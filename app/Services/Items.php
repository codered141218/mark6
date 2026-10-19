<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Item master: create / update / delete items together with their pack / purchase units (item_uoms),
 * barcode lookup for the POS (item barcodes, pack barcodes, scale barcodes) and margin figures for list screens.
 */
class Items
{
    public const TYPES = [
        'product' => 'Product (stocked goods)',
        'service' => 'Service / fee (not stocked)',
    ];
    public const TYPE_SHORT = ['product' => 'Product', 'service' => 'Service'];
    public const TYPE_HINTS = [
        'product' => 'Goods you buy, keep in stock and sell: medicines, groceries, hardware. Stock, cost and reorder levels are tracked.',
        'service' => 'Sold but not tracked in stock: delivery fee, repair service, blood pressure check, key duplication, gift wrapping.',
    ];
    private const FIELDS = ['sku', 'name', 'generic_name', 'brand', 'category_id', 'item_type', 'base_uom_id', 'price', 'wholesale_price', 'wholesale_min_qty',
        'reorder_point', 'reorder_qty', 'sellable', 'active', 'vat_exempt', 'rx_required', 'track_batches', 'color', 'barcode', 'plu', 'description', 'sort_order'];

    /**
     * Create an item. $data holds the item fields plus optional
     * 'uoms' => [['uom_id', 'factor', 'price', 'barcode'], ...] (pack / purchase units).
     */
    public static function create(array $data): int
    {
        required($data, 'name', 'item_type', 'base_uom_id');
        $row = self::fields($data);
        if (empty($row['sku'])) $row['sku'] = Sequence::next('SKU', 'SKU', 5);
        if (DB::value('SELECT id FROM items WHERE sku = ?', [$row['sku']])) throw HttpException::bad('SKU already exists');
        self::checkBarcode($row['barcode'], 0);
        $cost = in_array($row['item_type'], Inventory::STOCKED, true) ? max(num($data['avg_cost'] ?? 0), 0) : 0;

        $id = DB::transaction(function () use ($row, $cost, $data) {
            $id = DB::insert('items', $row + ['avg_cost' => $cost, 'last_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
            self::saveChildren($id, $data);
            return $id;
        });
        Audit::log('create', 'item', $id, $row['name']);
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $item = Inventory::item($id);
        required($data, 'name', 'item_type', 'base_uom_id');
        $row = self::fields($data);
        if (empty($row['sku'])) unset($row['sku']);
        elseif (DB::value('SELECT id FROM items WHERE sku = ? AND id <> ?', [$row['sku'], $id])) throw HttpException::bad('SKU already exists');
        self::checkBarcode($row['barcode'], $id);

        if ($row['item_type'] !== $item['item_type'] && abs((float) $item['stock_qty']) > 0.0001) {
            throw HttpException::bad('Cannot change item type while it has stock on hand. Adjust stock to zero first.');
        }
        $hasMovements = (bool) DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$id]);
        if ((int) $row['base_uom_id'] !== (int) $item['base_uom_id'] && $hasMovements) {
            throw HttpException::bad('Cannot change the base unit of an item that already has stock movements');
        }
        // A standard cost can be set directly only while the item has no stock history (initial setup).
        if (isset($data['avg_cost']) && $data['avg_cost'] !== '' && !$hasMovements && in_array($row['item_type'], Inventory::STOCKED, true)) {
            $row['avg_cost'] = max(num($data['avg_cost']), 0);
        }

        DB::transaction(function () use ($id, $row, $data, $item) {
            DB::update('items', $id, $row + ['updated_at' => now()]);
            self::saveChildren($id, $data);
            if ($row['track_batches'] && !(int) $item['track_batches']) Batches::openingLot(Inventory::item($id));
        });
        Audit::log('update', 'item', $id, $row);
    }

    /**
     * Delete an item, or deactivate it when it already has history (sales, stock, documents).
     * Returns true if it was deleted, false if it was deactivated.
     */
    public static function delete(int $id): bool
    {
        $item = Inventory::item($id);
        $used = DB::value(
            'SELECT 1 FROM stock_movements WHERE item_id = ? UNION SELECT 1 FROM ticket_items WHERE item_id = ?
             UNION SELECT 1 FROM inv_doc_lines WHERE item_id = ? UNION SELECT 1 FROM count_lines WHERE item_id = ? LIMIT 1',
            [$id, $id, $id, $id]
        );
        if ($used) {
            DB::run('UPDATE items SET active = 0, sellable = 0, updated_at = ? WHERE id = ?', [now(), $id]);
            Audit::log('deactivate', 'item', $id, $item['name']);
            return false;
        }
        DB::run('DELETE FROM items WHERE id = ?', [$id]);
        Audit::log('delete', 'item', $id, $item['name']);
        return true;
    }

    /** The editable item columns from submitted data, validated and typed. */
    private static function fields(array $d): array
    {
        if (!isset(self::TYPES[$d['item_type']])) throw HttpException::bad('Unknown item type');
        if (!DB::value('SELECT id FROM uoms WHERE id = ?', [(int) $d['base_uom_id']])) throw HttpException::bad('Choose the base unit');
        if (num($d['price'] ?? 0) < 0) throw HttpException::bad('Price cannot be negative');
        $text = fn ($k, $len) => isset($d[$k]) && trim((string) $d[$k]) !== '' ? mb_substr(trim((string) $d[$k]), 0, $len) : null;
        $stocked = $d['item_type'] === 'product';
        $wholesale = isset($d['wholesale_price']) && $d['wholesale_price'] !== '' && num($d['wholesale_price']) > 0 ? r2(num($d['wholesale_price'])) : null;
        $wholesaleMin = $wholesale !== null && num($d['wholesale_min_qty'] ?? 0) > 0 ? r4(num($d['wholesale_min_qty'])) : null;
        if ($wholesale !== null && $wholesaleMin === null) throw HttpException::bad('Enter the minimum quantity for the wholesale price');
        $plu = $text('plu', 10);
        if ($plu !== null && !ctype_digit($plu)) throw HttpException::bad('The scale PLU must be a number (e.g. 00123)');
        return [
            'sku' => $text('sku', 40),
            'name' => mb_substr(trim($d['name']), 0, 160),
            'generic_name' => $text('generic_name', 160),
            'brand' => $text('brand', 80),
            'category_id' => !empty($d['category_id']) ? (int) $d['category_id'] : null,
            'item_type' => $d['item_type'],
            'base_uom_id' => (int) $d['base_uom_id'],
            'price' => r2(num($d['price'] ?? 0)),
            'wholesale_price' => $wholesale,
            'wholesale_min_qty' => $wholesaleMin,
            'reorder_point' => $stocked ? r4(max(num($d['reorder_point'] ?? 0), 0)) : 0,
            'reorder_qty' => $stocked ? r4(max(num($d['reorder_qty'] ?? 0), 0)) : 0,
            'sellable' => !empty($d['sellable']) ? 1 : 0,
            'active' => !empty($d['active']) ? 1 : 0,
            'vat_exempt' => !empty($d['vat_exempt']) ? 1 : 0,
            'rx_required' => !empty($d['rx_required']) ? 1 : 0,
            'track_batches' => $stocked && !empty($d['track_batches']) ? 1 : 0,
            'color' => $text('color', 9),
            'barcode' => $text('barcode', 60),
            'plu' => $plu,
            'description' => $text('description', 255),
            'sort_order' => (int) ($d['sort_order'] ?? 0),
        ];
    }

    /** A barcode may belong to only one item or pack. */
    private static function checkBarcode(?string $code, int $itemId): void
    {
        if ($code === null) return;
        $other = DB::value('SELECT name FROM items WHERE barcode = ? AND id <> ?', [$code, $itemId])
            ?? DB::value('SELECT i.name FROM item_uoms iu JOIN items i ON i.id = iu.item_id WHERE iu.barcode = ? AND iu.item_id <> ?', [$code, $itemId]);
        if ($other) throw HttpException::bad("Barcode $code is already used by \"$other\"");
    }

    /** Replace the pack / purchase units (stocked items only). Each: uom_id, factor, optional price (sold as a pack) and barcode. */
    private static function saveChildren(int $id, array $data): void
    {
        $item = Inventory::item($id);
        DB::run('DELETE FROM item_uoms WHERE item_id = ?', [$id]);
        if (!Inventory::isStocked($item)) return;
        $seen = [];
        foreach ($data['uoms'] ?? [] as $u) {
            if (empty($u['uom_id']) || num($u['factor'] ?? 0) <= 0) continue;
            $uomId = (int) $u['uom_id'];
            if ($uomId === (int) $item['base_uom_id']) throw HttpException::bad('A pack unit cannot be the same as the base unit');
            if (isset($seen[$uomId])) throw HttpException::bad('The same pack unit is listed twice');
            $seen[$uomId] = true;
            $barcode = isset($u['barcode']) && trim((string) $u['barcode']) !== '' ? mb_substr(trim((string) $u['barcode']), 0, 60) : null;
            if ($barcode !== null) {
                if ($barcode === $item['barcode']) throw HttpException::bad('The pack barcode must differ from the item barcode');
                $other = DB::value('SELECT name FROM items WHERE barcode = ? AND id <> ?', [$barcode, $id])
                    ?? DB::value('SELECT i.name FROM item_uoms iu JOIN items i ON i.id = iu.item_id WHERE iu.barcode = ? AND iu.item_id <> ?', [$barcode, $id]);
                if ($other) throw HttpException::bad("Barcode $barcode is already used by \"$other\"");
            }
            $price = isset($u['price']) && $u['price'] !== '' && num($u['price']) > 0 ? r2(num($u['price'])) : null;
            DB::insert('item_uoms', ['item_id' => $id, 'uom_id' => $uomId, 'factor' => (float) $u['factor'], 'price' => $price, 'barcode' => $barcode]);
        }
    }

    // ------------------------------------------------------------------ POS lookups & pricing

    /**
     * Everything that can be sold, one entry per item unit that has a price: the base unit of every sellable item,
     * plus pack units with their own price (1 box of 100 tablets, 1 case of 12 bottles ...).
     */
    public static function sellable(): array
    {
        $items = DB::all(
            'SELECT i.id, i.name, i.generic_name, i.brand, i.price, i.wholesale_price, i.wholesale_min_qty, i.category_id, i.color, i.item_type, i.stock_qty, i.sku,
                    i.barcode, i.plu, i.rx_required, i.vat_exempt, i.track_batches, i.sort_order, u.abbr AS uom
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.active = 1 AND i.sellable = 1 ORDER BY i.sort_order, i.name'
        );
        $packs = [];
        foreach (DB::all('SELECT iu.*, u.abbr FROM item_uoms iu JOIN uoms u ON u.id = iu.uom_id WHERE iu.price IS NOT NULL ORDER BY iu.factor') as $p) $packs[(int) $p['item_id']][] = $p;
        $out = [];
        foreach ($items as $i) {
            $base = [
                'key' => $i['id'] . ':0', 'id' => (int) $i['id'], 'uom_id' => null, 'factor' => 1.0, 'uom' => $i['uom'], 'name' => $i['name'], 'generic_name' => $i['generic_name'],
                'brand' => $i['brand'], 'price' => (float) $i['price'], 'wholesale_price' => $i['wholesale_price'] === null ? null : (float) $i['wholesale_price'],
                'wholesale_min_qty' => $i['wholesale_min_qty'] === null ? null : (float) $i['wholesale_min_qty'], 'category_id' => (int) $i['category_id'], 'color' => $i['color'],
                'item_type' => $i['item_type'], 'stock_qty' => (float) $i['stock_qty'], 'sku' => $i['sku'], 'barcode' => $i['barcode'], 'plu' => $i['plu'],
                'rx_required' => (int) $i['rx_required'], 'vat_exempt' => (int) $i['vat_exempt'], 'track_batches' => (int) $i['track_batches'],
            ];
            $out[] = $base;
            foreach ($packs[(int) $i['id']] ?? [] as $p) {
                $out[] = ['key' => $i['id'] . ':' . $p['uom_id'], 'uom_id' => (int) $p['uom_id'], 'factor' => (float) $p['factor'], 'uom' => $p['abbr'],
                    'name' => $i['name'] . ' (' . $p['abbr'] . ' of ' . qty($p['factor']) . ')', 'price' => (float) $p['price'], 'wholesale_price' => null, 'wholesale_min_qty' => null,
                    'barcode' => $p['barcode'], 'plu' => null, 'pack' => true] + $base;
            }
        }
        return $out;
    }

    /**
     * What a scanned / typed code means: ['item' => row, 'uom_id' => int|null, 'factor' => float, 'price' => float, 'qty' => float] or null.
     * Order: item barcode or SKU, pack barcode, then scale barcodes (prefix + 5-digit PLU + 5-digit weight in grams + check digit).
     */
    public static function lookup(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;
        $i = DB::one('SELECT * FROM items WHERE active = 1 AND sellable = 1 AND (barcode = ? OR sku = ?) ORDER BY CASE WHEN barcode = ? THEN 0 ELSE 1 END LIMIT 1', [$code, $code, $code]);
        if ($i) return ['item' => $i, 'uom_id' => null, 'factor' => 1.0, 'price' => (float) $i['price'], 'qty' => 1.0];
        $p = DB::one('SELECT iu.*, i.active, i.sellable FROM item_uoms iu JOIN items i ON i.id = iu.item_id WHERE iu.barcode = ? AND i.active = 1 AND i.sellable = 1', [$code]);
        if ($p) {
            $item = Inventory::item((int) $p['item_id']);
            return ['item' => $item, 'uom_id' => (int) $p['uom_id'], 'factor' => (float) $p['factor'], 'price' => $p['price'] === null ? r2($item['price'] * $p['factor']) : (float) $p['price'], 'qty' => 1.0];
        }
        $prefix = Settings::pos()['scalePrefix'];
        if ($prefix !== '' && strlen($code) === 13 && ctype_digit($code) && str_starts_with($code, $prefix)) {
            $plu = substr($code, strlen($prefix), 7 - strlen($prefix));
            $grams = (int) substr($code, 7, 5);
            $item = DB::one('SELECT * FROM items WHERE active = 1 AND sellable = 1 AND plu IS NOT NULL AND CAST(plu AS INTEGER) = ?', [(int) $plu]);
            if ($item && $grams > 0) return ['item' => $item, 'uom_id' => null, 'factor' => 1.0, 'price' => (float) $item['price'], 'qty' => r4($grams / 1000), 'scale' => true];
        }
        return null;
    }

    /** Unit price per base unit for a quantity (wholesale price from the minimum quantity up). */
    public static function effectivePrice(array $item, float $baseQty): float
    {
        if ($item['wholesale_price'] !== null && $item['wholesale_min_qty'] !== null && $baseQty >= (float) $item['wholesale_min_qty'] - 0.00005) {
            return (float) $item['wholesale_price'];
        }
        return (float) $item['price'];
    }

    // ------------------------------------------------------------------ costing for list screens

    /** Unit cost of every item at once: [item_id => avg cost] (services cost 0). */
    public static function unitCosts(): array
    {
        $costs = [];
        foreach (DB::all('SELECT id, item_type, avg_cost FROM items') as $i) $costs[(int) $i['id']] = $i['item_type'] === 'product' ? (float) $i['avg_cost'] : 0.0;
        return $costs;
    }

    /** VAT divisor for selling prices (prices are VAT-inclusive): 1.12, or 1 when not VAT-registered / the item is VAT-exempt. */
    public static function vatDivisor(bool $vatExempt = false): float
    {
        return $vatExempt ? 1.0 : 1 + Settings::tax()['vatRate'];
    }

    /** Gross margin % = (selling price net of VAT − cost) / net price. Null when there is no price. */
    public static function marginPct(float $cost, float $price, bool $vatExempt = false): ?float
    {
        if ($price <= 0) return null;
        $net = $price / self::vatDivisor($vatExempt);
        return r2(($net - $cost) / $net * 100);
    }

    /** Unit cost with 2 to 4 decimals, e.g. 0.055 or 190.00. */
    public static function cost4($n): string
    {
        if ($n === null || $n === '') return '';
        return preg_replace('/(\.\d{2}\d*?)0+$/', '$1', number_format((float) $n, 4));
    }

    /** Stock status shown on the item list: inactive / NEGATIVE / REORDER / OK ('' for services). */
    public static function status(array $i): string
    {
        $stocked = in_array($i['item_type'], Inventory::STOCKED, true);
        if (!$i['active']) return 'inactive';
        if ($stocked && (float) $i['stock_qty'] < 0) return 'NEGATIVE';
        if ($stocked && (float) $i['reorder_point'] > 0 && (float) $i['stock_qty'] <= (float) $i['reorder_point']) return 'REORDER';
        return $stocked ? 'OK' : '';
    }
}
