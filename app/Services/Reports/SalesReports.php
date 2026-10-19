<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Sales report queries. Every method takes the business-date range and returns plain rows.
 * Only paid tickets count as sales; voided receipts and returns are reported separately.
 */
class SalesReports
{
    public const METHOD_LABELS = [
        'cash' => 'Cash', 'card' => 'Card', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank Transfer', 'charge' => 'Charge (A/R)',
    ];

    public const DISCOUNT_LABELS = ['sc' => 'Senior Citizen', 'pwd' => 'PWD', 'percent' => 'Promo %', 'amount' => 'Fixed amount'];

    public static function methodLabel(string $method): string
    {
        return self::METHOD_LABELS[$method] ?? $method;
    }

    public static function daily(string $from, string $to): array
    {
        return DB::all(
            "SELECT t.business_date AS date, COUNT(*) AS receipts,
                    (SELECT COALESCE(SUM(i.qty * i.factor),0) FROM ticket_items i JOIN tickets x ON x.id = i.ticket_id WHERE x.business_date = t.business_date AND x.status = 'paid' AND i.status = 'active') AS items,
                    SUM(t.subtotal) AS gross, SUM(t.discount_amount) AS discounts, SUM(t.vatable_sales) AS vatable_sales, SUM(t.vat_exempt_sales) AS vat_exempt,
                    SUM(t.vat_amount) AS vat, SUM(t.total) AS net_sales,
                    (SELECT COALESCE(SUM(r.refund_amount),0) FROM sales_returns r WHERE r.business_date = t.business_date AND r.status = 'posted') AS refunds,
                    SUM(t.cogs) AS cogs, SUM(t.total) - SUM(t.vat_amount) - SUM(t.cogs) AS gross_profit
             FROM tickets t WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ?
             GROUP BY t.business_date ORDER BY t.business_date", [$from, $to]
        );
    }

    /** Quantity (in base units) and sales per item, with the cost recorded at the time of sale. */
    public static function items(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT i.item_id, it.sku, it.name AS item, it.generic_name, COALESCE(c.name, 'Uncategorized') AS category, u.abbr AS uom,
                    SUM(i.qty * i.factor) AS qty, SUM(i.line_total) AS gross, SUM(i.discount_amount) AS discounts,
                    SUM(i.qty * i.factor * i.unit_cost) AS total_cost, SUM(i.returned_qty * i.factor) AS returned
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id
             LEFT JOIN categories c ON c.id = it.category_id LEFT JOIN uoms u ON u.id = it.base_uom_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?
             GROUP BY i.item_id, it.sku, it.name, it.generic_name, c.name, u.abbr ORDER BY gross DESC", [$from, $to]
        );
        $total = array_sum(array_column($rows, 'gross')) ?: 1;
        foreach ($rows as &$r) {
            $r['avg_price'] = (float) $r['qty'] ? r2($r['gross'] / $r['qty']) : 0;
            $r['margin'] = r2((float) $r['gross'] - (float) $r['discounts'] - (float) $r['total_cost']);
            $r['share_pct'] = r2($r['gross'] / $total * 100);
        }
        return $rows;
    }

    public static function categories(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT COALESCE(c.name, 'Uncategorized') AS category, COUNT(DISTINCT t.id) AS receipts, SUM(i.qty * i.factor) AS qty, SUM(i.line_total) AS gross,
                    SUM(i.qty * i.factor * i.unit_cost) AS total_cost
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id
             LEFT JOIN categories c ON c.id = it.category_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?
             GROUP BY c.name ORDER BY gross DESC", [$from, $to]
        );
        $total = array_sum(array_column($rows, 'gross')) ?: 1;
        foreach ($rows as &$r) {
            $r['share_pct'] = r2($r['gross'] / $total * 100);
            $r['margin_pct'] = (float) $r['gross'] ? r2(((float) $r['gross'] - (float) $r['total_cost']) / (float) $r['gross'] * 100) : null;
        }
        return $rows;
    }

    /** Every issued receipt (paid and voided), optionally only one status. */
    public static function receipts(string $from, string $to, ?string $status = null): array
    {
        $params = [$from, $to];
        $where = '';
        if (in_array($status, ['paid', 'void'], true)) { $where = 'AND t.status = ?'; $params[] = $status; }
        $rows = DB::all(
            "SELECT t.id, t.business_date AS date, t.receipt_no, t.ticket_no, t.status, t.customer_name,
                    t.subtotal AS gross, t.discount_type, t.discount_amount AS discount, t.vat_amount AS vat, t.vat_exempt_sales AS vat_exempt, t.total, t.refunded_total,
                    t.paid_at, u.full_name AS cashier, t.void_reason, t.rx_note,
                    (SELECT STRING_AGG(p.method, ', ' ORDER BY p.id) FROM payments p WHERE p.ticket_id = t.id) AS payment
             FROM tickets t LEFT JOIN users u ON u.id = t.paid_by
             WHERE t.receipt_no IS NOT NULL AND t.business_date BETWEEN ? AND ? $where ORDER BY t.receipt_no", $params
        );
        foreach ($rows as &$r) {
            $r['discount_type'] = $r['discount_type'] === 'none' ? '' : (self::DISCOUNT_LABELS[$r['discount_type']] ?? $r['discount_type']);
            $r['payment'] = implode(', ', array_map([self::class, 'methodLabel'], array_filter(explode(', ', (string) $r['payment']))));
        }
        return $rows;
    }

    public static function payments(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT p.method, COUNT(*) AS transactions, SUM(p.amount) AS amount FROM payments p JOIN tickets t ON t.id = p.ticket_id
             WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ? GROUP BY p.method ORDER BY amount DESC", [$from, $to]
        );
        foreach ($rows as &$r) $r['label'] = self::methodLabel($r['method']);
        return $rows;
    }

    public static function hourly(string $from, string $to): array
    {
        return DB::all(
            "SELECT CAST(EXTRACT(HOUR FROM paid_at) AS INTEGER) AS h,
                    LPAD(CAST(CAST(EXTRACT(HOUR FROM paid_at) AS INTEGER) AS TEXT), 2, '0') || ':00 - ' || LPAD(CAST(CAST(EXTRACT(HOUR FROM paid_at) AS INTEGER) AS TEXT), 2, '0') || ':59' AS hour,
                    COUNT(*) AS receipts, SUM(total) AS net_sales, AVG(total) AS avg_ticket
             FROM tickets WHERE status = 'paid' AND business_date BETWEEN ? AND ?
             GROUP BY EXTRACT(HOUR FROM paid_at) ORDER BY h", [$from, $to]
        );
    }

    public static function cashiers(string $from, string $to): array
    {
        return DB::all(
            "SELECT u.full_name AS cashier, COUNT(*) AS receipts, SUM(t.total) AS net_sales, SUM(t.discount_amount) AS discounts,
                    (SELECT COUNT(*) FROM tickets v WHERE v.voided_by = u.id AND v.status = 'void' AND v.receipt_no IS NOT NULL
                       AND v.business_date BETWEEN ? AND ?) AS voids_authorized,
                    (SELECT COALESCE(SUM(r.refund_amount),0) FROM sales_returns r WHERE r.created_by = u.id AND r.status = 'posted' AND r.business_date BETWEEN ? AND ?) AS refunds
             FROM tickets t JOIN users u ON u.id = t.paid_by
             WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ? GROUP BY u.id, u.full_name ORDER BY net_sales DESC",
            [$from, $to, $from, $to, $from, $to]
        );
    }

    /** Voided receipts and cancelled held sales, in the order they were voided. */
    public static function voids(string $from, string $to): array
    {
        return DB::all(
            "SELECT t.business_date AS date, CASE WHEN t.receipt_no IS NULL THEN 'Cancelled sale' ELSE 'Voided receipt' END AS kind,
                    COALESCE(t.receipt_no, t.ticket_no) AS ref, t.customer_name, t.total AS amount, t.void_reason AS reason, t.voided_at, u.full_name AS authorized_by
             FROM tickets t LEFT JOIN users u ON u.id = t.voided_by
             WHERE t.status = 'void' AND t.business_date BETWEEN ? AND ? ORDER BY t.voided_at", [$from, $to]
        );
    }

    /** Customer returns and refunds, one row per returned line. */
    public static function returns(string $from, string $to): array
    {
        return DB::all(
            "SELECT r.business_date AS date, r.return_no, t.receipt_no, l.name AS item, l.qty, l.price, l.amount, l.vat_amount AS vat, l.cogs,
                    r.refund_method, CASE WHEN r.restock = 1 THEN 'Restocked' ELSE 'Written off' END AS disposition, r.reason, r.status, u.full_name AS processed_by
             FROM sales_return_lines l JOIN sales_returns r ON r.id = l.return_id JOIN tickets t ON t.id = r.ticket_id LEFT JOIN users u ON u.id = r.created_by
             WHERE r.business_date BETWEEN ? AND ? ORDER BY r.id, l.id", [$from, $to]
        );
    }

    /**
     * SC/PWD sales book (BIR requirement) and other discounts, with the names and ID numbers captured at the POS.
     * One row per receipt that has any discount (whole receipt or single items); "Discount(s)" lists what was given.
     */
    public static function discounts(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT t.id, t.business_date AS date, t.receipt_no, t.discount_type, t.discount_name, t.discount_rate, t.sc_count, t.sc_details,
                    t.subtotal AS gross, t.vat_exempt_sales, t.sc_discount, t.promo_discount, t.discount_amount AS discount, t.total AS net
             FROM tickets t
             WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ?
               AND (t.discount_type <> 'none' OR t.discount_amount > 0)
             ORDER BY t.receipt_no", [$from, $to]
        );
        $itemDiscounts = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach (DB::all(
                "SELECT ticket_id, name, discount_name, discount_kind FROM ticket_items
                 WHERE status = 'active' AND discount_kind IS NOT NULL AND ticket_id IN (" . DB::placeholders($ids) . ')', $ids
            ) as $i) {
                $itemDiscounts[$i['ticket_id']][] = ($i['discount_name'] ?: (self::DISCOUNT_LABELS[$i['discount_kind']] ?? $i['discount_kind'])) . ' (' . $i['name'] . ')';
            }
        }
        foreach ($rows as &$r) {
            $people = json_decode((string) $r['sc_details'], true);
            $people = is_array($people) ? $people : [];
            $r['names'] = implode('; ', array_filter(array_column($people, 'name')));
            $r['id_numbers'] = implode('; ', array_filter(array_column($people, 'id_no')));
            $given = $itemDiscounts[$r['id']] ?? [];
            if ($r['discount_type'] !== 'none') {
                $label = $r['discount_name'] ?: ($r['discount_type'] === 'percent' ? (float) $r['discount_rate'] . '%' : (self::DISCOUNT_LABELS[$r['discount_type']] ?? $r['discount_type']));
                array_unshift($given, $label . ' (whole receipt)');
            }
            $r['discount_type'] = implode('; ', $given);
            unset($r['sc_details'], $r['id'], $r['discount_name'], $r['discount_rate']);
        }
        return $rows;
    }

    /** End-of-day history: one row per business day (cash session). */
    public static function eod(string $from, string $to): array
    {
        return DB::all(
            "SELECT s.id, s.business_date, s.status, s.opened_at, ou.full_name AS opened_by, s.closed_at, cu.full_name AS closed_by, s.opening_cash,
                    (SELECT COUNT(*) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') AS receipts,
                    (SELECT COALESCE(SUM(total), 0) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') AS net_sales,
                    (SELECT COALESCE(SUM(refund_amount), 0) FROM sales_returns r WHERE r.cash_session_id = s.id AND r.status = 'posted') AS refunds,
                    s.expected_cash, s.counted_cash, s.variance
             FROM cash_sessions s LEFT JOIN users ou ON ou.id = s.opened_by LEFT JOIN users cu ON cu.id = s.closed_by
             WHERE s.business_date BETWEEN ? AND ? ORDER BY s.business_date, s.id", [$from, $to]
        );
    }

    /** X/Z reading of one business day (live computation). */
    public static function reading(int $sessionId): array
    {
        if (!DB::one('SELECT id FROM cash_sessions WHERE id = ?', [$sessionId])) throw HttpException::notFound('Business day');
        return \App\Services\Pos\CashSessions::report($sessionId);
    }
}
