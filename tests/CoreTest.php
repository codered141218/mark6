<?php
use App\Core\DB;
use App\Services\Batches;
use App\Services\Inventory;
use App\Services\Items;
use App\Services\Ledger;
use App\Services\Sequence;
use App\Services\Settings;

test('sequence numbers are consecutive', function () {
    $a = Sequence::next('TST', 'TST', 4);
    $b = Sequence::next('TST', 'TST', 4);
    eq('TST-0001', $a);
    eq('TST-0002', $b);
});

test('ledger rejects unbalanced entries and reverses balanced ones', function () {
    throws(fn () => Ledger::post(today(), 'bad', [['key' => 'cash_on_hand', 'debit' => 10], ['key' => 'capital', 'credit' => 9]]), 'not balanced');
    $id = Ledger::post(today(), 'Owner investment', [['key' => 'cash_on_hand', 'debit' => 5000], ['key' => 'capital', 'credit' => 5000]]);
    eq(5000, acct_balance('cash_on_hand'));
    Ledger::reverse($id);
    eq(0, acct_balance('cash_on_hand'));
    assert_books_balance();
});

test('sample catalog installed: items, lots, opening stock and balanced books', function () {
    eq('pharmacy', Settings::get('business_type'));
    eq(900, stock('Biogesic'), '600 + 300 from two lots');
    $lots = lots('Biogesic');
    eq(2, count($lots));
    eq('BG2501B', $lots[0]['batch_no'], 'soonest expiry first (FEFO order)');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM inv_docs WHERE payment_mode = 'opening' AND status = 'posted'"));
    eq(acct_balance('inventory'), -acct_balance('opening_equity'), 'opening stock against opening balance equity');
    assert_books_balance();
});

test('unit conversions: global (g -> kg) and pack units (box = 100 tab)', function () {
    $biogesic = Inventory::item(item_id('Biogesic'));
    eq(100, Inventory::toBase($biogesic, 1, uom_id('box')));
    $units = array_column(Inventory::units(item_id('Biogesic')), null, 'abbr');
    eq(1, $units['tab']['factor']);
    eq(100, $units['box']['factor']);
    eq(500, $units['box']['price'], 'the box has its own selling price');
    eq('14800016101011', $units['box']['barcode']);
    $kgItem = Items::create(['name' => 'Rice (per kg)', 'item_type' => 'product', 'base_uom_id' => uom_id('kg'), 'price' => 52, 'plu' => '1001', 'sellable' => 1, 'active' => 1]);
    eq(0.25, Inventory::toBase(Inventory::item($kgItem), 250, uom_id('g')));
    throws(fn () => Inventory::toBase($biogesic, 1, uom_id('kg')), 'No conversion');
});

test('barcode lookup: item, pack, SKU and weighing-scale barcodes', function () {
    $r = Items::lookup('4800016101014');
    eq(item_id('Biogesic'), (int) $r['item']['id']);
    eq(null, $r['uom_id']);
    eq(5.5, $r['price']);
    $p = Items::lookup('14800016101011');
    eq(item_id('Biogesic'), (int) $p['item']['id']);
    eq(uom_id('box'), $p['uom_id']);
    eq(100, $p['factor']);
    eq(500, $p['price']);
    eq(item_id('Neozep'), (int) Items::lookup('PH0002')['item']['id'], 'SKU works too');
    eq(null, Items::lookup('nope-123'));
    // Scale barcode (EAN-13): prefix 2 + PLU 001001 + 01250 g + check digit
    eq(null, Items::lookup('2001001012507'), 'scale barcodes off until a prefix is set');
    Settings::set('scale_barcode_prefix', '2');
    $s = Items::lookup('2001001012507');
    eq('Rice (per kg)', $s['item']['name']);
    eq(1.25, $s['qty']);
    ok(!empty($s['scale']));
    Settings::set('scale_barcode_prefix', '');
});

test('sellable list has one entry per priced unit, with keys the POS uses', function () {
    $keys = array_column(Items::sellable(), null, 'key');
    $base = $keys[item_id('Biogesic') . ':0'];
    $box = $keys[item_id('Biogesic') . ':' . uom_id('box')];
    eq(5.5, $base['price']);
    eq(500, $box['price']);
    ok(!empty($box['pack']) && $box['factor'] == 100, 'pack entry');
    ok(!isset($keys[item_id('Enervon') . ':' . uom_id('box')]) || $keys[item_id('Enervon') . ':' . uom_id('box')]['price'] == 850, 'priced packs only');
    eq(1, $keys[item_id('Losartan') . ':0']['vat_exempt']);
    eq(1, $keys[item_id('Amoxicillin') . ':0']['rx_required']);
});

test('wholesale price from the minimum quantity, margin figures', function () {
    $id = Items::create(['name' => 'Bond Paper A4', 'item_type' => 'product', 'base_uom_id' => uom_id('pc'), 'price' => 1.5, 'wholesale_price' => 1.2, 'wholesale_min_qty' => 500, 'avg_cost' => 0.9, 'sellable' => 1, 'active' => 1]);
    $it = Inventory::item($id);
    eq(1.5, Items::effectivePrice($it, 100));
    eq(1.2, Items::effectivePrice($it, 500));
    throws(fn () => Items::create(['name' => 'Bad', 'item_type' => 'product', 'base_uom_id' => uom_id('pc'), 'price' => 10, 'wholesale_price' => 8]), 'minimum quantity');
    eq(r2((5.5 / 1.12 - 3.2) / (5.5 / 1.12) * 100), Items::marginPct(3.2, 5.5));
    eq(r2((12 - 6.8) / 12 * 100), Items::marginPct(6.8, 12, true), 'VAT-exempt item: price is already net');
    eq(null, Items::marginPct(1, 0));
});

test('moving average cost and FEFO lot deduction', function () {
    $id = item_id('Cotton Balls');
    Inventory::move(['item_id' => $id, 'qty' => 40, 'mtype' => 'RECEIVE', 'unit_cost' => 26]);
    eq(24, (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [$id]), '(40 x 22 + 40 x 26) / 80');
    eq(80, stock('Cotton Balls'));

    $bio = Inventory::item(item_id('Biogesic'));
    Inventory::move(['item_id' => $bio['id'], 'qty' => -310, 'mtype' => 'SALE', 'ref_type' => 'test', 'ref_id' => 1]);
    $lots = array_column(Batches::forItem((int) $bio['id'], false), 'qty_on_hand', 'batch_no');
    eq(0, $lots['BG2501B'], 'the lot expiring first is used up');
    eq(590, $lots['BG2409A']);
    eq(590, stock('Biogesic'));
    Inventory::move(['item_id' => $bio['id'], 'qty' => 310, 'mtype' => 'SALE_VOID', 'ref_type' => 'test', 'ref_id' => 1]);
    Batches::restore('test', 1);
    $lots = array_column(Batches::forItem((int) $bio['id']), 'qty_on_hand', 'batch_no');
    eq(300, $lots['BG2501B'], 'void puts the stock back into the same lots');
    eq(600, $lots['BG2409A']);
});

test('expiry watch: expired and expiring lots', function () {
    $rows = array_column(Batches::expiring(90), null, 'batch_no');
    eq('EXPIRED', $rows['LP2407']['status'], 'Loperamide lot expired 10 days ago');
    eq('EXPIRING', $rows['BG2501B']['status'], 'Biogesic lot expires in 25 days');
    ok(!isset($rows['BG2409A']), 'lots beyond the warning window are not listed');
    $a = Batches::alerts(90);
    eq(1, $a['expired_lots']);
    eq(r2(150 * 4.2), $a['expired_value']);
    ok($a['expiring_lots'] >= 2, 'Biogesic + Amoxicillin lots within 90 days');
});
