<?php
use App\Core\DB;
use App\Services\Batches;
use App\Services\Finance\PettyCash;
use App\Services\Items;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Returns;
use App\Services\Pos\Tickets;
use App\Services\Settings;

as_user('admin');
$vat = fn (float $gross) => r2($gross - $gross / 1.12);

test('cannot sell before the day is opened', function () {
    throws(fn () => Tickets::create([]), 'not open');
});

test('open the business day with beginning cash', function () {
    $s = CashSessions::open(2000);
    eq('open', $s['status']);
    throws(fn () => CashSessions::open(100), 'already open');
});

$GLOBALS['t1'] = null;
test('sale: box pack + VAT-exempt medicine with SC discount + split payment with change', function () use ($vat) {
    $t = Tickets::create(['customer_name' => 'Aling Nena']);
    Tickets::addItem($t['id'], item_id('Biogesic'), 1, null, null, null, uom_id('box'));
    Tickets::addItem($t['id'], item_id('Biogesic'), 5);
    $t = Tickets::addItem($t['id'], item_id('Losartan'), 10);
    eq(500 + 27.5 + 120, $t['subtotal']);
    eq(3, count($t['items']), 'box and loose tabs are separate lines');
    eq('box', line_of($t, 'Biogesic')['uom_label']);
    throws(fn () => Tickets::discount($t['id'], ['discount_type' => 'sc', 'sc_details' => []]), 'ID number');
    $t = Tickets::discount($t['id'], ['discount_type' => 'sc', 'sc_details' => [['name' => 'Lola Nena', 'id_no' => 'OSCA-123']]]);
    // SC: everything VAT-exempt, 20% off the net amount: (527.5 / 1.12 + 120) x 0.8
    $net = r2(527.5 / 1.12) + 120;
    eq($net, $t['vat_exempt_sales']);
    eq(r2($net * 0.2), $t['discount_amount']);
    eq(0, $t['vat_amount']);
    eq(r2($net * 0.8), $t['total']);
    throws(fn () => Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 100]]), 'short');
    $paid = Tickets::pay($t['id'], [['method' => 'card', 'amount' => 200, 'reference' => 'APP1'], ['method' => 'cash', 'amount' => 300]]);
    eq('paid', $paid['status']);
    eq(r2(500 - $t['total']), $paid['change_amount']);
    ok(str_starts_with($paid['receipt_no'], 'SI-'), 'receipt number');
    eq(795, stock('Biogesic'), '900 - 100 - 5');
    eq(590, stock('Losartan'));
    eq(195, array_column(lots('Biogesic'), 'qty_on_hand', 'batch_no')['BG2501B'], 'FEFO: the soonest-expiring lot first');
    eq(r2(105 * 3.2 + 10 * 6.8), $paid['cogs']);
    eq('Lola Nena', $paid['sc_details'][0]['name']);
    assert_books_balance();
    $GLOBALS['t1'] = $paid;
});

test('barcode scan of a pack, merge rule, held sales list, hold and resume', function () {
    $scan = Items::lookup('14800016101011');
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], (int) $scan['item']['id'], 1, null, null, null, $scan['uom_id']);
    $t = Tickets::addItem($t['id'], (int) $scan['item']['id'], 1, null, null, null, $scan['uom_id']);
    eq(1, count($t['items']), 'same item and unit merge into one line');
    eq(2, line_of($t, 'Biogesic')['qty']);
    eq(1000, $t['subtotal']);
    $held = Tickets::openList();
    eq(1, count($held));
    eq(1000, $held[0]['total']);
    eq(2, $held[0]['item_count']);
    $again = Tickets::get($t['id']);
    eq('open', $again['status']);
    $t = Tickets::updateLine($t['id'], line_of($t, 'Biogesic')['id'], ['qty' => 1]);
    eq(500, $t['total']);
    Tickets::void($t['id'], 'customer left');
    eq([], Tickets::openList());
});

test('price override and wholesale pricing', function () {
    make_user('cashier1', 'Cashier');
    $pen = Items::create(['name' => 'Ballpen Black', 'item_type' => 'product', 'base_uom_id' => uom_id('pc'), 'price' => 10, 'wholesale_price' => 8, 'wholesale_min_qty' => 12, 'avg_cost' => 5, 'sellable' => 1, 'active' => 1]);
    DB::run('UPDATE items SET stock_qty = 100 WHERE id = ?', [$pen]);
    as_user('cashier1');
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], $pen, 10);
    eq(100, $t['subtotal']);
    $t = Tickets::addItem($t['id'], $pen, 2);
    eq(12 * 8, $t['subtotal'], 'wholesale price kicks in at 12 pcs');
    throws(fn () => Tickets::updateLine($t['id'], line_of($t, 'Ballpen')['id'], ['price' => 7]), 'Manager authorization');
    throws(fn () => Tickets::updateLine($t['id'], line_of($t, 'Ballpen')['id'], ['price' => 7], '9999'), 'Invalid manager PIN');
    $t = Tickets::updateLine($t['id'], line_of($t, 'Ballpen')['id'], ['price' => 7], '1234');
    eq(84, $t['subtotal']);
    throws(fn () => Tickets::addItem($t['id'], item_id('Bioflu'), 1, null, 1), 'Manager authorization');
    $paid = Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 100]]);
    eq(16, $paid['change_amount']);
    as_user('admin');
    assert_books_balance();
});

test('block negative stock setting', function () {
    Settings::set('block_negative_stock', '1');
    $t = Tickets::create([]);
    throws(fn () => Tickets::addItem($t['id'], item_id('Digital Thermometer'), 9), 'Only 8');
    $t = Tickets::addItem($t['id'], item_id('Digital Thermometer'), 8);
    throws(fn () => Tickets::updateLine($t['id'], line_of($t, 'Digital')['id'], ['qty' => 9]), 'Only 8');
    Tickets::addItem($t['id'], item_id('Blood Pressure'), 1);   // services are never blocked
    Tickets::void($t['id'], 'test');
    Settings::set('block_negative_stock', '0');
});

test('Rx note is kept on the sale and charge to a customer account creates a receivable', function () {
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], item_id('Amoxicillin'), 21);
    eq(1, line_of($t, 'Amoxicillin')['rx_required']);
    throws(fn () => Tickets::pay($t['id'], [['method' => 'charge', 'amount' => $t['total']]]), 'customer account');
    $cust = (int) DB::value("SELECT id FROM customers WHERE name LIKE 'Dr. Reyes%'");
    $paid = Tickets::pay($t['id'], [['method' => 'charge', 'amount' => $t['total'], 'customer_id' => $cust]], ['rx_note' => 'Dr. Reyes PRC 12345 for Pedro']);
    eq('Dr. Reyes PRC 12345 for Pedro', $paid['rx_note']);
    $ar = DB::one("SELECT * FROM ar_invoices WHERE source_type = 'pos_sale' AND source_id = ?", [$paid['id']]);
    eq(178.5, (float) $ar['amount']);
    eq(178.5, acct_balance('ar'));
    eq(179, array_column(lots('Amoxicillin'), 'qty_on_hand', 'batch_no')['AMX2403'], 'FEFO from the lot expiring in 60 days');
    assert_books_balance();
});

$GLOBALS['ret'] = null;
test('partial return: refund from the drawer, stock back into the same lot, Sales Returns booked', function () {
    $t = $GLOBALS['t1'];
    $box = line_of($t, 'Biogesic');
    $losartan = line_of($t, 'Losartan');
    throws(fn () => Returns::create($t['id'], [['id' => $losartan['id'], 'qty' => 11]], 'cash', 'too many'), 'Only 10');
    throws(fn () => Returns::create($t['id'], [['id' => $losartan['id'], 'qty' => 4]], 'cash', ''), 'Reason');
    throws(fn () => Returns::create($t['id'], [['id' => $losartan['id'], 'qty' => 4]], 'bitcoin', 'x'), 'refunded');
    $before = acct_balance('cash_on_hand');
    $lotBefore = array_column(Batches::forItem(item_id('Losartan')), 'qty_on_hand', 'batch_no')['LS2411'];
    $r = Returns::create($t['id'], [['id' => $losartan['id'], 'qty' => 4]], 'cash', 'Wrong dosage', true);
    ok(str_starts_with($r['return_no'], 'RET-'));
    // 4 of 10 tabs: the line paid 120 x 0.8 = 96 -> 38.40 back
    eq(38.4, $r['refund_amount']);
    eq(0, $r['vat_amount']);
    eq(r2(4 * 6.8), $r['cogs']);
    eq(594, stock('Losartan'));
    eq($lotBefore + 4, array_column(Batches::forItem(item_id('Losartan')), 'qty_on_hand', 'batch_no')['LS2411'], 'back into the lot it came from');
    eq(r2($before - 38.4), acct_balance('cash_on_hand'));
    eq(38.4, acct_balance('sales_returns'));
    $t = Tickets::get($t['id']);
    eq(4, line_of($t, 'Losartan')['returned_qty']);
    eq(38.4, $t['refunded_total']);
    throws(fn () => Tickets::void($t['id'], 'x'), 'has returns');
    // Damaged goods: not restocked, written off instead
    $w = acct_balance('wastage');
    $r2 = Returns::create($t['id'], [['id' => $box['id'], 'qty' => 1]], 'cash', 'Box crushed', false);
    eq(r2(500 / 1.12 * 0.8), $r2['refund_amount']);
    eq(795, stock('Biogesic'), 'not restocked');
    eq(r2($w + 100 * 3.2), acct_balance('wastage'));
    $GLOBALS['ret'] = $r;
    assert_books_balance();
});

test('void a return: refund reversed, stock out again; cashier needs a manager PIN', function () {
    $r = $GLOBALS['ret'];
    as_user('cashier1');
    throws(fn () => Returns::void($r['id'], 'oops'), 'Manager authorization');
    $v = Returns::void($r['id'], 'customer kept the tablets', '1234');
    as_user('admin');
    eq('void', $v['status']);
    eq(590, stock('Losartan'));
    eq(0, Tickets::get($r['ticket_id'])['items'][2]['returned_qty'] + line_of(Tickets::get($r['ticket_id']), 'Losartan')['returned_qty']);
    throws(fn () => Returns::void($r['id'], 'again'), 'already void');
    assert_books_balance();
});

test('end of day: blind count, cash short posted to GL, returns on the Z-reading', function () {
    throws(function () {
        $t = Tickets::create([]);
        Tickets::addItem($t['id'], item_id('Bioflu'), 1);
        try { CashSessions::close([1000 => 2]); } finally { Tickets::void($t['id'], 'test cleanup'); }
    }, 'held sale');
    $x = CashSessions::report((int) CashSessions::current()['id']);
    $t1 = $GLOBALS['t1'];
    $cashSales = r2((500 - $t1['change_amount']) - 200 + 84);   // sale 1 cash net of change + ballpens; the charge sale is not cash
    eq($cashSales, $x['cash']['cash_sales']);
    eq(1, $x['returns']['cnt'], 'the voided return no longer counts');
    eq(r2(500 / 1.12 * 0.8), $x['returns']['amount']);
    eq(r2(500 / 1.12 * 0.8), $x['cash']['refunds']);
    eq(r2(2000 + $cashSales - $x['cash']['refunds']), $x['cash']['expected']);
    eq(3, $x['sales']['cnt']);
    $z = CashSessions::close([1000 => 2, 100 => 2]);
    eq(2200, $z['cash']['counted']);
    eq(r2(2200 - $x['cash']['expected']), $z['cash']['variance']);
    eq('closed', $z['session']['status']);
    eq(r2($x['cash']['expected'] - 2200), acct_balance('cash_short_over'));
    assert_books_balance();
});

test('voiding a previous day receipt refunds from the new day drawer', function () {
    $prior = DB::one("SELECT id FROM tickets WHERE status = 'paid' AND customer_name IS NULL AND paid_total = 100 LIMIT 1");
    throws(fn () => Tickets::void((int) $prior['id'], 'late complaint'), 'Open the business day');
    $s = CashSessions::open(1000);
    $v = Tickets::void((int) $prior['id'], 'late complaint');
    eq('void', $v['status']);
    eq(100, stock('Ballpen'), 'pens back in stock');
    $x = CashSessions::report((int) $s['id']);
    eq(84, $x['cash']['refunds']);
    eq(1000 - 84, $x['cash']['expected']);
    assert_books_balance();
});

test('drawer payout during the day reduces expected cash', function () {
    $s = CashSessions::current();
    $before = CashSessions::report((int) $s['id'])['cash']['expected'];
    $acct = (int) DB::value("SELECT id FROM accounts WHERE code = '6400'");
    $id = PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 150, 'account_id' => $acct, 'description' => 'Delivery fare']);
    eq($before - 150, CashSessions::report((int) $s['id'])['cash']['expected']);
    eq(1, count(PettyCash::listForSession((int) $s['id'])));
    PettyCash::void($id, 'wrong amount');
    eq($before, CashSessions::report((int) $s['id'])['cash']['expected']);
    assert_books_balance();
});
