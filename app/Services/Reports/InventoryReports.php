<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Services\Batches;
use App\Services\Items;
use App\Services\Settings;

/**
 * Inventory report queries. Quantities are in each item's base unit.
 * Movement types: RECEIVE, SALE, RETURN, ISSUE, WASTE, COUNT and their *_VOID reversals.
 */
class InventoryReports
{
    public const MOVE_LABELS = ['RECEIVE' => 'Stock in', 'SALE' => 'Sale', 'RETURN' => 'Customer return', 'ISSUE' => 'Issuance', 'WASTE' => 'Write-off', 'COUNT' => 'Count adj.', 'BEGINNING' => 'Beginning'];

    public const ITEM_TYPES = ['product' => 'Product', 'service' => 'Service'];

    /** "Sale (void)" style label for a movement type. */
    public static function moveLabel(string $type): string
    {
        $base = str_replace('_VOID', '', $type);
        return (self::MOVE_LABELS[$base] ?? $base) . (str_ends_with($type, '_VOID') ? ' (void)' : '');
    }

    public static function onHand(?int $categoryId = null): array
    {
        $params = [];
        $where = '';
        if ($categoryId) { $where = 'AND i.category_id = ?'; $params[] = $categoryId; }
        return DB::all(
            "SELECT i.id, i.sku, i.name AS item, i.generic_name, COALESCE(c.name, '') AS category, u.abbr AS uom, i.stock_qty AS on_hand,
                    i.avg_cost, i.last_cost, ROUND(i.stock_qty * i.avg_cost, 2) AS value, i.price, ROUND(i.stock_qty * i.price, 2) AS retail_value, i.reorder_point, i.reorder_qty,
                    CASE WHEN i.stock_qty < 0 THEN 'NEGATIVE' WHEN i.reorder_point > 0 AND i.stock_qty <= i.reorder_point THEN 'REORDER' ELSE 'OK' END AS status
             FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type = 'product' $where ORDER BY c.name, i.name", $params
        );
    }

    /** Items at or below their reorder point, with a suggested order quantity. */
    public static function reorder(): array
    {
        return DB::all(
            "SELECT i.id, i.sku, i.name AS item, COALESCE(c.name, '') AS category, u.abbr AS uom, i.stock_qty AS on_hand, i.reorder_point, i.reorder_qty,
                    GREATEST(i.reorder_qty, i.reorder_point - i.stock_qty) AS suggested_order, i.last_cost,
                    ROUND(GREATEST(i.reorder_qty, i.reorder_point - i.stock_qty) * i.last_cost, 2) AS est_cost,
                    (SELECT s.name FROM item_batches b JOIN suppliers s ON s.id = b.supplier_id WHERE b.item_id = i.id ORDER BY b.id DESC LIMIT 1) AS last_supplier
             FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type = 'product' AND i.reorder_point > 0 AND i.stock_qty <= i.reorder_point
             ORDER BY c.name, i.name"
        );
    }

    /** Per item: beginning balance, movements in the period by type, ending balance. */
    public static function movement(string $from, string $to): array
    {
        $in = fn (string ...$types) => "COALESCE(SUM(CASE WHEN m.bdate >= ? AND m.mtype IN ('" . implode("','", $types) . "') THEN m.qty END), 0)";
        $rows = DB::all(
            "SELECT i.id, i.sku, i.name AS item, u.abbr AS uom, i.avg_cost,
                    COALESCE(SUM(CASE WHEN m.bdate < ? THEN m.qty END), 0) AS beginning,
                    {$in('RECEIVE', 'RECEIVE_VOID')} AS received,
                    -{$in('SALE', 'SALE_VOID')} AS sold,
                    {$in('RETURN', 'RETURN_VOID')} AS returned,
                    -{$in('ISSUE', 'ISSUE_VOID')} AS issued,
                    -{$in('WASTE', 'WASTE_VOID')} AS wasted,
                    {$in('COUNT')} AS count_adj,
                    COALESCE(SUM(m.qty), 0) AS ending
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
             LEFT JOIN stock_movements m ON m.item_id = i.id AND m.bdate <= ?
             WHERE i.item_type = 'product' AND i.active = 1
             GROUP BY i.id, i.sku, i.name, u.abbr, i.avg_cost ORDER BY i.name",
            [$from, $from, $from, $from, $from, $from, $from, $to]
        );
        foreach ($rows as &$r) $r['ending_value'] = r2($r['ending'] * $r['avg_cost']);
        return $rows;
    }

    /** Stock card: beginning balance, then every movement with a running balance. */
    public static function stockCard(int $itemId, string $from, string $to): array
    {
        $balance = (float) DB::value('SELECT COALESCE(SUM(qty), 0) FROM stock_movements WHERE item_id = ? AND bdate < ?', [$itemId, $from]);
        $moves = DB::all(
            'SELECT m.bdate AS date, m.ts, m.mtype AS type, m.ref_no, m.notes, m.qty, m.unit_cost, m.total_cost, u.full_name AS user_name
             FROM stock_movements m LEFT JOIN users u ON u.id = m.user_id
             WHERE m.item_id = ? AND m.bdate BETWEEN ? AND ? ORDER BY m.bdate, m.id', [$itemId, $from, $to]
        );
        $rows = [['date' => $from, 'type' => 'BEGINNING', 'ref_no' => '', 'qty_in' => null, 'qty_out' => null, 'balance' => r4($balance), '_bold' => true]];
        foreach ($moves as $m) {
            $q = (float) $m['qty'];
            $balance += $q;
            $rows[] = $m + ['qty_in' => $q > 0 ? $q : null, 'qty_out' => $q < 0 ? -$q : null, 'balance' => r4($balance)];
        }
        return $rows;
    }

    /** Line-level listing of posted RECEIVE / ISSUE / WASTE documents. */
    public static function documents(string $type, string $from, string $to): array
    {
        return DB::all(
            "SELECT d.id AS doc_id, d.doc_date AS date, d.doc_no, s.name AS supplier, d.invoice_no, d.payment_mode, d.issued_to, a.name AS expense_account,
                    COALESCE(l.reason, d.reason) AS reason, i.sku, i.name AS item, l.qty, u.abbr AS uom, l.base_qty, bu.abbr AS base_uom, l.unit_cost,
                    l.line_total AS amount, COALESCE(l.batch_no, bt.batch_no) AS batch_no, COALESCE(l.expiry_date, bt.expiry_date) AS expiry_date, d.status, cu.full_name AS prepared_by
             FROM inv_doc_lines l JOIN inv_docs d ON d.id = l.doc_id JOIN items i ON i.id = l.item_id
             LEFT JOIN uoms u ON u.id = l.uom_id LEFT JOIN uoms bu ON bu.id = i.base_uom_id LEFT JOIN item_batches bt ON bt.id = l.batch_id
             LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN accounts a ON a.id = d.expense_account_id
             LEFT JOIN users cu ON cu.id = d.created_by
             WHERE d.doc_type = ? AND d.status = 'posted' AND d.doc_date BETWEEN ? AND ? ORDER BY d.doc_date, d.doc_no, l.id",
            [$type, $from, $to]
        );
    }

    public static function counts(string $from, string $to): array
    {
        return DB::all(
            "SELECT s.count_date AS date, s.doc_no, i.sku, i.name AS item, u.abbr AS uom, l.system_qty, l.counted_qty, l.variance, l.unit_cost, l.variance_value
             FROM count_lines l JOIN count_sessions s ON s.id = l.session_id JOIN items i ON i.id = l.item_id
             LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE s.status = 'posted' AND l.counted_qty IS NOT NULL AND s.count_date BETWEEN ? AND ?
             ORDER BY s.count_date, s.doc_no, i.name", [$from, $to]
        );
    }

    /** Lots expired or expiring within the warning period (Settings → expiry_warn_days). */
    public static function expiry(?int $days = null): array
    {
        return Batches::expiring($days ?? Settings::pos()['expiryWarnDays']);
    }

    /** Every lot with stock on hand. */
    public static function batches(): array
    {
        return Batches::expiring(Settings::pos()['expiryWarnDays'], true);
    }

    /** Price list with cost, margin and margin % per sellable item (net of VAT). */
    public static function margins(): array
    {
        $rows = DB::all(
            "SELECT i.id, i.sku, i.name AS item, COALESCE(c.name, '') AS category, i.item_type AS type, u.abbr AS uom, i.price, i.wholesale_price, i.avg_cost AS cost, i.vat_exempt
             FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.sellable = 1 ORDER BY c.name, i.name"
        );
        foreach ($rows as &$r) {
            $cost = $r['type'] === 'product' ? (float) $r['cost'] : 0.0;
            $net = r2($r['price'] / Items::vatDivisor((bool) $r['vat_exempt']));
            $r['cost'] = $cost;
            $r['price_net_of_vat'] = $net;
            $r['margin'] = r2($net - $cost);
            $r['margin_pct'] = Items::marginPct($cost, (float) $r['price'], (bool) $r['vat_exempt']);
        }
        return $rows;
    }

    /** Items without sales in the period but with stock (slow movers), with days since the last sale. */
    public static function slowMovers(string $from, string $to): array
    {
        return DB::all(
            "SELECT i.sku, i.name AS item, COALESCE(c.name, '') AS category, u.abbr AS uom, i.stock_qty AS on_hand, ROUND(i.stock_qty * i.avg_cost, 2) AS value,
                    (SELECT MAX(m.bdate) FROM stock_movements m WHERE m.item_id = i.id AND m.mtype = 'SALE') AS last_sold
             FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type = 'product' AND i.stock_qty > 0
               AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.item_id = i.id AND m.mtype = 'SALE' AND m.bdate BETWEEN ? AND ?)
             ORDER BY value DESC", [$from, $to]
        );
    }
}
