<?php
use App\Core\DB;
use App\Services\Batches;
use App\Services\Inventory;
use App\Services\InventoryCounts;
use App\Services\InventoryDocs;
use App\Services\Items;
use App\Services\Ledger;
use App\Services\Reports\InventoryReports;

$avg = fn (string $name) => (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [item_id($name)]);
$supplier = (int) DB::value("SELECT id FROM suppliers WHERE name = 'Zuellig Pharma Corp.'");   // 30-day terms
$inv0 = acct_balance('inventory');

test('purchase on credit: stock, lots, moving average, A/P bill with due date, balanced GL', function () use ($avg, $supplier, $inv0) {
    $id = InventoryDocs::create('RECEIVE', [
        'doc_date' => '2026-10-01', 'supplier_id' => $supplier, 'invoice_no' => 'SI-1001', 'payment_mode' => 'credit', 'post' => 1,
        'lines' => [
            ['item_id' => item_id('Biogesic'), 'qty' => 10, 'uom_id' => uom_id('box'), 'unit_cost' => 300, 'batch_no' => 'BG2603', 'expiry_date' => '2028-03-31'],
            ['item_id' => item_id('Losartan'), 'qty' => 400, 'line_total' => 2800, 'batch_no' => 'LS2412', 'expiry_date' => '2027-12-31'],
            ['item_id' => item_id('Cotton Balls'), 'qty' => 40, 'unit_cost' => 24],
        ],
    ]);
    $d = InventoryDocs::find($id);
    eq('posted', $d['status']);
    ok(str_starts_with($d['doc_no'], 'RR-'), 'RR- document number');
    eq(1900, stock('Biogesic'), '900 + 10 boxes x 100');
    eq(r4((900 * 3.2 + 3000) / 1900), $avg('Biogesic'), 'moving average, box cost spread per tab');
    eq(1000, stock('Losartan'));
    eq(6.88, $avg('Losartan'), '(600 x 6.8 + 2800) / 1000');
    $lots = array_column(lots('Biogesic'), null, 'batch_no');
    eq(1000, $lots['BG2603']['qty_on_hand']);
    eq('2028-03-31', $lots['BG2603']['expiry_date']);
    eq(3, (float) $lots['BG2603']['unit_cost']);
    eq(0, count(lots('Cotton Balls')), 'items without lot tracking have no lots');
    $bill = DB::one('SELECT * FROM ap_bills WHERE id = ?', [$d['ap_bill_id']]);
    eq(3000 + 2800 + 960, (float) $bill['amount']);
    eq('2026-10-31', $bill['due_date'], 'due = date + 30 days terms');
    eq('inv_receive', $bill['source_type']);
    eq(r2($inv0 + 6760), acct_balance('inventory'));
    eq(-6760, acct_balance('ap'));
    eq(0, acct_balance('input_vat'));
    assert_books_balance();
});

test('VAT-inclusive cash purchase splits input VAT', function () use ($avg) {
    $before = acct_balance('inventory');
    $id = InventoryDocs::create('RECEIVE', [
        'payment_mode' => 'cash', 'vat_inclusive' => 1, 'post' => 1,
        'lines' => [['item_id' => item_id('Face Mask'), 'qty' => 1000, 'line_total' => 1344]],
    ]);
    eq(null, InventoryDocs::find($id)['ap_bill_id']);
    eq(144, acct_balance('input_vat'));
    eq(-1344, acct_balance('cash_on_hand'));
    eq(r4((1500 * 1.2 + 1200) / 2500), $avg('Face Mask'), 'cost net of VAT');
    eq(r2($before + 1200), acct_balance('inventory'));
    assert_books_balance();
});

test('issuance for store use charges the expense account at average cost', function () {
    $before = acct_balance('inventory');
    $id = InventoryDocs::create('ISSUE', [
        'issued_to' => 'Store use / supplies', 'post' => 1,
        'lines' => [['item_id' => item_id('Isopropyl Alcohol'), 'qty' => 2], ['item_id' => item_id('Face Mask'), 'qty' => 1, 'uom_id' => uom_id('box')]],
    ]);
    $d = InventoryDocs::find($id);
    ok(str_starts_with($d['doc_no'], 'IS-'), 'IS- number');
    eq(Ledger::account('supplies'), (int) $d['expense_account_id'], 'default expense account');
    eq(46, stock('Isopropyl Alcohol'));
    eq(2450, stock('Face Mask'), '2500 - 1 box of 50');
    $cost = r2(2 * 55 + 50 * (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [item_id('Face Mask')]));
    eq($cost, (float) $d['total_cost']);
    eq($cost, acct_balance('supplies'));
    eq(r2($before - $cost), acct_balance('inventory'));
    throws(fn () => InventoryDocs::create('ISSUE', ['post' => 1, 'lines' => [['item_id' => item_id('Delivery Fee'), 'qty' => 1]]]), 'not stocked');
    assert_books_balance();
});

test('write-off of an expired lot books Shrinkage, Damage & Expiry and empties that lot', function () {
    $expired = lots('Loperamide')[0];
    eq('LP2407', $expired['batch_no']);
    $id = InventoryDocs::create('WASTE', [
        'reason' => 'expired', 'post' => 1,
        'lines' => [['item_id' => item_id('Loperamide'), 'qty' => 150, 'batch_id' => $expired['id']], ['item_id' => item_id('Biogesic'), 'qty' => 20]],
    ]);
    $d = InventoryDocs::find($id);
    ok(str_starts_with($d['doc_no'], 'WS-'), 'WS- number');
    eq(0, stock('Loperamide'));
    eq([], lots('Loperamide'), 'no lot with stock left');
    eq(1880, stock('Biogesic'));
    eq(280, array_column(lots('Biogesic'), 'qty_on_hand', 'batch_no')['BG2501B'], 'FEFO: 20 tabs taken from the soonest-expiring lot');
    $cost = r2(150 * 4.2 + 20 * (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [item_id('Biogesic')]));
    eq($cost, (float) $d['total_cost']);
    eq($cost, acct_balance('wastage'));
    throws(fn () => InventoryDocs::create('WASTE', ['reason' => 'stolen', 'lines' => [['item_id' => item_id('Bioflu'), 'qty' => 1]]]), 'reason');
    eq(0, count(array_filter(Batches::expiring(90), fn ($r) => $r['status'] === 'EXPIRED')), 'expiry watch is clear after the write-off');
    assert_books_balance();
});

test('draft documents: validation, edit, delete; posted ones are locked', function () use ($supplier) {
    throws(fn () => InventoryDocs::create('RECEIVE', ['payment_mode' => 'credit', 'lines' => [['item_id' => item_id('Bioflu'), 'qty' => 1]]]), 'Supplier is required');
    throws(fn () => InventoryDocs::create('RECEIVE', ['payment_mode' => 'cash', 'lines' => [['item_id' => item_id('Blood Pressure'), 'qty' => 1]]]), 'service');
    throws(fn () => InventoryDocs::create('RECEIVE', ['payment_mode' => 'cash', 'lines' => [['item_id' => item_id('Bioflu'), 'qty' => 1, 'unit_cost' => 1, 'expiry_date' => '31/12/2027']]]), 'Invalid expiry');
    throws(fn () => InventoryDocs::create('ISSUE', ['lines' => [['item_id' => item_id('Bioflu'), 'qty' => 0]]]), 'greater than zero');
    throws(fn () => InventoryDocs::create('ISSUE', ['lines' => []]), 'at least one item');

    $id = InventoryDocs::create('RECEIVE', ['supplier_id' => $supplier, 'lines' => [['item_id' => item_id('Bioflu'), 'qty' => 100, 'unit_cost' => 4.8, 'batch_no' => 'BF2603', 'expiry_date' => '2028-01-31']]]);
    $d = InventoryDocs::find($id);
    eq('draft', $d['status']);
    eq(480, (float) $d['total_cost']);
    eq('BF2603', $d['lines'][0]['batch_no']);
    eq(300, stock('Bioflu'), 'drafts do not move stock');
    InventoryDocs::update($id, ['supplier_id' => $supplier, 'lines' => [['item_id' => item_id('Bioflu'), 'qty' => 120, 'unit_cost' => 4.8]]]);
    eq(576, (float) InventoryDocs::find($id)['total_cost']);
    InventoryDocs::delete($id);
    throws(fn () => InventoryDocs::find($id), 'not found');

    $posted = (int) DB::value("SELECT id FROM inv_docs WHERE status = 'posted' ORDER BY id LIMIT 1");
    throws(fn () => InventoryDocs::update($posted, ['lines' => []]), 'Only draft');
    throws(fn () => InventoryDocs::delete($posted), 'voided');
});

test('only users with inventory.post can post', function () {
    make_user('clerk', 'Stock Clerk');
    as_user('clerk');
    try {
        $id = InventoryDocs::create('WASTE', ['lines' => [['item_id' => item_id('Bioflu'), 'qty' => 1]]]);
        throws(fn () => InventoryDocs::post($id), 'permission');
        eq('draft', InventoryDocs::find($id)['status']);
        InventoryDocs::delete($id);
    } finally {
        as_user('admin');
    }
});

test('voiding a purchase restores stock, lots and average cost, and voids the payable', function () use ($avg, $supplier) {
    $stock = stock('Cetirizine');
    $cost = $avg('Cetirizine');
    $inv = acct_balance('inventory');
    $ap = acct_balance('ap');
    $id = InventoryDocs::create('RECEIVE', ['supplier_id' => $supplier, 'post' => 1, 'lines' => [['item_id' => item_id('Cetirizine'), 'qty' => 100, 'line_total' => 600, 'batch_no' => 'CT2606', 'expiry_date' => '2028-06-30']]]);
    eq($stock + 100, stock('Cetirizine'));
    eq(2, count(lots('Cetirizine')));
    ok(abs($avg('Cetirizine') - $cost) > 0.1, 'average moved after the purchase');
    throws(fn () => InventoryDocs::void($id, ''), 'reason');

    InventoryDocs::void($id, 'Wrong supplier');
    $d = InventoryDocs::find($id);
    eq('cancelled', $d['status']);
    eq($stock, stock('Cetirizine'));
    eq(1, count(lots('Cetirizine')), 'the new lot is empty again');
    ok(abs($cost - $avg('Cetirizine')) < 0.001, 'average cost restored');
    eq('void', DB::value('SELECT status FROM ap_bills WHERE id = ?', [$d['ap_bill_id']]));
    eq($inv, acct_balance('inventory'));
    eq($ap, acct_balance('ap'));
    throws(fn () => InventoryDocs::void($id, 'again'), 'Only posted');
    assert_books_balance();
});

test('voiding is refused when the supplier bill has payments', function () use ($supplier) {
    $id = InventoryDocs::create('RECEIVE', ['supplier_id' => $supplier, 'post' => 1, 'lines' => [['item_id' => item_id('Bioflu'), 'qty' => 30, 'line_total' => 150]]]);
    $billId = (int) InventoryDocs::find($id)['ap_bill_id'];
    DB::run("UPDATE ap_bills SET paid_amount = 100, status = 'partial' WHERE id = ?", [$billId]);
    $before = stock('Bioflu');
    throws(fn () => InventoryDocs::void($id, 'Damaged'), 'already has payments');
    eq('posted', InventoryDocs::find($id)['status']);
    eq($before, stock('Bioflu'), 'stock untouched');
    assert_books_balance();
});

test('voiding an issuance puts the stock back', function () {
    $before = stock('Isopropyl Alcohol');
    $supplies = acct_balance('supplies');
    $id = InventoryDocs::create('ISSUE', ['post' => 1, 'lines' => [['item_id' => item_id('Isopropyl Alcohol'), 'qty' => 4]]]);
    eq($before - 4, stock('Isopropyl Alcohol'));
    InventoryDocs::void($id, 'Returned to the shelf');
    eq($before, stock('Isopropyl Alcohol'));
    eq($supplies, acct_balance('supplies'));
    assert_books_balance();
});

test('count posting sets stock to counted and books the variance', function () use ($avg) {
    $id = InventoryCounts::start(null, null, 'Month-end');
    $s = InventoryCounts::find($id);
    ok(str_starts_with($s['doc_no'], 'CNT-'), 'CNT- number');
    eq((int) DB::value("SELECT COUNT(*) FROM items WHERE active = 1 AND item_type = 'product'"), count($s['lines']));
    $line = fn ($name) => (int) current(array_filter($s['lines'], fn ($l) => (int) $l['item_id'] === item_id($name)))['id'];

    $betadineCost = $avg('Betadine');
    $soap = stock('Safeguard');
    $bioflu = stock('Bioflu');
    InventoryCounts::save($id, [$line('Betadine') => 22, $line('Safeguard') => $soap + 5, $line('Bioflu') => '']);
    $variance = acct_balance('inv_variance');
    $inv = acct_balance('inventory');
    InventoryCounts::post($id);

    $s = InventoryCounts::find($id);
    eq('posted', $s['status']);
    eq(22, stock('Betadine'));
    eq($soap + 5, stock('Safeguard'));
    eq($bioflu, stock('Bioflu'), 'blank line not adjusted');
    $loss = r2(2 * $betadineCost);
    $gain = r2(5 * $avg('Safeguard'));
    eq(r2($gain - $loss), (float) $s['total_variance_value']);
    eq(r2($variance + $loss - $gain), acct_balance('inv_variance'));
    eq(r2($inv + $gain - $loss), acct_balance('inventory'));
    eq(2, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE ref_type = 'count' AND ref_id = ?", [$id]));
    eq(22, array_sum(array_column(lots('Betadine'), 'qty_on_hand')), 'lots follow the count');
    throws(fn () => InventoryCounts::post($id), 'already closed');
    assert_books_balance();
});

test('count sessions: category scope, nothing counted, cancel', function () {
    $cat = (int) DB::value("SELECT id FROM categories WHERE name = 'Medical Supplies'");
    $id = InventoryCounts::start(today(), $cat, null);
    eq((int) DB::value("SELECT COUNT(*) FROM items WHERE active = 1 AND category_id = ? AND item_type = 'product'", [$cat]), count(InventoryCounts::find($id)['lines']));
    throws(fn () => InventoryCounts::post($id), 'at least one counted');
    InventoryCounts::cancel($id);
    eq('cancelled', InventoryCounts::find($id)['status']);
    throws(fn () => InventoryCounts::save($id, []), 'closed');
});

test('item master: auto SKU, pack units with prices and barcodes, barcode / PLU validation', function () {
    $id = Items::create([
        'name' => 'Vitamin C 500mg', 'generic_name' => 'Ascorbic acid', 'brand' => 'Poten-Cee', 'item_type' => 'product', 'base_uom_id' => uom_id('tab'),
        'price' => 5, 'avg_cost' => 2.8, 'barcode' => '4800000000017', 'sellable' => 1, 'active' => 1, 'track_batches' => 1,
        'uoms' => [['uom_id' => uom_id('box'), 'factor' => 100, 'price' => 450, 'barcode' => '14800000000014'], ['uom_id' => '', 'factor' => '']],
    ]);
    $item = Inventory::item($id);
    ok(preg_match('/^SKU-\d{5}$/', $item['sku']) === 1, 'auto SKU ' . $item['sku']);
    eq(1, (int) DB::value('SELECT COUNT(*) FROM item_uoms WHERE item_id = ?', [$id]));
    eq(450, Items::lookup('14800000000014')['price']);
    throws(fn () => Items::create(['name' => 'Dup', 'sku' => $item['sku'], 'item_type' => 'product', 'base_uom_id' => uom_id('tab')]), 'SKU already exists');
    throws(fn () => Items::create(['name' => 'Dup barcode', 'item_type' => 'product', 'base_uom_id' => uom_id('tab'), 'barcode' => '14800000000014']), 'Barcode');
    throws(fn () => Items::create(['name' => 'Bad PLU', 'item_type' => 'product', 'base_uom_id' => uom_id('tab'), 'plu' => 'AB12']), 'PLU');
    throws(fn () => Items::update($id, ['name' => 'Vitamin C 500mg', 'item_type' => 'product', 'base_uom_id' => uom_id('tab'), 'active' => 1,
        'uoms' => [['uom_id' => uom_id('tab'), 'factor' => 2]]]), 'same as the base unit');
    throws(fn () => Items::update($id, ['name' => 'Vitamin C 500mg', 'item_type' => 'product', 'base_uom_id' => uom_id('tab'), 'active' => 1,
        'uoms' => [['uom_id' => uom_id('box'), 'factor' => 100, 'barcode' => '4800016101014']]]), 'Barcode');
});

test('item rules: type and base unit locks, opening cost, delete vs deactivate', function () {
    $bio = Inventory::item(item_id('Bioflu'));
    $base = ['name' => $bio['name'], 'item_type' => 'product', 'base_uom_id' => $bio['base_uom_id'], 'active' => 1];
    throws(fn () => Items::update((int) $bio['id'], ['item_type' => 'service'] + $base), 'stock on hand');
    throws(fn () => Items::update((int) $bio['id'], ['base_uom_id' => uom_id('box')] + $base), 'base unit');
    Items::update((int) $bio['id'], $base + ['avg_cost' => 999, 'uoms' => [['uom_id' => uom_id('box'), 'factor' => 100]]]);
    ok((float) Inventory::item((int) $bio['id'])['avg_cost'] < 999, 'cost not overwritten once there is stock history');
    eq(100, Inventory::toBase($bio, 1, uom_id('box')));

    $new = Items::create(['name' => 'Hand Sanitizer 60ml', 'item_type' => 'product', 'base_uom_id' => uom_id('btl'), 'avg_cost' => 30, 'price' => 49, 'active' => 1]);
    eq(30, (float) Inventory::item($new)['avg_cost']);
    Items::update($new, ['name' => 'Hand Sanitizer 60ml', 'item_type' => 'product', 'base_uom_id' => uom_id('pc'), 'avg_cost' => 32, 'price' => 49, 'active' => 1]);
    eq(32, (float) Inventory::item($new)['avg_cost'], 'cost editable without history');
    ok(Items::delete($new), 'unused item is deleted');
    ok(!DB::value('SELECT id FROM items WHERE id = ?', [$new]), 'gone');
    ok(!Items::delete((int) $bio['id']), 'item with history is deactivated');
    eq(0, (int) Inventory::item((int) $bio['id'])['active']);
    DB::run('UPDATE items SET active = 1 WHERE id = ?', [$bio['id']]);
});

test('turning on lot tracking for an item with stock creates an opening lot', function () {
    $soap = Inventory::item(item_id('Safeguard'));
    eq(0, (int) $soap['track_batches']);
    Items::update((int) $soap['id'], ['name' => $soap['name'], 'item_type' => 'product', 'base_uom_id' => $soap['base_uom_id'], 'price' => $soap['price'], 'active' => 1, 'sellable' => 1, 'track_batches' => 1]);
    $l = lots('Safeguard');
    eq(1, count($l));
    eq(stock('Safeguard'), (float) $l[0]['qty_on_hand']);
});

test('inventory reports: on hand, reorder, margins, batches, slow movers, documents with lots', function () {
    $onhand = array_column(InventoryReports::onHand(), null, 'item');
    eq('REORDER', $onhand['Loperamide 2mg']['status'], 'written off below the reorder point');
    eq(r2(1880 * (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [item_id('Biogesic')])), $onhand['Biogesic 500mg']['value']);
    eq(r2(1880 * 5.5), $onhand['Biogesic 500mg']['retail_value']);
    $re = array_column(InventoryReports::reorder(), null, 'item');
    eq(200, $re['Loperamide 2mg']['suggested_order']);
    $m = array_column(InventoryReports::margins(), null, 'item');
    eq(r2(12 - 6.88), $m['Losartan 50mg']['margin'], 'VAT-exempt: price is net');
    eq(r2(5.5 / 1.12), $m['Biogesic 500mg']['price_net_of_vat']);
    ok(count(InventoryReports::batches()) > 10, 'every lot with stock');
    ok(in_array('Biogesic 500mg', array_column(InventoryReports::slowMovers('2026-01-01', '2026-01-31'), 'item'), true), 'no sales in January');
    $docs = InventoryReports::documents('RECEIVE', '2026-10-01', '2026-10-01');
    eq('BG2603', $docs[0]['batch_no']);
    $w = InventoryReports::documents('WASTE', today(), today());
    eq('LP2407', $w[0]['batch_no'], 'lot of the written-off line');
    $abbrs = array_column(Inventory::units(item_id('Bioflu')), 'factor', 'abbr');
    eq(1, $abbrs['tab']);
    eq(100, $abbrs['box']);
    assert_books_balance();
});
