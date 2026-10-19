<?php
use App\Controllers\DashboardController;
use App\Controllers\Reports\Catalog;
use App\Controllers\Reports\ReportController;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\InventoryDocs;
use App\Services\Ledger;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Returns;
use App\Services\Pos\Tickets;
use App\Services\Reports\Dashboard;
use App\Services\Reports\FinanceReports;
use App\Services\Reports\InventoryReports;
use App\Services\Reports\SalesReports;

as_user('admin');
$_SESSION = $_SESSION ?? [];

/** Render a report page through the controller (views included); PHP warnings fail the test. */
function rep_page(string $method, array $query, string $path = '/reports'): string|Response
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path . '?' . http_build_query($query);
    $GLOBALS['__request'] = $req = new Request();
    set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
    try {
        return $method === 'dashboard' ? (new DashboardController())->index($req) : (new ReportController())->$method($req);
    } finally {
        restore_error_handler();
    }
}

/** Open every report of every group (screen and Excel). */
function rep_all_pages(array $extra): void
{
    foreach (['sales', 'inventory', 'finance'] as $group) {
        foreach (array_keys(Catalog::group($group)['reports']) as $key) {
            $html = rep_page($group, ['report' => $key, 'from' => '2026-09-01', 'to' => '2026-09-30'] + $extra, "/reports/$group");
            ok(is_string($html) && str_contains($html, 'page-header'), "$group/$key renders");
            $x = rep_page($group, ['report' => $key, 'from' => '2026-09-01', 'to' => '2026-09-30', 'export' => 'xlsx'] + $extra, "/reports/$group");
            if ($x instanceof Response) ok(str_starts_with($x->body, 'PK'), "$group/$key exports xlsx");
        }
    }
    ok(str_contains(rep_page('dashboard', ['from' => '2026-09-01', 'to' => '2026-09-30'], '/'), 'Net sales'), 'dashboard renders');
    ok(str_contains(rep_page('dashboard', ['from' => '2026-09-10', 'to' => '2026-09-10'], '/'), 'Sales by hour'), 'one-day dashboard shows hours');
}

test('every report and the dashboard run with only the sample catalog', function () {
    rep_all_pages(['item_id' => item_id('Biogesic'), 'account_id' => Ledger::account('cash_on_hand')]);
    $d = Dashboard::data('2026-09-01', '2026-09-30');
    eq(0, $d['kpi']['net_sales']);
    eq(0, $d['kpi']['margin_pct']);
    ok($d['expiry']['expired_lots'] >= 1, 'expiry alerts from the sample lots');
});

// ------------------------------------------------------------------ sample month (September 2026)
$GLOBALS['rep'] = [];
test('set up a month of transactions', function () {
    // Move the sample opening stock to Sep 1 so September has a beginning balance
    foreach (['stock_movements' => 'bdate', 'inv_docs' => 'doc_date', 'journal_entries' => 'entry_date', 'item_batches' => 'received_at'] as $table => $col) {
        DB::run("UPDATE $table SET $col = '2026-09-01' WHERE $col IS NOT NULL AND CAST($col AS DATE) = CAST(? AS DATE)", [today()]);
    }
    // Opening capital, petty cash fund and a bank account
    Ledger::post('2026-09-01', 'Owner investment', [['key' => 'cash_on_hand', 'debit' => 50000], ['key' => 'capital', 'credit' => 50000]]);
    $pcr = DB::insert('petty_cash_txns', ['doc_no' => 'PCV-1', 'txn_date' => '2026-09-01', 'txn_type' => 'replenish', 'source' => 'fund', 'amount' => 2000, 'status' => 'posted']);
    Ledger::post('2026-09-01', 'Petty cash fund', [['key' => 'petty_cash', 'debit' => 2000], ['key' => 'cash_on_hand', 'credit' => 2000]], 'petty_cash', $pcr);
    $gl = DB::insert('accounts', ['code' => '1031', 'name' => 'Cash in Bank - BDO', 'type' => 'asset', 'subtype' => 'bank', 'active' => 1]);
    $bank = DB::insert('bank_accounts', ['bank_name' => 'BDO', 'account_no' => '001234567', 'gl_account_id' => $gl, 'active' => 1]);
    Ledger::post('2026-09-02', 'Deposit', [['account_id' => $gl, 'debit' => 10000, 'bank_account_id' => $bank], ['key' => 'cash_on_hand', 'credit' => 10000]], 'bank');
    Ledger::post('2026-09-20', 'Bank charge', [['key' => 'bank_charges', 'debit' => 50], ['account_id' => $gl, 'credit' => 50, 'bank_account_id' => $bank]], 'bank');

    // Purchase (09-05): 10 boxes of Biogesic, cash
    $rr = InventoryDocs::create('RECEIVE', ['doc_date' => '2026-09-05', 'payment_mode' => 'cash', 'post' => 1,
        'lines' => [['item_id' => item_id('Biogesic'), 'qty' => 10, 'uom_id' => uom_id('box'), 'unit_cost' => 300, 'batch_no' => 'BG2609', 'expiry_date' => '2028-09-30']]]);

    // Business day 09-10: two sales, then closed with an exact count
    $s1 = CashSessions::open(2000, '2026-09-10');
    $a = Tickets::create([]);
    Tickets::addItem($a['id'], item_id('Biogesic'), 10);
    Tickets::addItem($a['id'], item_id('Face Mask'), 1, null, null, null, uom_id('box'));
    $a = Tickets::pay($a['id'], [['method' => 'cash', 'amount' => 200]]);
    $b = Tickets::create([]);
    Tickets::addItem($b['id'], item_id('Losartan'), 10);
    Tickets::discount($b['id'], ['discount_type' => 'sc', 'sc_details' => [['name' => 'Lola Nena', 'id_no' => 'SC-123']]]);
    $b = Tickets::pay($b['id'], [['method' => 'card', 'amount' => 96, 'reference' => 'APP-1']]);
    DB::update('tickets', (int) $a['id'], ['paid_at' => '2026-09-10 10:15:00', 'created_at' => '2026-09-10 10:10:00']);
    DB::update('tickets', (int) $b['id'], ['paid_at' => '2026-09-10 12:15:00', 'created_at' => '2026-09-10 12:10:00']);
    $z1 = CashSessions::close([1000 => 2, 100 => 1, 50 => 1, 20 => 1, 5 => 1]);   // 2175 = 2000 + 175 cash sales

    // Business day 09-11 (left open): a GCash sale, a voided cash sale, a return on sale A
    $s2 = CashSessions::open(1000, '2026-09-11');
    $c = Tickets::create([]);
    Tickets::addItem($c['id'], item_id('Betadine'), 2);
    $c = Tickets::pay($c['id'], [['method' => 'gcash', 'amount' => 190, 'reference' => 'G-1']]);
    DB::update('tickets', (int) $c['id'], ['paid_at' => '2026-09-11 19:15:00', 'created_at' => '2026-09-11 19:10:00']);
    $v = Tickets::create([]);
    Tickets::addItem($v['id'], item_id('Bioflu'), 1);
    $v = Tickets::pay($v['id'], [['method' => 'cash', 'amount' => 8]]);
    $v = Tickets::void((int) $v['id'], 'Customer left');
    $ret = Returns::create((int) $a['id'], [['id' => line_of($a, 'Biogesic')['id'], 'qty' => 2]], 'cash', 'Wrong item', true);

    // Write-off of the expired Loperamide lot (09-11) and a fare paid from petty cash (09-10)
    $expired = lots('Loperamide')[0];
    $w = InventoryDocs::create('WASTE', ['doc_date' => '2026-09-11', 'reason' => 'expired', 'post' => 1, 'lines' => [['item_id' => item_id('Loperamide'), 'qty' => 150, 'batch_id' => $expired['id']]]]);
    $fare = (int) DB::value("SELECT id FROM accounts WHERE code = '6400'");
    $pc = DB::insert('petty_cash_txns', ['doc_no' => 'PCV-2', 'txn_date' => '2026-09-10', 'txn_type' => 'expense', 'source' => 'fund', 'amount' => 150,
        'account_id' => $fare, 'payee' => 'Grab', 'status' => 'posted']);
    Ledger::post('2026-09-10', 'Delivery fare', [['account_id' => $fare, 'debit' => 150], ['key' => 'petty_cash', 'credit' => 150]], 'petty_cash', $pc, 'PCV-2');

    // Open payables / receivables for aging, a released cash advance
    DB::insert('ap_bills', ['bill_no' => 'AP-1', 'supplier_id' => 1, 'bill_date' => '2026-08-01', 'due_date' => '2026-08-16', 'amount' => 1000, 'status' => 'open']);
    DB::insert('ap_bills', ['bill_no' => 'AP-2', 'supplier_id' => 1, 'bill_date' => '2026-09-25', 'due_date' => '2026-10-15', 'amount' => 500, 'paid_amount' => 100, 'status' => 'partial']);
    DB::insert('ap_bills', ['bill_no' => 'AP-3', 'supplier_id' => 2, 'bill_date' => '2026-05-01', 'due_date' => '2026-05-01', 'amount' => 700, 'status' => 'open']);
    DB::insert('ar_invoices', ['invoice_no' => 'AR-1', 'customer_id' => 1, 'inv_date' => '2026-09-01', 'due_date' => '2026-09-20', 'amount' => 300, 'status' => 'open']);
    DB::insert('cash_advances', ['doc_no' => 'CA-1', 'employee_id' => 1, 'request_date' => '2026-09-03', 'amount' => 1000, 'status' => 'approved', 'balance' => 1000]);

    $GLOBALS['rep'] = ['s1' => (int) $s1['id'], 's2' => (int) $s2['id'], 'bank' => $bank, 'a' => Tickets::get((int) $a['id']), 'b' => $b, 'c' => $c, 'v' => $v, 'ret' => $ret, 'rr' => $rr, 'w' => $w, 'z1' => $z1];
    eq(0, $z1['cash']['variance'], 'day 1 counted exactly');
    assert_books_balance();
});

test('dashboard KPIs', function () {
    ['a' => $a, 'b' => $b, 'c' => $c, 'ret' => $ret] = $GLOBALS['rep'];
    $k = Dashboard::data('2026-09-01', '2026-09-30')['kpi'];
    eq(175 + 96 + 190, $k['net_sales'], 'net sales');
    eq(3, $k['receipts']);
    $vat = r2($a['vat_amount'] + $b['vat_amount'] + $c['vat_amount']);
    eq(r2(175 - 175 / 1.12) + r2(190 - 190 / 1.12), $vat, 'VAT: the SC sale is exempt');
    eq($vat, $k['vat']);
    eq(r2(461 - $vat), $k['net_of_vat']);
    eq(10 + 50 + 10 + 2, $k['items_sold'], 'base units: the box of 50 masks counts as 50');
    eq(r2(461 / 3), $k['avg_ticket']);
    eq(24, $k['discounts'], 'SC 20% of 120');
    $cogs = r2($a['cogs'] + $b['cogs'] + $c['cogs']);
    eq($cogs, $k['cogs']);
    eq(r2(461 - $vat - $cogs), $k['gross_profit']);
    eq(r2((461 - $vat - $cogs) / (461 - $vat) * 100), $k['margin_pct']);
    eq(1, $k['voids']);
    eq(8, $k['void_amount']);
    eq(1, $k['returns']);
    eq($ret['refund_amount'], $k['return_amount']);
    eq(11, $ret['refund_amount'], '2 of 10 tabs at 55');
    eq(630, $k['wastage'], '150 x 4.20');
    eq(3000, $k['purchases'], 'opening stock is not a purchase');
    eq(150, $k['petty_cash']);
    eq(200, $k['operating_expenses'], 'fare 150 + bank charge 50 (wastage is cost of sales)');
    eq($k['net_income_est'], FinanceReports::incomeStatement('2026-09-01', '2026-09-30')['net_income'], 'agrees with the income statement');
});

test('dashboard series, expiry card and financial position', function () {
    $d = Dashboard::data('2026-09-01', '2026-09-30');
    eq(2, count($d['daily']));
    eq(3, count($d['hourly']));
    eq('Betadine 10% Solution 60ml', $d['top_items'][0]['name']);
    eq(['Medical Supplies', 'Prescription Medicines', 'OTC Medicines'], array_column($d['categories'], 'name'));
    eq(0, $d['expiry']['expired_lots'], 'expired lot written off');
    ok($d['expiry']['expiring_lots'] >= 1 && count($d['expiring']) >= 1, 'lots expiring within the warning window');
    $p = $d['position'];
    eq(acct_balance('cash_on_hand'), $p['cash_on_hand']);
    eq(50000 - 2000 - 10000 - 3000 + 175 + 8 - 8 - 11 + 0, $p['cash_on_hand'], 'cash: void and refund reversed');
    eq(1850, $p['petty_cash']);
    eq(9950, $p['banks'][0]['balance']);
    eq(1000 + 400 + 700, $p['ap_total']);
    eq(300, $p['ar_total']);
    eq(1000, $p['ca_outstanding']);
    ok($p['inventory_retail'] > $p['inventory_value'], 'retail value above cost');
    ok($d['session'] !== null, 'open business day');
});

test('sales by item, category, payment, hour, receipts, returns and discounts', function () {
    $items = array_column(SalesReports::items('2026-09-01', '2026-09-30'), null, 'item');
    eq(10, $items['Biogesic 500mg']['qty'], 'voided receipt excluded');
    eq(2, $items['Biogesic 500mg']['returned']);
    eq(55, $items['Biogesic 500mg']['gross']);
    eq(50, $items['Face Mask 3-ply']['qty'], 'pack sold as base units');
    eq(120, $items['Face Mask 3-ply']['gross']);
    eq(190, $items['Betadine 10% Solution 60ml']['gross']);
    eq(100, array_sum(array_column($items, 'share_pct')), '% of sales adds up');
    ok(!isset($items['Bioflu']), 'voided sale excluded');
    $cats = array_column(SalesReports::categories('2026-09-01', '2026-09-30'), 'gross', 'category');
    eq(310, $cats['Medical Supplies']);
    eq(55, $cats['OTC Medicines']);
    eq(120, $cats['Prescription Medicines']);
    $pay = array_column(SalesReports::payments('2026-09-01', '2026-09-30'), 'amount', 'method');
    eq(['gcash' => 190.0, 'cash' => 175.0, 'card' => 96.0], array_map('floatval', $pay), 'largest first');
    eq(['10:00 - 10:59', '12:00 - 12:59', '19:00 - 19:59'], array_column(SalesReports::hourly('2026-09-01', '2026-09-30'), 'hour'));
    $receipts = SalesReports::receipts('2026-09-01', '2026-09-30');
    eq(4, count($receipts));
    eq('Cash', $receipts[0]['payment']);
    eq(11, $receipts[0]['refunded_total']);
    eq(1, count(SalesReports::receipts('2026-09-01', '2026-09-30', 'void')));
    $daily = SalesReports::daily('2026-09-01', '2026-09-30');
    eq(271, $daily[0]['net_sales']);
    eq(11, $daily[1]['refunds']);
    $voids = SalesReports::voids('2026-09-01', '2026-09-30');
    eq(['Voided receipt'], array_column($voids, 'kind'));
    $ret = SalesReports::returns('2026-09-01', '2026-09-30');
    eq(1, count($ret));
    eq(2, $ret[0]['qty']);
    eq('Restocked', $ret[0]['disposition']);
    $sc = SalesReports::discounts('2026-09-01', '2026-09-30');
    eq('SC-123', $sc[0]['id_numbers']);
    eq('Lola Nena', $sc[0]['names']);
    eq('Senior Citizen (whole receipt)', $sc[0]['discount_type']);
    eq(1, count(SalesReports::cashiers('2026-09-01', '2026-09-30')));
});

test('end of day history and X/Z readings', function () {
    $eod = SalesReports::eod('2026-09-01', '2026-09-30');
    eq(2, count($eod));
    eq(2, $eod[0]['receipts']);
    eq(271, $eod[0]['net_sales']);
    eq('closed', $eod[0]['status']);
    eq(11, $eod[1]['refunds']);
    $r = SalesReports::reading($GLOBALS['rep']['s1']);
    eq(2, $r['sales']['cnt']);
    eq(2175, $r['cash']['expected'], 'opening 2000 + cash sales 175');
    $r2 = SalesReports::reading($GLOBALS['rep']['s2']);
    eq(1000 + 8 - 8 - 11, $r2['cash']['expected'], 'void refund and return come out of the drawer');
    eq(1, $r2['returns']['cnt']);
    $_GET = [];
    $html = (new ReportController())->eod(new Request(), (string) $GLOBALS['rep']['s1']);
    ok(str_contains($html, 'Z-READING') && str_contains($html, 'print-root'), 'printable Z-reading');
});

test('trial balance balances; income statement; balance sheet check is zero', function () {
    $tb = FinanceReports::trialBalance('2026-09-01', '2026-09-30');
    eq(round(array_sum(array_column($tb, 'period_debit')), 2), round(array_sum(array_column($tb, 'period_credit')), 2), 'period');
    eq(round(array_sum(array_column($tb, 'ending_debit')), 2), round(array_sum(array_column($tb, 'ending_credit')), 2), 'ending');
    $tbOct = FinanceReports::trialBalance('2026-10-01', '2026-10-31');
    eq(round(array_sum(array_column($tbOct, 'opening')), 2), 0.0, 'openings net to zero');

    ['a' => $a, 'b' => $b, 'c' => $c, 'ret' => $ret] = $GLOBALS['rep'];
    $is = FinanceReports::incomeStatement('2026-09-01', '2026-09-30');
    $vat = r2($a['vat_amount'] + $b['vat_amount'] + $c['vat_amount']);
    eq(r2(461 - $vat - ($ret['refund_amount'] - $ret['vat_amount'])), $is['revenue'], 'sales net of VAT less SC discount and returns, void reversed');
    eq(r2($a['cogs'] + $b['cogs'] + $c['cogs'] - $ret['cogs'] + 630), $is['total_cogs']);
    eq(200, $is['total_opex']);
    eq(r2($is['revenue'] - $is['total_cogs'] - $is['total_opex']), $is['net_income']);

    $bs = FinanceReports::balanceSheet('2026-09-30');
    eq(0, $bs['check']);
    $eqy = array_column($bs['equity'], 'amount', 'name');
    eq($is['net_income'], $eqy['Net Income (cumulative, unclosed)']);
    eq(0, FinanceReports::balanceSheet('2026-09-10')['check']);
});

test('general ledger, journal and bank register running balances', function () {
    $cash = Ledger::account('cash_on_hand');
    $gl = FinanceReports::generalLedger($cash, '2026-09-02', '2026-09-30');
    eq(48000, $gl[0]['balance'], 'beginning = 50000 - 2000 petty fund');
    eq(Ledger::balance($cash, '2026-09-30'), end($gl)['balance']);
    $sources = array_column(FinanceReports::journal('2026-09-11', '2026-09-11'), 'source');
    ok(in_array('pos_sale', $sources, true) && in_array('pos_return', $sources, true), 'journal lists sales, returns and reversals');
    $bank = FinanceReports::bankRegister($GLOBALS['rep']['bank'], '2026-09-01', '2026-09-30');
    eq(0, $bank[0]['balance']);
    eq(10000, $bank[1]['balance']);
    eq(9950, $bank[2]['balance']);
});

test('stock card running balance, movement summary, on hand, reorder, margins', function () {
    $card = InventoryReports::stockCard(item_id('Biogesic'), '2026-09-10', '2026-09-30');
    eq('BEGINNING', $card[0]['type']);
    eq(1900, $card[0]['balance'], '900 opening + 1000 purchased on 09-05');
    eq([1900.0, 1890.0, 1892.0], array_map('floatval', array_column($card, 'balance')));
    eq(2, $card[2]['qty_in'], 'customer return');
    $bioflu = array_column(InventoryReports::stockCard(item_id('Bioflu'), '2026-09-01', '2026-09-30'), 'balance');
    eq(300, end($bioflu), '300 - 1 + 1 (void)');
    $mv = array_column(InventoryReports::movement('2026-09-10', '2026-09-30'), null, 'item');
    $bg = $mv['Biogesic 500mg'];
    eq(1900, $bg['beginning']);
    eq(10, $bg['sold']);
    eq(2, $bg['returned']);
    eq(1892, $bg['ending']);
    eq(0, $mv['Bioflu']['sold'], 'void sale nets out');
    eq(150, $mv['Loperamide 2mg']['wasted']);
    eq(stock('Biogesic'), $bg['ending']);
    $onhand = array_column(InventoryReports::onHand(), null, 'item');
    eq('REORDER', $onhand['Loperamide 2mg']['status']);
    eq(r2(1892 * (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [item_id('Biogesic')])), $onhand['Biogesic 500mg']['value']);
    $reorder = array_column(InventoryReports::reorder(), null, 'item');
    eq(200, $reorder['Loperamide 2mg']['suggested_order']);
    eq(1, count(InventoryReports::documents('WASTE', '2026-09-01', '2026-09-30')));
    ok(count(InventoryReports::documents('RECEIVE', '2026-09-01', '2026-09-30')) > 20, 'opening stock lines + the purchase');
    $m = array_column(InventoryReports::margins(), null, 'item');
    eq(r2(95 / 1.12), $m['Betadine 10% Solution 60ml']['price_net_of_vat']);
    ok($m['Betadine 10% Solution 60ml']['margin_pct'] > 0, 'margin %');
    ok(count(InventoryReports::expiry()) >= 1);
});

test('AP / AR aging buckets', function () {
    $ap = array_column(FinanceReports::aging('ap', '2026-09-30'), null, 'party');
    $z = $ap['Zuellig Pharma Corp.'];
    eq(1000, $z['d31_60'], '45 days past due');
    eq(400, $z['current'], 'not yet due, partially paid');
    eq(1400, $z['total']);
    eq(700, $ap['Metro Drug Distribution']['over_90']);
    eq('Zuellig Pharma Corp.', FinanceReports::aging('ap', '2026-09-30')[0]['party'], 'largest balance first');
    eq(1000, array_column(FinanceReports::aging('ap', '2026-09-01'), null, 'party')['Zuellig Pharma Corp.']['total'], 'bills after the as-of date are excluded');
    $ar = FinanceReports::aging('ar', '2026-09-30');
    eq(300, $ar[0]['d1_30']);
    eq(300, FinanceReports::aging('ar', '2026-09-15')[0]['current']);
});

test('petty cash report and cash advances', function () {
    $p = FinanceReports::pettyCash('2026-09-05', '2026-09-30');
    eq(2000, $p['beginning']);
    eq(1850, $p['ending']);
    eq(150, $p['spent']);
    eq(0, $p['added']);
    eq('Transportation & Delivery', $p['by_account'][0]['account']);
    eq(2000, FinanceReports::pettyCash('2026-09-01', '2026-09-30')['added']);
    eq(1000, FinanceReports::cashAdvances('2026-09-01', '2026-09-30')[0]['balance']);
});

test('every report page renders and exports with data', function () {
    rep_all_pages(['item_id' => item_id('Biogesic'), 'account_id' => Ledger::account('cash_on_hand'), 'bank_account_id' => $GLOBALS['rep']['bank']]);
    $tb = rep_page('finance', ['report' => 'is', 'from' => '2026-09-01', 'to' => '2026-09-30'], '/reports/finance');
    ok(str_contains($tb, 'GROSS PROFIT') && str_contains($tb, 'NET INCOME'), 'income statement layout');
    $bs = rep_page('finance', ['report' => 'bs', 'to' => '2026-09-30'], '/reports/finance');
    ok(str_contains($bs, 'TOTAL ASSETS') && !str_contains($bs, 'Out of balance'), 'balance sheet balances');
    $pick = rep_page('inventory', ['report' => 'stockcard'], '/reports/inventory');
    ok(str_contains($pick, 'Select the item'), 'stock card asks for an item');
    $exp = rep_page('inventory', ['report' => 'expiry'], '/reports/inventory');
    ok(str_contains($exp, 'EXPIRING'), 'expiry report shows the expiring lots');
});

test('a user with only pettycash.view sees just the petty cash report', function () {
    $role = DB::insert('roles', ['name' => 'Petty only', 'permissions' => '["pettycash.view"]']);
    DB::insert('users', ['username' => 'pettyonly', 'full_name' => 'Petty Only', 'password_hash' => 'x', 'role_id' => $role, 'active' => 1]);
    as_user('pettyonly');
    try {
        $html = rep_page('finance', ['report' => 'tb'], '/reports/finance');
        ok(str_contains($html, 'Fund beginning balance'), 'falls back to the petty cash report');
        ok(!str_contains($html, 'Trial balance'), 'other finance reports hidden');
    } finally {
        as_user('admin');
    }
});
