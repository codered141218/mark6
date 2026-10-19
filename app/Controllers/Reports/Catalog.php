<?php
namespace App\Controllers\Reports;

use App\Services\Reports\FinanceReports;
use App\Services\Reports\InventoryReports;
use App\Services\Reports\SalesReports;

/**
 * The report catalog: what each report is called, which filters it takes, its columns and where its rows come from.
 *
 * Report keys:
 *   label    shown in the report picker
 *   columns  Table column specs (drive both the screen table and the Excel export)
 *   rows     fn (array $f) => rows; $f has from, to, item_id, account_id, bank_account_id, category_id, status
 *   params   'range' (default, from/to) | 'asof' (single date in `to`) | 'none'
 *   filters  extra filters: item | account | bank | category | status
 *   requires filter that must be chosen before the report can run (e.g. item_id for the stock card)
 *   link     fn ($row) => url, makes rows clickable
 *   view     'statement' (income statement / balance sheet) or 'petty' instead of a plain table
 *   perms    permissions needed besides the page permission (any of)
 */
class Catalog
{
    public static function group(string $group): array
    {
        return match ($group) {
            'sales' => ['title' => 'Sales Reports', 'reports' => self::sales()],
            'inventory' => ['title' => 'Inventory Reports', 'reports' => self::inventory()],
            'finance' => ['title' => 'Financial Reports', 'reports' => self::finance()],
        };
    }

    // ------------------------------------------------------------------ column helpers
    private static function money(string $key, string $label, bool $total = true): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'money', 'total' => $total];
    }

    private static function qty(string $key, string $label, bool $total = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'qty', 'total' => $total];
    }

    private static function int(string $key, string $label, bool $total = true): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'int', 'total' => $total];
    }

    private static function text(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label];
    }

    private static function date(string $key = 'date', string $label = 'Date'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'date', 'align' => 'nowrap'];
    }

    private static function status(string $key = 'status', string $label = 'Status'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'badge'];
    }

    private static function pct(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'percent'];
    }

    /** Text column whose value goes through a label map / function, on screen and in Excel. */
    private static function mapped(string $key, string $label, callable $fn): array
    {
        return ['key' => $key, 'label' => $label, 'value' => fn ($r) => $fn($r[$key] ?? null), 'html' => fn ($r) => e($fn($r[$key] ?? null))];
    }

    /** Money with red for negative / amber for positive (cash over/short). */
    private static function variance(string $key, string $label): array
    {
        return self::money($key, $label) + ['html' => function ($r) use ($key) {
            if (($r[$key] ?? null) === null) return '';
            $v = (float) $r[$key];
            return '<span class="' . ($v < 0 ? 'text-red bold' : ($v > 0 ? 'text-amber bold' : '')) . '">' . money($v) . '</span>';
        }];
    }

    /** Gross margin % colored: below 10% red, below 20% amber. */
    private static function marginPct(string $key, string $label): array
    {
        return self::pct($key, $label) + ['html' => function ($r) use ($key) {
            if (($r[$key] ?? null) === null) return '';
            $v = (float) $r[$key];
            return '<span class="' . ($v < 10 ? 'text-red bold' : ($v < 20 ? 'text-amber' : 'text-green')) . '">' . number_format($v, 1) . '%</span>';
        }];
    }

    /** Days left until expiry: red when expired, amber when close. */
    private static function daysLeft(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'int', 'total' => false, 'html' => function ($r) use ($key) {
            if (($r[$key] ?? null) === null) return '<span class="muted">—</span>';
            $v = (int) $r[$key];
            return '<span class="' . ($v < 0 ? 'text-red bold' : ($v <= 30 ? 'text-amber bold' : '')) . '">' . ($v < 0 ? 'expired ' . abs($v) . 'd ago' : $v . ' days') . '</span>';
        }];
    }

    // ------------------------------------------------------------------ sales
    private static function sales(): array
    {
        return [
            'daily' => [
                'label' => 'Sales summary by date',
                'rows' => fn ($f) => SalesReports::daily($f['from'], $f['to']),
                'columns' => [self::date(), self::int('receipts', 'Receipts'), self::qty('items', 'Items sold', true), self::money('gross', 'Gross sales'),
                    self::money('discounts', 'Discounts'), self::money('vatable_sales', 'VATable'), self::money('vat_exempt', 'VAT-exempt'),
                    self::money('vat', 'VAT'), self::money('net_sales', 'Net sales'), self::money('refunds', 'Refunds'),
                    self::money('cogs', 'COGS'), self::money('gross_profit', 'Gross profit')],
            ],
            'items' => [
                'label' => 'Sales by item',
                'rows' => fn ($f) => SalesReports::items($f['from'], $f['to']),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('generic_name', 'Generic / alt name'), self::text('category', 'Category'),
                    self::text('uom', 'Unit'), self::qty('qty', 'Qty sold', true), self::qty('returned', 'Returned', true), self::money('gross', 'Sales'),
                    self::money('discounts', 'Discounts'), self::money('avg_price', 'Avg price', false), self::money('total_cost', 'Cost'), self::money('margin', 'Margin'),
                    self::pct('share_pct', '% of sales')],
            ],
            'categories' => [
                'label' => 'Sales by category',
                'rows' => fn ($f) => SalesReports::categories($f['from'], $f['to']),
                'columns' => [self::text('category', 'Category'), self::int('receipts', 'Receipts', false), self::qty('qty', 'Qty sold', true),
                    self::money('gross', 'Sales'), self::money('total_cost', 'Cost'), self::marginPct('margin_pct', 'Margin %'), self::pct('share_pct', '% of sales')],
            ],
            'receipts' => [
                'label' => 'Sales by receipt',
                'filters' => ['status'],
                'rows' => fn ($f) => SalesReports::receipts($f['from'], $f['to'], $f['status']),
                'columns' => [self::date(), self::text('receipt_no', 'Receipt'), self::status(), self::text('customer_name', 'Customer'), self::money('gross', 'Gross'),
                    self::text('discount_type', 'Disc. type'), self::money('discount', 'Discount'), self::money('vat', 'VAT'), self::money('vat_exempt', 'VAT-exempt'),
                    self::money('total', 'Total'), self::money('refunded_total', 'Refunded'), self::text('payment', 'Payment'),
                    self::text('cashier', 'Cashier'), self::text('rx_note', 'Rx / notes'), self::text('void_reason', 'Void reason')],
            ],
            'payments' => [
                'label' => 'Sales by payment method',
                'rows' => fn ($f) => SalesReports::payments($f['from'], $f['to']),
                'columns' => [self::text('label', 'Method'), self::int('transactions', 'Transactions'), self::money('amount', 'Amount')],
            ],
            'hourly' => [
                'label' => 'Sales by hour',
                'rows' => fn ($f) => SalesReports::hourly($f['from'], $f['to']),
                'columns' => [self::text('hour', 'Hour'), self::int('receipts', 'Receipts'), self::money('net_sales', 'Net sales'), self::money('avg_ticket', 'Avg ticket', false)],
            ],
            'cashiers' => [
                'label' => 'Sales by cashier',
                'rows' => fn ($f) => SalesReports::cashiers($f['from'], $f['to']),
                'columns' => [self::text('cashier', 'Cashier'), self::int('receipts', 'Receipts'), self::money('net_sales', 'Net sales'),
                    self::money('discounts', 'Discounts'), self::money('refunds', 'Refunds given'), self::int('voids_authorized', 'Voids authorized')],
            ],
            'returns' => [
                'label' => 'Returns & refunds',
                'rows' => fn ($f) => SalesReports::returns($f['from'], $f['to']),
                'columns' => [self::date(), self::text('return_no', 'Return no.'), self::text('receipt_no', 'Receipt'), self::text('item', 'Item'), self::qty('qty', 'Qty', true),
                    self::money('price', 'Price', false), self::money('amount', 'Refunded'), self::money('vat', 'VAT'), self::money('cogs', 'Cost'),
                    self::mapped('refund_method', 'Refund via', fn ($m) => \App\Services\Pos\Returns::METHODS[$m]['label'] ?? (string) $m), self::text('disposition', 'Goods'),
                    self::text('reason', 'Reason'), self::status(), self::text('processed_by', 'Processed by')],
            ],
            'eod' => [
                'label' => 'End of day / Z-readings',
                'rows' => fn ($f) => SalesReports::eod($f['from'], $f['to']),
                'link' => fn ($r) => url('/reports/eod/' . $r['id']),
                'columns' => [self::date('business_date', 'Business date'), self::status(), self::text('opened_by', 'Opened by'),
                    self::money('opening_cash', 'Beginning cash'), self::int('receipts', 'Receipts'), self::money('net_sales', 'Net sales'), self::money('refunds', 'Refunds'),
                    self::money('expected_cash', 'Expected cash'), self::money('counted_cash', 'Counted'), self::variance('variance', 'Over/(Short)'),
                    self::text('closed_by', 'Closed by'), ['key' => 'closed_at', 'label' => 'Closed at', 'type' => 'datetime', 'align' => 'nowrap']],
            ],
            'voids' => [
                'label' => 'Voids & cancelled sales',
                'rows' => fn ($f) => SalesReports::voids($f['from'], $f['to']),
                'columns' => [self::date(), self::text('kind', 'Kind'), self::text('ref', 'Receipt / sale no.'), self::text('customer_name', 'Customer'),
                    self::money('amount', 'Amount'), self::text('reason', 'Reason'),
                    ['key' => 'voided_at', 'label' => 'Voided at', 'type' => 'datetime', 'align' => 'nowrap'], self::text('authorized_by', 'Authorized by')],
            ],
            'discounts' => [
                'label' => 'Discounts / SC & PWD sales book',
                'rows' => fn ($f) => SalesReports::discounts($f['from'], $f['to']),
                'columns' => [self::date(), self::text('receipt_no', 'Receipt'), self::text('discount_type', 'Discount(s)'), self::text('names', 'SC/PWD name(s)'),
                    self::text('id_numbers', 'OSCA/PWD ID'), self::int('sc_count', 'Persons', false),
                    self::money('gross', 'Gross'), self::money('vat_exempt_sales', 'VAT-exempt sales'), self::money('sc_discount', 'SC/PWD discount'),
                    self::money('promo_discount', 'Other discounts'), self::money('discount', 'Total discount'), self::money('net', 'Net')],
            ],
        ];
    }

    // ------------------------------------------------------------------ inventory
    private static function inventory(): array
    {
        $docCols = fn (array $middle) => array_merge([self::date(), self::text('doc_no', 'Doc no.')], $middle,
            [self::text('item', 'Item'), self::qty('qty', 'Qty'), self::text('uom', 'Unit')]);
        return [
            'onhand' => [
                'label' => 'Stock on hand & valuation', 'params' => 'none', 'filters' => ['category'],
                'rows' => fn ($f) => InventoryReports::onHand($f['category_id']),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('generic_name', 'Generic / alt name'), self::text('category', 'Category'), self::text('uom', 'Unit'),
                    self::qty('on_hand', 'On hand'), self::money('avg_cost', 'Avg cost', false), self::money('last_cost', 'Last cost', false),
                    self::money('value', 'Value at cost'), self::money('price', 'Price', false), self::money('retail_value', 'Value at retail'), self::qty('reorder_point', 'Reorder pt.'), self::status()],
            ],
            'expiry' => [
                'label' => 'Expiry report (expired & expiring lots)', 'params' => 'none',
                'rows' => fn ($f) => InventoryReports::expiry(),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('category', 'Category'), self::text('batch_no', 'Lot no.'),
                    self::date('expiry_date', 'Expiry'), self::daysLeft('days_left', 'Days left'), self::qty('qty_on_hand', 'On hand', true), self::text('uom', 'Unit'),
                    self::money('unit_cost', 'Unit cost', false), self::money('value', 'Value at cost'), self::text('supplier', 'Supplier'), self::status()],
            ],
            'batches' => [
                'label' => 'Lots / batches on hand', 'params' => 'none',
                'rows' => fn ($f) => InventoryReports::batches(),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('batch_no', 'Lot no.'), self::date('expiry_date', 'Expiry'), self::daysLeft('days_left', 'Days left'),
                    self::qty('qty_on_hand', 'On hand', true), self::text('uom', 'Unit'), self::money('unit_cost', 'Unit cost', false), self::money('value', 'Value'), self::text('supplier', 'Supplier')],
            ],
            'reorder' => [
                'label' => 'Reorder / purchase suggestion', 'params' => 'none',
                'rows' => fn ($f) => InventoryReports::reorder(),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('category', 'Category'), self::text('uom', 'Unit'),
                    self::qty('on_hand', 'On hand'), self::qty('reorder_point', 'Reorder pt.'), self::qty('reorder_qty', 'Reorder qty'),
                    self::qty('suggested_order', 'Suggested order'), self::money('last_cost', 'Last cost', false), self::money('est_cost', 'Est. cost'), self::text('last_supplier', 'Last supplier')],
            ],
            'movement' => [
                'label' => 'Inventory movement summary',
                'rows' => fn ($f) => InventoryReports::movement($f['from'], $f['to']),
                'link' => fn ($r) => current_url(['report' => 'stockcard', 'item_id' => $r['id']]),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('uom', 'Unit'), self::qty('beginning', 'Beginning'),
                    self::qty('received', 'Received'), self::qty('sold', 'Sold'), self::qty('returned', 'Returned'), self::qty('issued', 'Issued'), self::qty('wasted', 'Written off'),
                    self::qty('count_adj', 'Count adj.'), self::qty('ending', 'Ending'), self::money('avg_cost', 'Avg cost', false),
                    self::money('ending_value', 'Ending value')],
            ],
            'stockcard' => [
                'label' => 'Stock card (per item)', 'filters' => ['item'], 'requires' => 'item_id',
                'rows' => fn ($f) => InventoryReports::stockCard($f['item_id'], $f['from'], $f['to']),
                'columns' => [self::date(), self::mapped('type', 'Type', fn ($t) => InventoryReports::moveLabel((string) $t)),
                    self::text('ref_no', 'Reference'), self::text('notes', 'Notes'), self::qty('qty_in', 'In', true), self::qty('qty_out', 'Out', true),
                    self::qty('balance', 'Balance'), self::money('unit_cost', 'Unit cost', false), self::money('total_cost', 'Value', false),
                    self::text('user_name', 'User')],
            ],
            'receiving' => [
                'label' => 'Purchases / stock-in report',
                'rows' => fn ($f) => InventoryReports::documents('RECEIVE', $f['from'], $f['to']),
                'columns' => array_merge($docCols([self::text('supplier', 'Supplier'), self::text('invoice_no', 'Invoice/DR'), self::text('payment_mode', 'Payment')]),
                    [self::text('batch_no', 'Lot no.'), self::date('expiry_date', 'Expiry'), self::qty('base_qty', 'Base qty'), self::text('base_uom', 'Base unit'), self::money('unit_cost', 'Unit cost', false),
                        self::money('amount', 'Amount'), self::text('prepared_by', 'Prepared by')]),
            ],
            'issuance' => [
                'label' => 'Stock issuance report',
                'rows' => fn ($f) => InventoryReports::documents('ISSUE', $f['from'], $f['to']),
                'columns' => array_merge($docCols([self::text('issued_to', 'Issued to'), self::text('expense_account', 'Charged to'), self::text('reason', 'Reason')]),
                    [self::money('unit_cost', 'Unit cost', false), self::money('amount', 'Cost')]),
            ],
            'wastage' => [
                'label' => 'Damaged, expired & write-offs',
                'rows' => fn ($f) => InventoryReports::documents('WASTE', $f['from'], $f['to']),
                'columns' => array_merge($docCols([self::text('reason', 'Reason'), self::text('batch_no', 'Lot no.'), self::date('expiry_date', 'Expiry')]),
                    [self::money('unit_cost', 'Unit cost', false), self::money('amount', 'Cost')]),
            ],
            'counts' => [
                'label' => 'Count variance report',
                'rows' => fn ($f) => InventoryReports::counts($f['from'], $f['to']),
                'columns' => [self::date(), self::text('doc_no', 'Count no.'), self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('uom', 'Unit'),
                    self::qty('system_qty', 'System'), self::qty('counted_qty', 'Actual'), self::qty('variance', 'Variance'),
                    self::money('unit_cost', 'Unit cost', false), self::money('variance_value', 'Variance value')],
            ],
            'slow' => [
                'label' => 'Slow-moving stock (no sales in the period)',
                'rows' => fn ($f) => InventoryReports::slowMovers($f['from'], $f['to']),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('category', 'Category'), self::qty('on_hand', 'On hand'), self::text('uom', 'Unit'),
                    self::money('value', 'Value at cost'), self::date('last_sold', 'Last sold')],
            ],
            'margins' => [
                'label' => 'Price list & margins', 'params' => 'none',
                'rows' => fn ($f) => InventoryReports::margins(),
                'columns' => [self::text('sku', 'SKU'), self::text('item', 'Item'), self::text('category', 'Category'), self::mapped('type', 'Type', fn ($t) => InventoryReports::ITEM_TYPES[$t] ?? (string) $t),
                    self::text('uom', 'Unit'), self::money('price', 'Selling price', false), self::money('wholesale_price', 'Wholesale', false), self::money('price_net_of_vat', 'Net of VAT', false),
                    self::money('cost', 'Cost', false), self::money('margin', 'Margin', false), self::marginPct('margin_pct', 'Margin %')],
            ],
        ];
    }

    // ------------------------------------------------------------------ finance
    private static function finance(): array
    {
        $source = self::mapped('source', 'Source', [FinanceReports::class, 'sourceLabel']);
        $aging = fn (string $party) => [self::text('party', $party), self::money('current', 'Current'), self::money('d1_30', '1–30 days'),
            self::money('d31_60', '31–60'), self::money('d61_90', '61–90'), self::money('over_90', 'Over 90'), self::money('total', 'Total')];
        return [
            'is' => [
                'label' => 'Income statement (P&L)', 'view' => 'statement',
                'rows' => fn ($f) => FinanceReports::incomeStatement($f['from'], $f['to']),
            ],
            'bs' => [
                'label' => 'Balance sheet', 'params' => 'asof', 'view' => 'statement',
                'rows' => fn ($f) => FinanceReports::balanceSheet($f['to']),
            ],
            'tb' => [
                'label' => 'Trial balance',
                'rows' => fn ($f) => FinanceReports::trialBalance($f['from'], $f['to']),
                'link' => fn ($r) => current_url(['report' => 'gl', 'account_id' => $r['id']]),
                'columns' => [self::text('code', 'Code'), self::text('account', 'Account'), self::text('type', 'Type'), self::money('opening', 'Opening', false),
                    self::money('period_debit', 'Debit'), self::money('period_credit', 'Credit'), self::money('ending_debit', 'Ending Dr'),
                    self::money('ending_credit', 'Ending Cr')],
            ],
            'gl' => [
                'label' => 'General ledger (per account)', 'filters' => ['account'], 'requires' => 'account_id',
                'rows' => fn ($f) => FinanceReports::generalLedger($f['account_id'], $f['from'], $f['to']),
                'columns' => [self::date(), self::text('entry_no', 'Entry'), $source, self::text('ref_no', 'Ref'), self::text('description', 'Description'),
                    self::money('debit', 'Debit'), self::money('credit', 'Credit'), self::money('balance', 'Balance', false)],
            ],
            'journal' => [
                'label' => 'General journal',
                'rows' => fn ($f) => FinanceReports::journal($f['from'], $f['to']),
                'columns' => [self::date(), self::text('entry_no', 'Entry'), $source, self::text('ref_no', 'Ref'), self::text('memo', 'Memo'),
                    self::text('code', 'Code'), self::text('account', 'Account'), self::money('debit', 'Debit'), self::money('credit', 'Credit'),
                    self::text('line_memo', 'Line memo')],
            ],
            'bank' => [
                'label' => 'Bank register', 'filters' => ['bank'], 'requires' => 'bank_account_id',
                'rows' => fn ($f) => FinanceReports::bankRegister($f['bank_account_id'], $f['from'], $f['to']),
                'columns' => [self::date(), self::text('entry_no', 'Entry'), $source, self::text('ref_no', 'Ref'), self::text('description', 'Description'),
                    self::money('money_in', 'Money in'), self::money('money_out', 'Money out'), self::money('balance', 'Balance', false)],
            ],
            'petty' => [
                'label' => 'Petty cash report', 'view' => 'petty', 'perms' => ['reports.finance', 'pettycash.view'],
                'rows' => fn ($f) => FinanceReports::pettyCash($f['from'], $f['to']),
                'columns' => [self::date(), self::text('doc_no', 'PCV no.'), self::text('type', 'Type'), self::text('source', 'Source'),
                    self::text('payee', 'Payee'), self::text('description', 'Description'), self::text('account', 'Account'), self::text('or_no', 'OR no.'),
                    self::money('amount', 'Amount', false) + [
                        'value' => fn ($r) => ($r['type'] === 'replenish' ? 1 : -1) * (float) $r['amount'],
                        'html' => fn ($r) => '<span class="' . ($r['status'] === 'void' ? 'muted' : '') . '">' . ($r['type'] === 'replenish' ? '+' : '−') . money($r['amount']) . '</span>',
                    ],
                    self::status(), self::text('recorded_by', 'Recorded by')],
                'by_account' => [self::text('account', 'Account'), self::int('cnt', 'Count'), self::money('amount', 'Amount')],
            ],
            'ap' => [
                'label' => 'Accounts payable aging', 'params' => 'asof',
                'rows' => fn ($f) => FinanceReports::aging('ap', $f['to']),
                'columns' => $aging('Supplier'),
            ],
            'ar' => [
                'label' => 'Accounts receivable aging', 'params' => 'asof',
                'rows' => fn ($f) => FinanceReports::aging('ar', $f['to']),
                'columns' => $aging('Customer'),
            ],
            'ca' => [
                'label' => 'Cash advances report',
                'rows' => fn ($f) => FinanceReports::cashAdvances($f['from'], $f['to']),
                'columns' => [self::text('doc_no', 'CA no.'), self::date('request_date', 'Requested'), self::text('emp_no', 'Emp no.'),
                    self::text('employee', 'Employee'), self::money('amount', 'Amount'), self::status(), self::text('release_method', 'Released via'),
                    self::date('release_date', 'Released'), self::text('approved_by', 'Approved by'), self::money('repaid', 'Repaid'),
                    self::money('balance', 'Balance'), self::text('reason', 'Reason')],
            ],
        ];
    }
}
