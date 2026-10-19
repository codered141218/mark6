<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Lots / batches with expiry dates for items that have track_batches = 1 (medicines, perishables, paint ...).
 *
 * Every lot is a row in item_batches with the quantity still on hand. Stock movements of such items
 * also write batch_movements, so a void can put the exact lots back. Outbound movements take from the
 * lot that expires first (FEFO), unless a specific lot is chosen (e.g. writing off an expired lot).
 * Lots never block a sale: when the lots run out, the remainder is recorded without a lot
 * (batch_id NULL) so items.stock_qty and the lots stay reconcilable.
 */
class Batches
{
    /** Lots of an item, first expiry first (lots without an expiry date last). */
    public static function forItem(int $itemId, bool $withStock = true): array
    {
        return DB::all(
            'SELECT b.*, s.name AS supplier_name FROM item_batches b LEFT JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.item_id = ?' . ($withStock ? ' AND b.qty_on_hand > 0' : '') . ' ORDER BY b.expiry_date ASC NULLS LAST, b.id',
            [$itemId]
        );
    }

    /** [id => "LOT123 · exp Jan 2027 · 40 on hand"] for pickers. */
    public static function options(int $itemId): array
    {
        $out = [];
        foreach (self::forItem($itemId) as $b) {
            $out[(int) $b['id']] = ($b['batch_no'] ?: 'No lot no.') . ($b['expiry_date'] ? ' · exp ' . fmt_date($b['expiry_date']) : ' · no expiry') . ' · ' . qty($b['qty_on_hand']) . ' on hand';
        }
        return $out;
    }

    /** Goods received: add to the lot with the same lot number + expiry, or create it. Returns the lot id. */
    public static function receive(array $item, float $qty, float $unitCost, ?string $batchNo, ?string $expiry, array $ref): int
    {
        $batchNo = trim((string) $batchNo) ?: null;
        $expiry = is_date($expiry) ? $expiry : null;
        $existing = DB::one(
            'SELECT * FROM item_batches WHERE item_id = ? AND COALESCE(batch_no, \'\') = ? AND COALESCE(CAST(expiry_date AS TEXT), \'\') = ? ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [$item['id'], $batchNo ?? '', $expiry ?? '']
        );
        if ($existing) {
            $id = (int) $existing['id'];
            DB::update('item_batches', $id, ['qty_in' => r4($existing['qty_in'] + $qty), 'qty_on_hand' => r4($existing['qty_on_hand'] + $qty), 'unit_cost' => $unitCost]);
        } else {
            $id = DB::insert('item_batches', [
                'item_id' => (int) $item['id'], 'batch_no' => $batchNo, 'expiry_date' => $expiry, 'qty_in' => $qty, 'qty_on_hand' => $qty,
                'unit_cost' => $unitCost, 'supplier_id' => $ref['supplier_id'] ?? null, 'doc_id' => $ref['ref_type'] === 'inv_doc' ? ($ref['ref_id'] ?? null) : null,
                'received_at' => now(),
            ]);
        }
        self::movement($id, $item, $qty, $ref);
        return $id;
    }

    /** Take $qty out of the lots: the chosen lot, or first expiry first. */
    public static function deduct(array $item, float $qty, array $ref, ?int $batchId = null): void
    {
        $left = r4($qty);
        if ($batchId) {
            $b = DB::one('SELECT * FROM item_batches WHERE id = ? AND item_id = ? FOR UPDATE', [$batchId, $item['id']]);
            if (!$b) throw HttpException::bad("Lot not found for \"{$item['name']}\"");
            $take = min($left, max((float) $b['qty_on_hand'], 0));
            if ($take > 0) {
                DB::update('item_batches', (int) $b['id'], ['qty_on_hand' => r4($b['qty_on_hand'] - $take)]);
                self::movement((int) $b['id'], $item, -$take, $ref);
                $left = r4($left - $take);
            }
        }
        if ($left > 0) {
            foreach (DB::all('SELECT * FROM item_batches WHERE item_id = ? AND qty_on_hand > 0 ORDER BY expiry_date ASC NULLS LAST, id FOR UPDATE', [$item['id']]) as $b) {
                if ($left <= 0) break;
                $take = min($left, (float) $b['qty_on_hand']);
                DB::update('item_batches', (int) $b['id'], ['qty_on_hand' => r4($b['qty_on_hand'] - $take)]);
                self::movement((int) $b['id'], $item, -$take, $ref);
                $left = r4($left - $take);
            }
        }
        if ($left > 0) self::movement(null, $item, -$left, $ref);   // more sold than the lots hold: keep the books reconcilable
    }

    /**
     * Stock coming back (customer return, count overage). With $fromRef (the original sale) the goods go back
     * into the lots that sale took from; otherwise into the newest lot, or a new lot without expiry.
     */
    public static function putBack(array $item, float $qty, array $ref, ?array $fromRef = null): void
    {
        $left = r4($qty);
        if ($fromRef) {
            $taken = DB::all(
                'SELECT batch_id, -SUM(qty) AS q FROM batch_movements WHERE item_id = ? AND ref_type = ? AND ref_id = ? AND batch_id IS NOT NULL AND qty < 0
                 GROUP BY batch_id ORDER BY MIN(id)', [$item['id'], $fromRef['ref_type'], $fromRef['ref_id']]
            );
            foreach ($taken as $t) {
                if ($left <= 0) break;
                $back = min($left, (float) $t['q']);
                DB::run('UPDATE item_batches SET qty_on_hand = qty_on_hand + ? WHERE id = ?', [$back, (int) $t['batch_id']]);
                self::movement((int) $t['batch_id'], $item, $back, $ref);
                $left = r4($left - $back);
            }
        }
        if ($left > 0) {
            $b = DB::one('SELECT * FROM item_batches WHERE item_id = ? ORDER BY expiry_date DESC NULLS LAST, id DESC LIMIT 1 FOR UPDATE', [$item['id']]);
            if ($b) {
                DB::update('item_batches', (int) $b['id'], ['qty_on_hand' => r4($b['qty_on_hand'] + $left)]);
                $id = (int) $b['id'];
            } else {
                $id = DB::insert('item_batches', ['item_id' => (int) $item['id'], 'batch_no' => null, 'expiry_date' => null, 'qty_in' => $left, 'qty_on_hand' => $left,
                    'unit_cost' => (float) $item['avg_cost'], 'received_at' => now(), 'notes' => 'Added by ' . strtolower($ref['mtype'] ?? 'adjustment')]);
            }
            self::movement($id, $item, $left, $ref);
        }
    }

    /** Undo the lot movements of a document (void): each lot gets back what the document took, or loses what it added. */
    public static function restore(string $refType, int $refId): void
    {
        $rows = DB::all(
            'SELECT batch_id, SUM(qty) AS q, MIN(mtype) AS mtype FROM batch_movements WHERE ref_type = ? AND ref_id = ? AND mtype NOT LIKE \'%\\_VOID\' GROUP BY batch_id',
            [$refType, $refId]
        );
        $void = DB::all('SELECT batch_id, SUM(qty) AS q FROM batch_movements WHERE ref_type = ? AND ref_id = ? AND mtype LIKE \'%\\_VOID\' GROUP BY batch_id', [$refType, $refId]);
        $already = [];
        foreach ($void as $v) $already[$v['batch_id'] ?? 0] = (float) $v['q'];
        foreach ($rows as $r) {
            $key = $r['batch_id'] ?? 0;
            $q = r4(-(float) $r['q'] - ($already[$key] ?? 0));   // what is still to be undone
            if (abs($q) < 0.00005) continue;
            if ($r['batch_id']) DB::run('UPDATE item_batches SET qty_on_hand = qty_on_hand + ? WHERE id = ?', [$q, (int) $r['batch_id']]);
            $itemId = (int) DB::value('SELECT item_id FROM batch_movements WHERE ref_type = ? AND ref_id = ? LIMIT 1', [$refType, $refId]);
            DB::insert('batch_movements', ['batch_id' => $r['batch_id'] ? (int) $r['batch_id'] : null, 'item_id' => $itemId, 'qty' => $q,
                'mtype' => $r['mtype'] . '_VOID', 'ref_type' => $refType, 'ref_id' => $refId, 'ts' => now()]);
        }
    }

    /** When lot tracking is switched on for an item that already has stock, put that stock into one opening lot. */
    public static function openingLot(array $item): void
    {
        $stock = (float) $item['stock_qty'];
        if ($stock <= 0 || DB::value('SELECT id FROM item_batches WHERE item_id = ? LIMIT 1', [$item['id']])) return;
        $id = DB::insert('item_batches', ['item_id' => (int) $item['id'], 'batch_no' => 'OPENING', 'expiry_date' => null, 'qty_in' => $stock, 'qty_on_hand' => $stock,
            'unit_cost' => (float) $item['avg_cost'], 'received_at' => now(), 'notes' => 'Stock on hand when lot tracking was enabled']);
        self::movement($id, $item, $stock, ['ref_type' => 'item', 'ref_id' => (int) $item['id'], 'mtype' => 'OPENING']);
    }

    /**
     * Expiry report: lots with stock, expired or expiring within $days (0 = every lot with stock).
     * Rows: item, sku, batch_no, expiry_date, qty_on_hand, unit_cost, value, days_left, status (EXPIRED | EXPIRING | OK).
     */
    public static function expiring(int $days, bool $all = false): array
    {
        $rows = DB::all(
            "SELECT b.id, i.id AS item_id, i.sku, i.name AS item, COALESCE(c.name, '') AS category, u.abbr AS uom, b.batch_no, b.expiry_date,
                    b.qty_on_hand, b.unit_cost, ROUND(b.qty_on_hand * b.unit_cost, 2) AS value, s.name AS supplier,
                    CAST(b.expiry_date - CURRENT_DATE AS INTEGER) AS days_left
             FROM item_batches b JOIN items i ON i.id = b.item_id LEFT JOIN categories c ON c.id = i.category_id
             LEFT JOIN uoms u ON u.id = i.base_uom_id LEFT JOIN suppliers s ON s.id = b.supplier_id
             WHERE b.qty_on_hand > 0 AND i.active = 1" . ($all ? '' : ' AND b.expiry_date IS NOT NULL AND b.expiry_date <= CURRENT_DATE + ?') . '
             ORDER BY b.expiry_date ASC NULLS LAST, i.name',
            $all ? [] : [$days]
        );
        foreach ($rows as &$r) {
            $d = $r['days_left'];
            $r['status'] = $d === null ? 'OK' : ((int) $d < 0 ? 'EXPIRED' : ((int) $d <= $days ? 'EXPIRING' : 'OK'));
        }
        return $rows;
    }

    /** Totals for the dashboard: lots expired / expiring soon and their value. */
    public static function alerts(int $days): array
    {
        $r = DB::one(
            'SELECT COALESCE(SUM(CASE WHEN b.expiry_date < CURRENT_DATE THEN 1 ELSE 0 END), 0) AS expired_lots,
                    COALESCE(SUM(CASE WHEN b.expiry_date < CURRENT_DATE THEN b.qty_on_hand * b.unit_cost ELSE 0 END), 0) AS expired_value,
                    COALESCE(SUM(CASE WHEN b.expiry_date >= CURRENT_DATE AND b.expiry_date <= CURRENT_DATE + ? THEN 1 ELSE 0 END), 0) AS expiring_lots,
                    COALESCE(SUM(CASE WHEN b.expiry_date >= CURRENT_DATE AND b.expiry_date <= CURRENT_DATE + ? THEN b.qty_on_hand * b.unit_cost ELSE 0 END), 0) AS expiring_value
             FROM item_batches b JOIN items i ON i.id = b.item_id WHERE b.qty_on_hand > 0 AND i.active = 1 AND b.expiry_date IS NOT NULL', [$days, $days]
        );
        return ['expired_lots' => (int) $r['expired_lots'], 'expired_value' => r2($r['expired_value']), 'expiring_lots' => (int) $r['expiring_lots'], 'expiring_value' => r2($r['expiring_value'])];
    }

    private static function movement(?int $batchId, array $item, float $qty, array $ref): void
    {
        DB::insert('batch_movements', ['batch_id' => $batchId, 'item_id' => (int) $item['id'], 'qty' => r4($qty), 'mtype' => $ref['mtype'] ?? 'ADJ',
            'ref_type' => $ref['ref_type'] ?? null, 'ref_id' => $ref['ref_id'] ?? null, 'ts' => now()]);
    }
}
