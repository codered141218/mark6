<?php
namespace App\Services;

use App\Core\DB;
use App\Services\Finance\Partners;

/** Creates the default data for a new installation, and optional sample catalogs. Safe to run more than once. */
class Seeder
{
    public static function base(string $adminPassword = 'admin123', string $adminPin = '1234'): void
    {
        DB::transaction(function () use ($adminPassword, $adminPin) {
            foreach (Settings::DEFAULTS as $k => $v) {
                if (DB::value('SELECT COUNT(*) FROM settings WHERE "key" = ?', [$k]) == 0) Settings::set($k, $v);
            }
            Ledger::seedAccounts();

            foreach (Permissions::defaultRoles() as $r) {
                if (!DB::value('SELECT id FROM roles WHERE name = ?', [$r['name']])) {
                    DB::insert('roles', ['name' => $r['name'], 'description' => $r['description'],
                        'permissions' => json_encode($r['permissions']), 'is_system' => $r['is_system'] ?? 0]);
                }
            }
            if (!DB::value('SELECT id FROM users LIMIT 1')) {
                DB::insert('users', [
                    'username' => 'admin', 'full_name' => 'System Administrator',
                    'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT), 'pin_hash' => password_hash($adminPin, PASSWORD_DEFAULT),
                    'role_id' => (int) DB::value("SELECT id FROM roles WHERE name = 'Administrator'"), 'active' => 1, 'created_at' => now(),
                ]);
            }

            $uoms = [['Piece', 'pc'], ['Tablet', 'tab'], ['Capsule', 'cap'], ['Sachet', 'sachet'], ['Ampule', 'amp'], ['Vial', 'vial'], ['Bottle', 'btl'],
                ['Box', 'box'], ['Pack', 'pack'], ['Can', 'can'], ['Case', 'case'], ['Sack', 'sack'], ['Bag', 'bag'], ['Dozen', 'doz'], ['Set', 'set'], ['Pair', 'pair'],
                ['Roll', 'roll'], ['Kilogram', 'kg'], ['Gram', 'g'], ['Liter', 'L'], ['Milliliter', 'ml'], ['Gallon', 'gal'], ['Meter', 'm'], ['Foot', 'ft'], ['Sheet', 'sheet'], ['Tray', 'tray']];
            foreach ($uoms as [$name, $abbr]) {
                if (!DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr])) DB::insert('uoms', ['name' => $name, 'abbr' => $abbr]);
            }
            foreach ([['kg', 'g', 1000], ['L', 'ml', 1000], ['gal', 'L', 3.785], ['doz', 'pc', 12], ['m', 'ft', 3.2808]] as [$f, $t, $factor]) {
                $from = self::uom($f); $to = self::uom($t);
                DB::run('INSERT INTO uom_conversions (from_uom_id, to_uom_id, factor) VALUES (?, ?, ?) ON CONFLICT (from_uom_id, to_uom_id) DO NOTHING', [$from, $to, $factor]);
            }
        });
    }

    private static function uom(string $abbr): int
    {
        return (int) DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr]);
    }

    /**
     * Sample catalog for a business type (pharmacy | grocery | hardware): categories, items with pack units and barcodes,
     * suppliers, customers, employees and an opening stock-in document (lot numbers and expiry dates for lot-tracked items).
     */
    public static function sample(string $kind): void
    {
        if (DB::value('SELECT id FROM items LIMIT 1')) return;
        $data = match ($kind) {
            'pharmacy' => self::pharmacy(),
            'grocery' => self::grocery(),
            'hardware' => self::hardware(),
            default => null,
        };
        if (!$data) return;
        DB::transaction(function () use ($data, $kind) {
            foreach ($data['settings'] as $k => $v) Settings::set($k, $v);
            $cats = [];
            foreach ($data['categories'] as $i => [$key, $name, $color]) {
                $cats[$key] = DB::insert('categories', ['name' => $name, 'color' => $color, 'sort_order' => $i + 1, 'active' => 1]);
            }
            $suppliers = [];
            foreach ($data['suppliers'] as [$name, $terms]) $suppliers[] = Partners::save('suppliers', ['name' => $name, 'terms_days' => $terms]);
            foreach ($data['customers'] as [$name, $terms, $limit]) Partners::save('customers', ['name' => $name, 'terms_days' => $terms, 'credit_limit' => $limit]);
            foreach ($data['employees'] as $i => [$name, $position]) {
                DB::insert('employees', ['emp_no' => Sequence::next('EMP', 'EMP', 3), 'full_name' => $name, 'position' => $position, 'department' => 'Store', 'active' => 1, 'date_hired' => add_days(today(), -400 + $i * 100)]);
            }

            $n = 1;
            $opening = [];
            foreach ($data['items'] as $it) {
                $uoms = [];
                foreach ($it['packs'] ?? [] as [$abbr, $factor, $price, $barcode]) $uoms[] = ['uom_id' => self::uom($abbr), 'factor' => $factor, 'price' => $price, 'barcode' => $barcode];
                $id = Items::create([
                    'sku' => sprintf('%s%04d', $data['sku_prefix'], $n++), 'name' => $it['name'], 'generic_name' => $it['generic'] ?? null, 'brand' => $it['brand'] ?? null,
                    'category_id' => $cats[$it['cat']], 'item_type' => $it['type'] ?? 'product', 'base_uom_id' => self::uom($it['uom']),
                    'price' => $it['price'], 'wholesale_price' => $it['wprice'] ?? null, 'wholesale_min_qty' => $it['wmin'] ?? null, 'avg_cost' => $it['cost'] ?? 0,
                    'reorder_point' => $it['rop'] ?? 0, 'reorder_qty' => $it['roq'] ?? 0, 'sellable' => 1, 'active' => 1,
                    'vat_exempt' => $it['exempt'] ?? 0, 'rx_required' => $it['rx'] ?? 0, 'track_batches' => $it['lots'] ?? 0,
                    'barcode' => $it['barcode'] ?? null, 'plu' => $it['plu'] ?? null, 'uoms' => $uoms,
                ]);
                foreach ($it['stock'] ?? [] as [$qty, $batchNo, $expiryDays]) {
                    $opening[] = ['item_id' => $id, 'qty' => $qty, 'unit_cost' => $it['cost'] ?? 0, 'batch_no' => $batchNo, 'expiry_date' => $expiryDays === null ? '' : add_days(today(), $expiryDays)];
                }
            }
            Sequence::bump('SKU', 'SKU', 5, 1);
            if ($opening) {
                InventoryDocs::create('RECEIVE', ['doc_date' => today(), 'payment_mode' => 'opening', 'supplier_id' => $suppliers[0] ?? null,
                    'notes' => 'Opening stock (sample data)', 'post' => 1, 'lines' => $opening]);
            }
            Audit::log('seed', 'sample', null, $kind);
        });
    }

    // ------------------------------------------------------------------ sample catalogs
    private static function pharmacy(): array
    {
        $rx = ['rx' => 1, 'lots' => 1];
        $otc = ['lots' => 1];
        return [
            'sku_prefix' => 'PH', 'settings' => ['business_name' => 'Botika ni Aling Nena', 'business_type' => 'pharmacy', 'receipt_title' => 'SALES INVOICE'],
            'categories' => [['rx', 'Prescription Medicines', '#b91c1c'], ['otc', 'OTC Medicines', '#1d4ed8'], ['vit', 'Vitamins & Supplements', '#15803d'],
                ['care', 'Personal Care', '#7c3aed'], ['supplies', 'Medical Supplies', '#0f766e'], ['baby', 'Baby Care', '#db2777'], ['svc', 'Services', '#6b7280']],
            'suppliers' => [['Zuellig Pharma Corp.', 30], ['Metro Drug Distribution', 15], ['Unilab Distributor (Mercury Drug Dist.)', 30]],
            'customers' => [['Barangay Health Center (charge account)', 30, 20000], ['Dr. Reyes Clinic', 15, 10000]],
            'employees' => [['Maria Santos', 'Pharmacist'], ['Juan Dela Cruz', 'Pharmacy Assistant']],
            'items' => [
                ['name' => 'Biogesic 500mg', 'generic' => 'Paracetamol', 'brand' => 'Unilab', 'cat' => 'otc', 'uom' => 'tab', 'price' => 5.50, 'cost' => 3.20, 'rop' => 200, 'roq' => 1000,
                    'barcode' => '4800016101014', 'packs' => [['box', 100, 500.00, '14800016101011']], 'stock' => [[600, 'BG2409A', 540], [300, 'BG2501B', 25]]] + $otc,
                ['name' => 'Neozep Forte', 'generic' => 'Phenylephrine + Chlorphenamine + Paracetamol', 'brand' => 'Unilab', 'cat' => 'otc', 'uom' => 'tab', 'price' => 7.00, 'cost' => 4.10, 'rop' => 100, 'roq' => 500,
                    'barcode' => '4800016102028', 'packs' => [['box', 100, 650.00, null]], 'stock' => [[400, 'NZ2410', 420]]] + $otc,
                ['name' => 'Bioflu', 'generic' => 'Phenylephrine + Chlorphenamine + Paracetamol', 'brand' => 'Unilab', 'cat' => 'otc', 'uom' => 'tab', 'price' => 8.00, 'cost' => 4.80, 'rop' => 100, 'roq' => 500,
                    'barcode' => '4800016103032', 'stock' => [[300, 'BF2411', 380]]] + $otc,
                ['name' => 'Cetirizine 10mg', 'generic' => 'Cetirizine', 'brand' => 'RiteMed', 'cat' => 'otc', 'uom' => 'tab', 'price' => 9.00, 'cost' => 5.00, 'rop' => 100, 'roq' => 300,
                    'stock' => [[250, 'CT2408', 300]]] + $otc,
                ['name' => 'Mefenamic Acid 500mg', 'generic' => 'Mefenamic acid', 'brand' => 'Dolfenal', 'cat' => 'otc', 'uom' => 'tab', 'price' => 6.00, 'cost' => 3.50, 'rop' => 100, 'roq' => 500,
                    'stock' => [[500, 'MF2412', 600]]] + $otc,
                ['name' => 'Loperamide 2mg', 'generic' => 'Loperamide', 'brand' => 'Diatabs', 'cat' => 'otc', 'uom' => 'cap', 'price' => 7.50, 'cost' => 4.20, 'rop' => 50, 'roq' => 200,
                    'stock' => [[150, 'LP2407', -10]]] + $otc,
                ['name' => 'Kremil-S', 'generic' => 'Aluminum hydroxide + Magnesium hydroxide + Simethicone', 'brand' => 'Unilab', 'cat' => 'otc', 'uom' => 'tab', 'price' => 8.50, 'cost' => 5.10, 'rop' => 60, 'roq' => 200,
                    'stock' => [[200, 'KS2502', 700]]] + $otc,
                ['name' => 'Amoxicillin 500mg', 'generic' => 'Amoxicillin', 'brand' => 'RiteMed', 'cat' => 'rx', 'uom' => 'cap', 'price' => 8.50, 'cost' => 4.90, 'rop' => 200, 'roq' => 1000,
                    'barcode' => '4806500001011', 'packs' => [['box', 100, 800.00, '14806500001018']], 'stock' => [[800, 'AMX2409', 450], [200, 'AMX2403', 60]]] + $rx,
                ['name' => 'Co-Amoxiclav 625mg', 'generic' => 'Amoxicillin + Clavulanic acid', 'brand' => 'Augmentin', 'cat' => 'rx', 'uom' => 'tab', 'price' => 68.00, 'cost' => 45.00, 'rop' => 30, 'roq' => 100,
                    'stock' => [[90, 'AUG2410', 500]]] + $rx,
                ['name' => 'Losartan 50mg', 'generic' => 'Losartan potassium', 'brand' => 'RiteMed', 'cat' => 'rx', 'uom' => 'tab', 'price' => 12.00, 'cost' => 6.80, 'rop' => 200, 'roq' => 600,
                    'exempt' => 1, 'stock' => [[600, 'LS2411', 650]]] + $rx,
                ['name' => 'Amlodipine 10mg', 'generic' => 'Amlodipine besilate', 'brand' => 'Norvasc', 'cat' => 'rx', 'uom' => 'tab', 'price' => 15.00, 'cost' => 8.50, 'rop' => 200, 'roq' => 600,
                    'exempt' => 1, 'stock' => [[400, 'AM2412', 720]]] + $rx,
                ['name' => 'Metformin 500mg', 'generic' => 'Metformin HCl', 'brand' => 'Glucophage', 'cat' => 'rx', 'uom' => 'tab', 'price' => 4.50, 'cost' => 2.40, 'rop' => 300, 'roq' => 1000,
                    'exempt' => 1, 'stock' => [[1000, 'MT2501', 800]]] + $rx,
                ['name' => 'Atorvastatin 20mg', 'generic' => 'Atorvastatin calcium', 'brand' => 'Lipitor', 'cat' => 'rx', 'uom' => 'tab', 'price' => 32.00, 'cost' => 19.00, 'rop' => 60, 'roq' => 300,
                    'exempt' => 1, 'stock' => [[200, 'AT2409', 560]]] + $rx,
                ['name' => 'Salbutamol 2mg', 'generic' => 'Salbutamol', 'brand' => 'Ventolin', 'cat' => 'rx', 'uom' => 'tab', 'price' => 6.50, 'cost' => 3.60, 'rop' => 100, 'roq' => 300,
                    'stock' => [[300, 'SB2408', 400]]] + $rx,
                ['name' => 'Enervon', 'generic' => 'Multivitamins + Vitamin C', 'brand' => 'Unilab', 'cat' => 'vit', 'uom' => 'tab', 'price' => 9.00, 'cost' => 5.60, 'rop' => 100, 'roq' => 300,
                    'packs' => [['box', 100, 850.00, null]], 'stock' => [[300, 'EN2412', 600]]] + $otc,
                ['name' => 'Ascorbic Acid 500mg', 'generic' => 'Vitamin C', 'brand' => 'Cecon', 'cat' => 'vit', 'uom' => 'tab', 'price' => 4.00, 'cost' => 2.20, 'rop' => 200, 'roq' => 1000,
                    'stock' => [[800, 'AA2502', 730]]] + $otc,
                ['name' => 'Centrum Advance', 'generic' => 'Multivitamins + Minerals', 'brand' => 'Centrum', 'cat' => 'vit', 'uom' => 'tab', 'price' => 16.00, 'cost' => 10.50, 'rop' => 60, 'roq' => 200,
                    'stock' => [[120, 'CA2410', 500]]] + $otc,
                ['name' => 'Betadine 10% Solution 60ml', 'generic' => 'Povidone-iodine', 'brand' => 'Betadine', 'cat' => 'supplies', 'uom' => 'btl', 'price' => 95.00, 'cost' => 62.00, 'rop' => 6, 'roq' => 24,
                    'barcode' => '4801981110012', 'stock' => [[24, 'BT2409', 900]]] + $otc,
                ['name' => 'Isopropyl Alcohol 70% 500ml', 'brand' => 'Green Cross', 'cat' => 'supplies', 'uom' => 'btl', 'price' => 85.00, 'cost' => 55.00, 'rop' => 12, 'roq' => 48,
                    'barcode' => '4800888118011', 'packs' => [['case', 24, 1920.00, '14800888118018']], 'stock' => [[48, null, null]]],
                ['name' => 'Face Mask 3-ply', 'cat' => 'supplies', 'uom' => 'pc', 'price' => 3.00, 'cost' => 1.20, 'rop' => 200, 'roq' => 1000,
                    'packs' => [['box', 50, 120.00, '4809999000050']], 'stock' => [[1500, null, null]]],
                ['name' => 'Cotton Balls 50g', 'cat' => 'supplies', 'uom' => 'pack', 'price' => 38.00, 'cost' => 22.00, 'rop' => 10, 'roq' => 48, 'stock' => [[40, null, null]]],
                ['name' => 'Digital Thermometer', 'brand' => 'Omron', 'cat' => 'supplies', 'uom' => 'pc', 'price' => 220.00, 'cost' => 150.00, 'rop' => 3, 'roq' => 10, 'stock' => [[8, null, null]]],
                ['name' => 'Safeguard Soap 135g', 'brand' => 'P&G', 'cat' => 'care', 'uom' => 'pc', 'price' => 52.00, 'cost' => 41.00, 'rop' => 24, 'roq' => 72,
                    'barcode' => '4902430450010', 'stock' => [[60, null, null]]],
                ['name' => 'Colgate Toothpaste 150g', 'brand' => 'Colgate', 'cat' => 'care', 'uom' => 'pc', 'price' => 98.00, 'cost' => 76.00, 'rop' => 12, 'roq' => 48, 'stock' => [[30, null, null]]],
                ['name' => 'Pampers Baby Dry Medium', 'brand' => 'Pampers', 'cat' => 'baby', 'uom' => 'pc', 'price' => 12.00, 'cost' => 8.90, 'rop' => 50, 'roq' => 200,
                    'packs' => [['pack', 30, 330.00, '4902430470001']], 'stock' => [[180, null, null]]],
                ['name' => 'Lactum 6+ 900g', 'brand' => 'Wyeth', 'cat' => 'baby', 'uom' => 'can', 'price' => 560.00, 'cost' => 455.00, 'rop' => 3, 'roq' => 12, 'lots' => 1,
                    'stock' => [[10, 'LC2503', 400]]],
                ['name' => 'Blood Pressure Check', 'cat' => 'svc', 'uom' => 'pc', 'type' => 'service', 'price' => 30.00],
                ['name' => 'Delivery Fee', 'cat' => 'svc', 'uom' => 'pc', 'type' => 'service', 'price' => 50.00],
            ],
        ];
    }

    private static function grocery(): array
    {
        return [
            'sku_prefix' => 'GR', 'settings' => ['business_name' => 'Aling Baby Minimart', 'business_type' => 'grocery', 'scale_barcode_prefix' => '2', 'receipt_title' => 'SALES INVOICE'],
            'categories' => [['bev', 'Beverages', '#1d4ed8'], ['canned', 'Canned Goods', '#b45309'], ['rice', 'Rice & Grains', '#a16207'], ['snacks', 'Snacks & Biscuits', '#db2777'],
                ['dairy', 'Dairy, Eggs & Frozen', '#0f766e'], ['produce', 'Fresh Produce (per kg)', '#15803d'], ['house', 'Household', '#6b7280'], ['care', 'Personal Care', '#7c3aed']],
            'suppliers' => [['Puregold Wholesale Club', 0], ['San Miguel Distributor', 15], ['Coca-Cola Beverages PH', 7], ['NFA Rice Dealer', 0]],
            'customers' => [['Carinderia ni Aling Rosa (listahan)', 15, 5000], ['Sari-sari Store ni Mang Tonyo', 7, 3000]],
            'employees' => [['Rosa Mendoza', 'Cashier'], ['Pedro Bautista', 'Bagger / Stock boy']],
            'items' => [
                ['name' => 'Coke 1.5L', 'brand' => 'Coca-Cola', 'cat' => 'bev', 'uom' => 'btl', 'price' => 75.00, 'cost' => 58.00, 'rop' => 12, 'roq' => 48,
                    'barcode' => '4801981100013', 'packs' => [['case', 12, 860.00, '14801981100010']], 'stock' => [[60, null, null]]],
                ['name' => 'Coke in Can 330ml', 'brand' => 'Coca-Cola', 'cat' => 'bev', 'uom' => 'can', 'price' => 32.00, 'cost' => 24.00, 'rop' => 24, 'roq' => 96,
                    'barcode' => '4801981100020', 'packs' => [['case', 24, 720.00, null]], 'stock' => [[96, null, null]]],
                ['name' => 'Bottled Water 500ml', 'brand' => 'Wilkins', 'cat' => 'bev', 'uom' => 'btl', 'price' => 18.00, 'cost' => 11.50, 'rop' => 24, 'roq' => 96,
                    'barcode' => '4801981100037', 'packs' => [['case', 24, 400.00, '14801981100034']], 'stock' => [[120, null, null]]],
                ['name' => 'San Miguel Pale Pilsen 320ml', 'brand' => 'San Miguel', 'cat' => 'bev', 'uom' => 'btl', 'price' => 65.00, 'cost' => 50.00, 'rop' => 24, 'roq' => 72,
                    'packs' => [['case', 24, 1500.00, null]], 'stock' => [[72, null, null]]],
                ['name' => 'Nescafe 3-in-1 Original', 'brand' => 'Nestle', 'cat' => 'bev', 'uom' => 'sachet', 'price' => 8.00, 'cost' => 6.20, 'rop' => 60, 'roq' => 300,
                    'packs' => [['pack', 30, 225.00, '4800361001014']], 'stock' => [[300, null, null]]],
                ['name' => '555 Sardines Tomato 155g', 'brand' => '555', 'cat' => 'canned', 'uom' => 'can', 'price' => 24.00, 'cost' => 18.50, 'rop' => 48, 'roq' => 200, 'lots' => 1,
                    'wprice' => 22.50, 'wmin' => 24, 'barcode' => '4800194111016', 'packs' => [['case', 100, 2200.00, null]], 'stock' => [[200, 'S2409', 540]]],
                ['name' => 'Argentina Corned Beef 175g', 'brand' => 'Argentina', 'cat' => 'canned', 'uom' => 'can', 'price' => 42.00, 'cost' => 34.00, 'rop' => 24, 'roq' => 96, 'lots' => 1,
                    'barcode' => '4800194222019', 'stock' => [[96, 'CB2410', 700]]],
                ['name' => 'Century Tuna Flakes in Oil 155g', 'brand' => 'Century', 'cat' => 'canned', 'uom' => 'can', 'price' => 38.00, 'cost' => 30.00, 'rop' => 24, 'roq' => 96, 'lots' => 1,
                    'barcode' => '4800194333012', 'stock' => [[72, 'CT2411', 600]]],
                ['name' => 'Lucky Me Pancit Canton Original', 'brand' => 'Lucky Me', 'cat' => 'snacks', 'uom' => 'pc', 'price' => 16.00, 'cost' => 12.50, 'rop' => 48, 'roq' => 240, 'lots' => 1,
                    'wprice' => 15.00, 'wmin' => 24, 'barcode' => '4807770270017', 'packs' => [['case', 72, 1050.00, null]], 'stock' => [[216, 'LM2412', 150]]],
                ['name' => 'Skyflakes Crackers 25g', 'brand' => 'M.Y. San', 'cat' => 'snacks', 'uom' => 'pack', 'price' => 9.00, 'cost' => 7.00, 'rop' => 30, 'roq' => 100,
                    'barcode' => '4800016644016', 'stock' => [[100, null, null]]],
                ['name' => 'Piattos Cheese 85g', 'brand' => 'Jack n Jill', 'cat' => 'snacks', 'uom' => 'pack', 'price' => 42.00, 'cost' => 34.00, 'rop' => 12, 'roq' => 48,
                    'barcode' => '4800016555015', 'stock' => [[36, null, null]]],
                ['name' => 'Rice Sinandomeng (per kg)', 'cat' => 'rice', 'uom' => 'kg', 'price' => 58.00, 'cost' => 49.00, 'rop' => 50, 'roq' => 150,
                    'wprice' => 55.00, 'wmin' => 25, 'plu' => '00101', 'packs' => [['sack', 25, 1375.00, null], ['sack', 50, 0, null]], 'stock' => [[300, null, null]]],
                ['name' => 'Rice Dinorado (per kg)', 'cat' => 'rice', 'uom' => 'kg', 'price' => 65.00, 'cost' => 55.00, 'rop' => 50, 'roq' => 150,
                    'wprice' => 62.00, 'wmin' => 25, 'plu' => '00102', 'stock' => [[150, null, null]]],
                ['name' => 'Sugar White (per kg)', 'cat' => 'rice', 'uom' => 'kg', 'price' => 85.00, 'cost' => 72.00, 'rop' => 20, 'roq' => 50, 'plu' => '00110', 'stock' => [[50, null, null]]],
                ['name' => 'Eggs Medium', 'cat' => 'dairy', 'uom' => 'pc', 'price' => 9.00, 'cost' => 7.50, 'rop' => 60, 'roq' => 360, 'lots' => 1,
                    'packs' => [['tray', 30, 255.00, null], ['doz', 12, 105.00, null]], 'stock' => [[360, 'EG-WK42', 21]]],
                ['name' => 'Bear Brand Powdered Milk 320g', 'brand' => 'Nestle', 'cat' => 'dairy', 'uom' => 'pack', 'price' => 165.00, 'cost' => 138.00, 'rop' => 12, 'roq' => 48, 'lots' => 1,
                    'barcode' => '4800361111010', 'stock' => [[36, 'BB2410', 365]]],
                ['name' => 'Magnolia Fresh Milk 1L', 'brand' => 'Magnolia', 'cat' => 'dairy', 'uom' => 'pc', 'price' => 112.00, 'cost' => 92.00, 'rop' => 6, 'roq' => 24, 'lots' => 1,
                    'barcode' => '4800888222013', 'stock' => [[24, 'MF1019', 12]]],
                ['name' => 'Banana Lakatan (per kg)', 'cat' => 'produce', 'uom' => 'kg', 'price' => 90.00, 'cost' => 65.00, 'plu' => '00201', 'stock' => [[30, null, null]]],
                ['name' => 'Red Onion (per kg)', 'cat' => 'produce', 'uom' => 'kg', 'price' => 160.00, 'cost' => 125.00, 'plu' => '00202', 'stock' => [[20, null, null]]],
                ['name' => 'Tomato (per kg)', 'cat' => 'produce', 'uom' => 'kg', 'price' => 80.00, 'cost' => 55.00, 'plu' => '00203', 'stock' => [[15, null, null]]],
                ['name' => 'Tide Powder 1kg', 'brand' => 'P&G', 'cat' => 'house', 'uom' => 'pack', 'price' => 140.00, 'cost' => 118.00, 'rop' => 12, 'roq' => 36,
                    'barcode' => '4902430333016', 'stock' => [[36, null, null]]],
                ['name' => 'Joy Dishwashing Liquid 250ml', 'brand' => 'P&G', 'cat' => 'house', 'uom' => 'btl', 'price' => 45.00, 'cost' => 36.00, 'rop' => 12, 'roq' => 48,
                    'barcode' => '4902430444013', 'stock' => [[48, null, null]]],
                ['name' => 'Safeguard Soap 135g', 'brand' => 'P&G', 'cat' => 'care', 'uom' => 'pc', 'price' => 52.00, 'cost' => 41.00, 'rop' => 24, 'roq' => 72,
                    'barcode' => '4902430450010', 'stock' => [[72, null, null]]],
                ['name' => 'Plastic Bag (per pc)', 'cat' => 'house', 'uom' => 'pc', 'type' => 'service', 'price' => 2.00],
            ],
        ];
    }

    private static function hardware(): array
    {
        return [
            'sku_prefix' => 'HW', 'settings' => ['business_name' => 'Mang Kanor Hardware & Construction Supply', 'business_type' => 'hardware', 'receipt_title' => 'SALES INVOICE'],
            'categories' => [['elec', 'Electrical', '#b45309'], ['plumb', 'Plumbing', '#1d4ed8'], ['paint', 'Paint & Finishing', '#db2777'], ['tools', 'Tools', '#374151'],
                ['fast', 'Nails, Screws & Fasteners', '#6b7280'], ['build', 'Cement, Steel & Lumber', '#a16207'], ['svc', 'Services', '#15803d']],
            'suppliers' => [['Wilcon Depot Trading', 30], ['Republic Cement Dealer', 15], ['Boysen Paints Distributor', 30], ['Divisoria Hardware Supply', 0]],
            'customers' => [['JRB Construction (charge account)', 30, 100000], ['Brgy. San Isidro Projects', 45, 50000]],
            'employees' => [['Kanor Villanueva Jr.', 'Store Supervisor'], ['Lito Ramos', 'Helper / Delivery']],
            'items' => [
                ['name' => 'Portland Cement 40kg', 'brand' => 'Republic', 'cat' => 'build', 'uom' => 'bag', 'price' => 265.00, 'cost' => 232.00, 'rop' => 50, 'roq' => 200,
                    'wprice' => 255.00, 'wmin' => 50, 'stock' => [[180, null, null]]],
                ['name' => 'Deformed Steel Bar 10mm x 6m', 'cat' => 'build', 'uom' => 'pc', 'price' => 215.00, 'cost' => 185.00, 'rop' => 30, 'roq' => 100, 'wprice' => 205.00, 'wmin' => 50, 'stock' => [[120, null, null]]],
                ['name' => 'Deformed Steel Bar 12mm x 6m', 'cat' => 'build', 'uom' => 'pc', 'price' => 310.00, 'cost' => 268.00, 'rop' => 30, 'roq' => 100, 'stock' => [[80, null, null]]],
                ['name' => 'Concrete Hollow Block 4"', 'cat' => 'build', 'uom' => 'pc', 'price' => 16.00, 'cost' => 12.50, 'rop' => 200, 'roq' => 1000, 'wprice' => 15.00, 'wmin' => 100, 'stock' => [[800, null, null]]],
                ['name' => 'Marine Plywood 1/2" 4x8', 'cat' => 'build', 'uom' => 'sheet', 'price' => 980.00, 'cost' => 840.00, 'rop' => 10, 'roq' => 30, 'stock' => [[25, null, null]]],
                ['name' => 'Washed Sand (per cu.m)', 'cat' => 'build', 'uom' => 'pc', 'price' => 1400.00, 'cost' => 1100.00, 'stock' => [[6, null, null]]],
                ['name' => 'Common Wire Nail 2" (per kg)', 'cat' => 'fast', 'uom' => 'kg', 'price' => 95.00, 'cost' => 72.00, 'rop' => 20, 'roq' => 50, 'wprice' => 88.00, 'wmin' => 10,
                    'packs' => [['box', 25, 2200.00, null]], 'stock' => [[75, null, null]]],
                ['name' => 'Common Wire Nail 4" (per kg)', 'cat' => 'fast', 'uom' => 'kg', 'price' => 92.00, 'cost' => 70.00, 'rop' => 20, 'roq' => 50, 'stock' => [[50, null, null]]],
                ['name' => 'Concrete Nail 3"', 'cat' => 'fast', 'uom' => 'kg', 'price' => 160.00, 'cost' => 125.00, 'rop' => 5, 'roq' => 20, 'stock' => [[15, null, null]]],
                ['name' => 'Tox Screw 8x1-1/2"', 'cat' => 'fast', 'uom' => 'pc', 'price' => 2.50, 'cost' => 1.40, 'rop' => 200, 'roq' => 1000, 'packs' => [['box', 100, 220.00, null]], 'stock' => [[1500, null, null]]],
                ['name' => 'THHN Wire 2.0mm² (per meter)', 'brand' => 'Phelps Dodge', 'cat' => 'elec', 'uom' => 'm', 'price' => 28.00, 'cost' => 21.50, 'rop' => 100, 'roq' => 300,
                    'packs' => [['roll', 150, 3950.00, null]], 'stock' => [[450, null, null]]],
                ['name' => 'THHN Wire 3.5mm² (per meter)', 'brand' => 'Phelps Dodge', 'cat' => 'elec', 'uom' => 'm', 'price' => 42.00, 'cost' => 33.00, 'rop' => 100, 'roq' => 300,
                    'packs' => [['roll', 150, 6000.00, null]], 'stock' => [[300, null, null]]],
                ['name' => 'LED Bulb 9W Daylight', 'brand' => 'Firefly', 'cat' => 'elec', 'uom' => 'pc', 'price' => 115.00, 'cost' => 82.00, 'rop' => 12, 'roq' => 48,
                    'barcode' => '4806012345011', 'packs' => [['box', 12, 1300.00, null]], 'stock' => [[60, null, null]]],
                ['name' => 'Universal Outlet 2-gang', 'brand' => 'Royu', 'cat' => 'elec', 'uom' => 'pc', 'price' => 95.00, 'cost' => 68.00, 'rop' => 10, 'roq' => 30, 'stock' => [[40, null, null]]],
                ['name' => 'Circuit Breaker 30A', 'brand' => 'Royu', 'cat' => 'elec', 'uom' => 'pc', 'price' => 320.00, 'cost' => 240.00, 'rop' => 5, 'roq' => 20, 'stock' => [[12, null, null]]],
                ['name' => 'PVC Pipe 1/2" x 3m', 'brand' => 'Neltex', 'cat' => 'plumb', 'uom' => 'pc', 'price' => 135.00, 'cost' => 102.00, 'rop' => 20, 'roq' => 60, 'stock' => [[50, null, null]]],
                ['name' => 'PVC Elbow 1/2"', 'brand' => 'Neltex', 'cat' => 'plumb', 'uom' => 'pc', 'price' => 12.00, 'cost' => 7.50, 'rop' => 50, 'roq' => 200, 'stock' => [[200, null, null]]],
                ['name' => 'Teflon Tape', 'cat' => 'plumb', 'uom' => 'roll', 'price' => 15.00, 'cost' => 8.00, 'rop' => 24, 'roq' => 100, 'stock' => [[100, null, null]]],
                ['name' => 'Faucet Stainless 1/2"', 'cat' => 'plumb', 'uom' => 'pc', 'price' => 185.00, 'cost' => 130.00, 'rop' => 6, 'roq' => 24, 'stock' => [[18, null, null]]],
                ['name' => 'Boysen Latex Flat White', 'brand' => 'Boysen', 'cat' => 'paint', 'uom' => 'gal', 'price' => 720.00, 'cost' => 590.00, 'rop' => 6, 'roq' => 24, 'lots' => 1,
                    'packs' => [['L', 1, 210.00, null]], 'stock' => [[24, 'BY2408', 540]]],
                ['name' => 'Boysen Semi-Gloss Enamel White', 'brand' => 'Boysen', 'cat' => 'paint', 'uom' => 'gal', 'price' => 890.00, 'cost' => 735.00, 'rop' => 4, 'roq' => 12, 'lots' => 1,
                    'stock' => [[12, 'BY2411', 700]]],
                ['name' => 'Paint Thinner 1L', 'cat' => 'paint', 'uom' => 'btl', 'price' => 110.00, 'cost' => 80.00, 'rop' => 6, 'roq' => 24, 'stock' => [[24, null, null]]],
                ['name' => 'Paint Roller 7" with tray', 'cat' => 'paint', 'uom' => 'set', 'price' => 165.00, 'cost' => 110.00, 'rop' => 5, 'roq' => 20, 'stock' => [[15, null, null]]],
                ['name' => 'Claw Hammer 16oz', 'brand' => 'Stanley', 'cat' => 'tools', 'uom' => 'pc', 'price' => 450.00, 'cost' => 320.00, 'rop' => 3, 'roq' => 10, 'stock' => [[8, null, null]]],
                ['name' => 'Steel Tape Measure 5m', 'brand' => 'Stanley', 'cat' => 'tools', 'uom' => 'pc', 'price' => 180.00, 'cost' => 120.00, 'rop' => 5, 'roq' => 20, 'stock' => [[15, null, null]]],
                ['name' => 'Cutting Disc 4" (metal)', 'brand' => 'Bosch', 'cat' => 'tools', 'uom' => 'pc', 'price' => 45.00, 'cost' => 30.00, 'rop' => 20, 'roq' => 100, 'packs' => [['box', 25, 1000.00, null]], 'stock' => [[100, null, null]]],
                ['name' => 'Work Gloves (pair)', 'cat' => 'tools', 'uom' => 'pair', 'price' => 65.00, 'cost' => 40.00, 'rop' => 12, 'roq' => 48, 'stock' => [[36, null, null]]],
                ['name' => 'Delivery (within town)', 'cat' => 'svc', 'uom' => 'pc', 'type' => 'service', 'price' => 300.00],
                ['name' => 'Pipe Threading Service', 'cat' => 'svc', 'uom' => 'pc', 'type' => 'service', 'price' => 50.00],
            ],
        ];
    }
}
