<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Services\Batches;
use App\Services\Ledger;
use App\Services\Settings;

/** Everything the dashboard shows for a date range: KPIs, chart series and the financial position as of $to. */
class Dashboard
{
    public static function data(string $from, string $to): array
    {
        $s = DB::one(
            "SELECT COUNT(*) AS receipts, COALESCE(SUM(total),0) AS net, COALESCE(SUM(subtotal),0) AS gross, COALESCE(SUM(discount_amount),0) AS discounts,
                    COALESCE(SUM(vat_amount),0) AS vat, COALESCE(SUM(cogs),0) AS cogs
             FROM tickets WHERE status = 'paid' AND business_date BETWEEN ? AND ?", [$from, $to]
        );
        $s = array_map('floatval', $s);
        $items = (float) DB::value("SELECT COALESCE(SUM(i.qty * i.factor),0) FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?", [$from, $to]);
        $netOfVat = r2($s['net'] - $s['vat']);
        $voids = DB::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS amt FROM tickets WHERE status = 'void' AND receipt_no IS NOT NULL AND business_date BETWEEN ? AND ?", [$from, $to]);
        $returns = DB::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(refund_amount),0) AS amt, COALESCE(SUM(refund_amount - vat_amount),0) AS net FROM sales_returns WHERE status = 'posted' AND business_date BETWEEN ? AND ?", [$from, $to]);
        $wastage = (float) DB::value("SELECT COALESCE(SUM(total_cost),0) FROM inv_docs WHERE doc_type = 'WASTE' AND status = 'posted' AND doc_date BETWEEN ? AND ?", [$from, $to]);
        $purchases = (float) DB::value("SELECT COALESCE(SUM(total_cost),0) FROM inv_docs WHERE doc_type = 'RECEIVE' AND status = 'posted' AND payment_mode <> 'opening' AND doc_date BETWEEN ? AND ?", [$from, $to]);
        $pettyCash = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM petty_cash_txns WHERE txn_type = 'expense' AND status = 'posted' AND txn_date BETWEEN ? AND ?", [$from, $to]);
        // Operating expenses and the period result straight from the ledger (voids are offset by their reversal)
        $expenses = (float) DB::value(
            "SELECT COALESCE(SUM(l.debit - l.credit),0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
             WHERE a.type = 'expense' AND COALESCE(a.subtype,'') <> 'cogs' AND e.entry_date BETWEEN ? AND ?", [$from, $to]
        );
        $result = DB::one(
            "SELECT COALESCE(SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit END),0) AS revenue,
                    COALESCE(SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit END),0) AS expenses
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id WHERE e.entry_date BETWEEN ? AND ?", [$from, $to]
        );

        $kpi = [
            'net_sales' => $s['net'], 'net_of_vat' => $netOfVat, 'gross_sales' => $s['gross'], 'discounts' => $s['discounts'],
            'vat' => $s['vat'], 'receipts' => (int) $s['receipts'], 'items_sold' => r4($items),
            'avg_ticket' => $s['receipts'] ? r2($s['net'] / $s['receipts']) : 0.0,
            'cogs' => $s['cogs'], 'gross_profit' => r2($netOfVat - $s['cogs']),
            'margin_pct' => $netOfVat ? r2(($netOfVat - $s['cogs']) / $netOfVat * 100) : 0.0,
            'voids' => (int) $voids['cnt'], 'void_amount' => r2($voids['amt']),
            'returns' => (int) $returns['cnt'], 'return_amount' => r2($returns['amt']),
            'wastage' => r2($wastage), 'purchases' => r2($purchases),
            'petty_cash' => r2($pettyCash), 'operating_expenses' => r2($expenses),
            'revenue' => r2($result['revenue']),
            'net_income_est' => r2($result['revenue'] - $result['expenses']),
        ];

        $daily = DB::all(
            "SELECT business_date AS date, COUNT(*) AS receipts, SUM(total) AS net, SUM(cogs) AS cogs FROM tickets
             WHERE status = 'paid' AND business_date BETWEEN ? AND ? GROUP BY business_date ORDER BY business_date", [$from, $to]
        );
        $hourly = DB::all(
            "SELECT CAST(EXTRACT(HOUR FROM paid_at) AS INTEGER) AS hour, COUNT(*) AS receipts, SUM(total) AS net FROM tickets
             WHERE status = 'paid' AND business_date BETWEEN ? AND ? GROUP BY EXTRACT(HOUR FROM paid_at) ORDER BY hour", [$from, $to]
        );
        $topItems = DB::all(
            "SELECT i.item_id, i.name, SUM(i.qty * i.factor) AS qty, SUM(i.line_total) AS amount FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?
             GROUP BY i.item_id, i.name ORDER BY amount DESC LIMIT 10", [$from, $to]
        );
        $categories = DB::all(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(i.qty * i.factor) AS qty, SUM(i.line_total) AS amount
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ? GROUP BY c.name ORDER BY amount DESC", [$from, $to]
        );
        $lowStock = DB::all(
            "SELECT i.id, i.name, i.stock_qty, i.reorder_point, i.reorder_qty, u.abbr AS uom,
                    GREATEST(i.reorder_qty, i.reorder_point - i.stock_qty) AS order_qty
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type = 'product' AND i.reorder_point > 0 AND i.stock_qty <= i.reorder_point
             ORDER BY i.stock_qty / i.reorder_point, i.name LIMIT 15"
        );
        $warn = Settings::pos()['expiryWarnDays'];

        return [
            'from' => $from, 'to' => $to, 'kpi' => $kpi,
            'daily' => $daily, 'hourly' => $hourly, 'top_items' => $topItems, 'categories' => $categories,
            'payments' => SalesReports::payments($from, $to), 'low_stock' => $lowStock,
            'expiry' => Batches::alerts($warn) + ['days' => $warn], 'expiring' => array_slice(Batches::expiring($warn), 0, 10),
            'position' => self::position($to),
            'session' => DB::one("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"),
            'open_tickets' => DB::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS amt FROM tickets WHERE status = 'open'"),
        ];
    }

    /** Cash, banks, inventory, receivables, payables and employee advances (balances as of $asOf). */
    public static function position(string $asOf): array
    {
        $in7days = add_days(today(), 7);
        $apDue = DB::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(amount - paid_amount),0) AS amt FROM ap_bills WHERE status IN ('open','partial') AND due_date <= ?", [$in7days]);
        $pendingCa = DB::one("SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS amt FROM cash_advances WHERE status = 'pending'");
        $banks = DB::all('SELECT id, bank_name, account_no, gl_account_id FROM bank_accounts WHERE active = 1 ORDER BY bank_name');
        foreach ($banks as &$b) $b['balance'] = Ledger::balance((int) $b['gl_account_id'], $asOf);
        return [
            'cash_on_hand' => Ledger::balance(Ledger::account('cash_on_hand'), $asOf),
            'petty_cash' => Ledger::balance(Ledger::account('petty_cash'), $asOf),
            'banks' => $banks,
            'inventory_value' => r2(DB::value("SELECT COALESCE(SUM(CASE WHEN stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END),0) FROM items WHERE item_type = 'product'")),
            'inventory_retail' => r2(DB::value("SELECT COALESCE(SUM(CASE WHEN stock_qty > 0 THEN stock_qty * price ELSE 0 END),0) FROM items WHERE item_type = 'product'")),
            'ap_total' => r2(DB::value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ap_bills WHERE status IN ('open','partial')")),
            'ap_due_7d' => r2($apDue['amt']), 'ap_due_count' => (int) $apDue['cnt'],
            'ar_total' => r2(DB::value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices WHERE status IN ('open','partial')")),
            'ar_overdue' => r2(DB::value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices WHERE status IN ('open','partial') AND due_date < ?", [today()])),
            'ca_pending' => (int) $pendingCa['cnt'], 'ca_pending_amount' => r2($pendingCa['amt']),
            'ca_outstanding' => r2(DB::value("SELECT COALESCE(SUM(balance),0) FROM cash_advances WHERE status = 'approved'")),
        ];
    }
}
