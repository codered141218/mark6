<?php
use App\Core\DB;
use App\Services\Discounts;
use App\Services\Migrations;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;

as_user('admin');
CashSessions::open(1000);
$preset = fn (string $name) => (int) DB::value('SELECT id FROM discounts WHERE name = ?', [$name]);
$line = fn (array $t, string $item) => line_of($t, $item)['id'];

test('default retail discount presets are installed', function () {
    $names = array_column(Discounts::all(true), 'name');
    foreach (['Senior Citizen', 'PWD', 'Senior 5% (basic goods)', 'Employee 10%', 'Promo 5%', 'Damaged pack 20%', 'Open discount %', 'Open discount ₱'] as $n) ok(in_array($n, $names, true), "missing $n");
});

test('SC tagged on the senior’s own medicine + employee 10% on another item', function () use ($preset, $line) {
    $t = Tickets::create([]);
    Tickets::addItem($t['id'], item_id('Biogesic'), 10);       // 55.00, VATable
    $t = Tickets::addItem($t['id'], item_id('Enervon'), 10);   // 90.00, VATable
    throws(fn () => Tickets::discountLine($t['id'], $line($t, 'Biogesic'), ['discount_id' => $preset('Senior Citizen')]), 'name and ID');
    $t = Tickets::discountLine($t['id'], $line($t, 'Biogesic'), ['discount_id' => $preset('Senior Citizen'), 'sc_person' => ['name' => 'Lola Nena', 'id_no' => 'OSCA-1']]);
    // 55 becomes VAT-exempt: 55 / 1.12 = 49.11, 20% = 9.82; Enervon stays VAT-able
    eq(49.11, $t['vat_exempt_sales']);
    eq(9.82, $t['sc_discount']);
    eq(r2(90 - 90 / 1.12), $t['vat_amount']);
    eq(r2(49.11 - 9.82 + 90), $t['total']);
    $t = Tickets::discountLine($t['id'], $line($t, 'Enervon'), ['discount_id' => $preset('Employee 10%')]);
    eq(9, $t['promo_discount']);
    eq(18.82, $t['discount_amount']);
    eq(r2(81 - 81 / 1.12), $t['vat_amount']);
    eq(r2(39.29 + 81), $t['total']);
    $amounts = array_column(array_filter($t['items'], fn ($i) => $i['discount_kind']), 'discount_amount');
    eq(18.82, array_sum($amounts), 'line discounts add up to the receipt discount');
    eq('Lola Nena', $t['sc_details'][0]['name']);

    // A whole-receipt SC discount cannot be stacked on item discounts
    throws(fn () => Tickets::discount($t['id'], ['discount_id' => $preset('Senior Citizen'), 'sc_details' => [['name' => 'X', 'id_no' => '1']]]), 'Remove the item discounts');
    $paid = Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 200]]);
    eq(r2(200 - 120.29), $paid['change_amount']);
    assert_books_balance();
    // Sales discounts booked net of VAT: 9.82 + 9 / 1.12 = 17.86
    eq(r2(9.82 + 9 / 1.12), acct_balance('sales_discounts'));
});

test('receipt-level promo applies after item discounts; removing the SC item forgets the names', function () use ($preset, $line) {
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], item_id('Betadine'), 2);    // 190
    $l = $line($t, 'Betadine');
    Tickets::discountLine($t['id'], $l, ['discount_id' => $preset('Open discount ₱'), 'value' => 40]);   // 150 left
    $t = Tickets::discount($t['id'], ['discount_id' => $preset('Open discount %'), 'value' => 10]);       // 10% of 150
    eq(55, $t['promo_discount']);
    eq(135, $t['total']);
    eq('Open discount %', $t['discount_name']);
    $t = Tickets::discountLine($t['id'], $l, ['discount_id' => $preset('Senior Citizen'), 'sc_person' => ['name' => 'Lolo Ben', 'id_no' => 'OSCA-2']]);
    ok(count($t['sc_details']) === 1, 'SC person stored');
    $t = Tickets::discountLine($t['id'], $l, ['discount_type' => 'none']);
    eq([], $t['sc_details']);
    Tickets::void($t['id'], 'test');
});

test('preset scope, open value and approval rules', function () use ($preset, $line) {
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], item_id('Colgate'), 1);
    throws(fn () => Tickets::discount($t['id'], ['discount_id' => $preset('Damaged pack 20%')]), 'single items');
    throws(fn () => Tickets::discountLine($t['id'], $line($t, 'Colgate'), ['discount_id' => $preset('Open discount %')]), 'Enter the discount value');
    // A cashier needs a manager PIN for presets that require approval, but not for ones that don't
    make_user('cash2', 'Cashier');
    as_user('cash2');
    throws(fn () => Tickets::discountLine($t['id'], $line($t, 'Colgate'), ['discount_id' => $preset('Damaged pack 20%')]), 'Manager authorization');
    $t2 = Tickets::discountLine($t['id'], $line($t, 'Colgate'), ['discount_id' => $preset('Promo 5%')]);
    eq(r2(98 * 0.95), $t2['total']);
    $t3 = Tickets::discountLine($t['id'], $line($t, 'Colgate'), ['discount_id' => $preset('Damaged pack 20%')], '1234');
    eq('Damaged pack 20%', $t3['items'][0]['discount_name']);
    eq(r2(98 * 0.8), $t3['total']);
    as_user('admin');
    Tickets::void($t['id'], 'test');
});

test('5% senior discount on basic goods does not make the sale VAT-exempt', function () use ($preset) {
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], item_id('Safeguard'), 2);   // 104
    $t = Tickets::discount($t['id'], ['discount_id' => $preset('Senior 5% (basic goods)')]);
    eq(5.2, $t['discount_amount']);
    eq(0, $t['vat_exempt_sales']);
    eq(r2(98.8 - 98.8 / 1.12), $t['vat_amount']);
    eq(98.8, $t['total']);
    Tickets::void($t['id'], 'test');
});

test('Z-reading lists discounts by name', function () {
    $z = CashSessions::report((int) CashSessions::current()['id']);
    $labels = array_column($z['discounts'], 'amount', 'label');
    eq(9.82, $labels['Senior Citizen']);
    eq(9, $labels['Employee 10%']);
    assert_books_balance();
});

test('database upgrade steps can run again safely', function () {
    App\Services\Settings::set('db_version', 0);
    Migrations::run();
    eq(Migrations::latest(), Migrations::current());
    ok(DB::columnExists('ticket_items', 'discount_kind'));
    eq(8, (int) DB::value('SELECT COUNT(*) FROM discounts'), 'presets not duplicated');
});

test('discount book lists item discounts with the SC/PWD names', function () {
    $rows = App\Services\Reports\SalesReports::discounts('2000-01-01', '2100-01-01');
    eq(1, count($rows), 'one paid receipt with discounts');
    ok(str_contains($rows[0]['discount_type'], 'Senior Citizen (Biogesic 500mg)'), $rows[0]['discount_type']);
    ok(str_contains($rows[0]['discount_type'], 'Employee 10% (Enervon)'), $rows[0]['discount_type']);
    eq('Lola Nena', $rows[0]['names']);
    eq(9.82, $rows[0]['sc_discount']);
    eq(9, $rows[0]['promo_discount']);
});
