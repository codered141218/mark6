<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Stock ledger, unit conversions, moving-average costing and lot (batch / expiry) bookkeeping.
 *
 * Key ideas:
 *  - Every item has a base unit (pc, tab, kg, m ...). Stock is always kept in the base unit.
 *  - Other units convert to the base unit through item_uoms (1 box = 100 tab, item specific)
 *    or uom_conversions (1 kg = 1000 g, global).
 *  - Every stock change is written to stock_movements and items.stock_qty is updated.
 *  - Items with track_batches = 1 also keep lots with expiry dates (item_batches); sales and
 *    write-offs take from the lot that expires first (see Batches).
 */
class Inventory
{
    public const STOCKED = ['product'];

    public static function item(int $id): array
    {
        $it = DB::one('SELECT * FROM items WHERE id = ?', [$id]);
        if (!$it) throw HttpException::bad("Item #$id not found");
        return $it;
    }

    public static function isStocked(array $item): bool
    {
        return in_array($item['item_type'], self::STOCKED, true);
    }

    /** How many base units are in 1 [$uomId] of this item. */
    public static function factorToBase(array $item, $uomId): float
    {
        if (!$uomId || (int) $uomId === (int) $item['base_uom_id']) return 1.0;
        $f = DB::value('SELECT factor FROM item_uoms WHERE item_id = ? AND uom_id = ?', [$item['id'], $uomId]);
        if ($f) return (float) $f;
        $f = DB::value('SELECT factor FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', [$uomId, $item['base_uom_id']]);
        if ($f) return (float) $f;
        $f = DB::value('SELECT factor FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', [$item['base_uom_id'], $uomId]);
        if ($f) return 1 / (float) $f;
        $u = DB::value('SELECT abbr FROM uoms WHERE id = ?', [$uomId]);
        $b = DB::value('SELECT abbr FROM uoms WHERE id = ?', [$item['base_uom_id']]);
        throw HttpException::bad("No conversion from {$u} to {$b} for \"{$item['name']}\". Add it under the item's units or Units & Conversions.");
    }

    public static function toBase(array $item, $qty, $uomId): float
    {
        return r4((float) $qty * self::factorToBase($item, $uomId));
    }

    /**
     * Units an item can be entered in: [['uom_id', 'abbr', 'factor', 'price', 'barcode'], ...] (factor = base units per 1).
     * The base unit comes first; price/barcode are set for pack units that are sold as such.
     */
    public static function units(int $itemId): array
    {
        $item = self::item($itemId);
        $out = [];
        $base = DB::one('SELECT id, abbr FROM uoms WHERE id = ?', [$item['base_uom_id']]);
        if ($base) $out[(int) $base['id']] = ['uom_id' => (int) $base['id'], 'abbr' => $base['abbr'], 'factor' => 1.0, 'price' => (float) $item['price'], 'barcode' => $item['barcode']];
        foreach (DB::all('SELECT iu.uom_id, u.abbr, iu.factor, iu.price, iu.barcode FROM item_uoms iu JOIN uoms u ON u.id = iu.uom_id WHERE iu.item_id = ? ORDER BY iu.factor', [$itemId]) as $r) {
            $out[(int) $r['uom_id']] ??= ['uom_id' => (int) $r['uom_id'], 'abbr' => $r['abbr'], 'factor' => (float) $r['factor'],
                'price' => $r['price'] === null ? null : (float) $r['price'], 'barcode' => $r['barcode']];
        }
        foreach (DB::all('SELECT c.from_uom_id AS uom_id, u.abbr, c.factor FROM uom_conversions c JOIN uoms u ON u.id = c.from_uom_id WHERE c.to_uom_id = ?', [$item['base_uom_id']]) as $r) {
            $out[(int) $r['uom_id']] ??= ['uom_id' => (int) $r['uom_id'], 'abbr' => $r['abbr'], 'factor' => (float) $r['factor'], 'price' => null, 'barcode' => null];
        }
        foreach (DB::all('SELECT c.to_uom_id AS uom_id, u.abbr, c.factor FROM uom_conversions c JOIN uoms u ON u.id = c.to_uom_id WHERE c.from_uom_id = ?', [$item['base_uom_id']]) as $r) {
            $out[(int) $r['uom_id']] ??= ['uom_id' => (int) $r['uom_id'], 'abbr' => $r['abbr'], 'factor' => 1 / (float) $r['factor'], 'price' => null, 'barcode' => null];
        }
        return array_values($out);
    }

    /** Cost of one base unit (moving average; services cost nothing). */
    public static function unitCost(int $itemId): float
    {
        $item = self::item($itemId);
        return self::isStocked($item) ? (float) $item['avg_cost'] : 0.0;
    }

    /**
     * Record a stock movement for a stocked item (services are ignored).
     * $qty is signed and in base units. Inbound movements with a unit cost update the moving average.
     * Lots: inbound RECEIVE movements may carry 'batch' => ['batch_no', 'expiry_date'] (items that track batches);
     * outbound movements take from 'batch_id' when given, otherwise first-expiry-first-out.
     * Returns the signed cost value of the movement.
     *
     * $m = ['item_id', 'qty', 'mtype', 'unit_cost' (optional), 'ref_type', 'ref_id', 'ref_no', 'bdate', 'notes', 'batch', 'batch_id']
     */
    public static function move(array $m): float
    {
        $item = DB::one('SELECT * FROM items WHERE id = ? FOR UPDATE', [$m['item_id']]);
        if (!$item) throw HttpException::bad('Item not found');
        if (!self::isStocked($item)) return 0.0;
        $qty = r4($m['qty']);
        if ($qty == 0) return 0.0;
        $onHand = (float) $item['stock_qty'];
        $avg = (float) $item['avg_cost'];
        $hasCost = $qty > 0 && isset($m['unit_cost']);
        $cost = $hasCost ? (float) $m['unit_cost'] : $avg;
        $newAvg = $avg;
        if ($hasCost) {
            $base = max($onHand, 0);
            $newAvg = $base + $qty > 0 ? ($base * $avg + $qty * $cost) / ($base + $qty) : $cost;
        }
        $balance = r4($onHand + $qty);
        $total = r2($qty * $cost);
        $ref = ['ref_type' => $m['ref_type'] ?? null, 'ref_id' => $m['ref_id'] ?? null, 'mtype' => $m['mtype']];
        DB::insert('stock_movements', [
            'item_id' => $item['id'], 'ts' => now(), 'bdate' => $m['bdate'] ?? today(), 'mtype' => $m['mtype'],
            'qty' => $qty, 'unit_cost' => r4($cost), 'total_cost' => $total, 'balance_after' => $balance,
            'ref_type' => $ref['ref_type'], 'ref_id' => $ref['ref_id'], 'ref_no' => $m['ref_no'] ?? null,
            'notes' => isset($m['notes']) ? mb_substr((string) $m['notes'], 0, 255) : null, 'user_id' => Auth::id(),
        ]);
        $upd = ['stock_qty' => $balance, 'avg_cost' => r4($newAvg), 'updated_at' => now()];
        if ($hasCost) $upd['last_cost'] = r4($cost);
        DB::update('items', (int) $item['id'], $upd);

        if ((int) $item['track_batches'] === 1) {
            if ($qty > 0 && $m['mtype'] === 'RECEIVE') {
                $b = $m['batch'] ?? [];
                Batches::receive($item, $qty, r4($cost), $b['batch_no'] ?? null, $b['expiry_date'] ?? null, $ref + ['supplier_id' => $m['supplier_id'] ?? null]);
            } elseif ($qty > 0) {
                Batches::putBack($item, $qty, $ref, $m['from_ref'] ?? null);
            } else {
                Batches::deduct($item, -$qty, $ref, isset($m['batch_id']) ? (int) $m['batch_id'] : null);
            }
        }
        return $total;
    }

    /**
     * Deduct (sign -1) the stock of sold lines [['item_id'=>, 'qty'=> base qty], ...] (several lines of one item are added up).
     * Returns the total cost (positive) = cost of goods sold.
     */
    public static function consume(array $lines, string $mtype, array $ref, int $sign = -1): float
    {
        $need = [];
        foreach ($lines as $l) {
            $id = (int) $l['item_id'];
            $need[$id] = r4(($need[$id] ?? 0) + (float) $l['qty']);
        }
        $total = 0.0;
        foreach ($need as $itemId => $q) {
            $total += abs(self::move(['item_id' => $itemId, 'qty' => $sign * $q, 'mtype' => $mtype] + $ref));
        }
        return r2($total);
    }

    /**
     * Undo every movement made by a document (used when voiding) at the original cost.
     * Lots get their quantities back. Returns the (signed) total value restored.
     */
    public static function reverseMovements(string $refType, int $refId, ?string $bdate = null, ?string $notes = null): float
    {
        $total = 0.0;
        foreach (DB::all('SELECT * FROM stock_movements WHERE ref_type = ? AND ref_id = ? ORDER BY id', [$refType, $refId]) as $m) {
            if (str_ends_with($m['mtype'], '_VOID')) continue;
            $item = DB::one('SELECT * FROM items WHERE id = ? FOR UPDATE', [$m['item_id']]);
            $qty = -(float) $m['qty'];
            $onHand = (float) $item['stock_qty'];
            $balance = r4($onHand + $qty);
            DB::insert('stock_movements', [
                'item_id' => $m['item_id'], 'ts' => now(), 'bdate' => $bdate ?? today(), 'mtype' => $m['mtype'] . '_VOID',
                'qty' => $qty, 'unit_cost' => $m['unit_cost'], 'total_cost' => r2($qty * (float) $m['unit_cost']), 'balance_after' => $balance,
                'ref_type' => $refType, 'ref_id' => $refId, 'ref_no' => $m['ref_no'], 'notes' => $notes, 'user_id' => Auth::id(),
            ]);
            $upd = ['stock_qty' => $balance, 'updated_at' => now()];
            if ((float) $m['qty'] > 0 && $balance > 0) {
                // Backing out a receipt: remove its value from the moving average.
                $value = max($onHand, 0) * (float) $item['avg_cost'] - (float) $m['qty'] * (float) $m['unit_cost'];
                $upd['avg_cost'] = r4(max($value / $balance, 0));
            }
            DB::update('items', (int) $m['item_id'], $upd);
            $total += $qty * (float) $m['unit_cost'];
        }
        Batches::restore($refType, $refId);
        return r2($total);
    }
}
