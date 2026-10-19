<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Batches;
use App\Services\Inventory;
use App\Services\Items;
use App\Services\Settings;

/**
 * Items & prices: the item master list and the full-page item form
 * (general fields, pricing with live margin %, stock settings, pack / purchase units with barcodes, lots on hand).
 */
class ItemController
{
    public function index(Request $req)
    {
        $f = [
            'type' => (string) $req->query('type', ''),
            'category_id' => (string) $req->query('category_id', ''),
            'q' => trim((string) $req->query('q', '')),
            'inactive' => $req->query('inactive') === '1',
            'low' => $req->query('low') === '1',
        ];
        $where = [];
        $params = [];
        if (isset(Items::TYPES[$f['type']])) { $where[] = 'i.item_type = ?'; $params[] = $f['type']; }
        if ($f['category_id'] !== '') { $where[] = 'i.category_id = ?'; $params[] = (int) $f['category_id']; }
        if (!$f['inactive']) $where[] = 'i.active = 1';
        if ($f['q'] !== '') {
            $where[] = '(i.name ILIKE ? OR i.generic_name ILIKE ? OR i.brand ILIKE ? OR i.sku ILIKE ? OR i.barcode = ? OR i.plu = ?
                         OR EXISTS (SELECT 1 FROM item_uoms iu WHERE iu.item_id = i.id AND iu.barcode = ?))';
            $like = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like, $like, $f['q'], $f['q'], $f['q']);
        }
        $rows = DB::all(
            'SELECT i.*, c.name AS category_name, u.abbr AS uom FROM items i
             LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id '
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.sort_order, c.name, i.sort_order, i.name',
            $params
        );

        foreach ($rows as &$r) {
            $stocked = in_array($r['item_type'], Inventory::STOCKED, true);
            $cost = $stocked ? (float) $r['avg_cost'] : 0.0;
            $r += [
                'type_short' => Items::TYPE_SHORT[$r['item_type']],
                'unit_cost' => $cost,
                'margin_pct' => $r['sellable'] && (float) $r['price'] > 0 ? Items::marginPct($cost, (float) $r['price'], (bool) $r['vat_exempt']) : null,
                'on_hand' => $stocked ? (float) $r['stock_qty'] : null,
                'reorder' => $stocked ? (float) $r['reorder_point'] : null,
                'stock_value' => $stocked ? r2((float) $r['stock_qty'] * (float) $r['avg_cost']) : null,
                'status' => Items::status($r),
                'flags' => implode(' ', array_filter([$r['rx_required'] ? 'Rx' : null, $r['vat_exempt'] ? 'VAT-exempt' : null, $r['track_batches'] ? 'Lots' : null])),
            ];
        }
        unset($r);

        $stocked = array_filter($rows, fn ($r) => $r['on_hand'] !== null && $r['active']);
        $isLow = fn ($r) => in_array($r['status'], ['REORDER', 'NEGATIVE'], true);
        $stats = [
            'total' => count($rows),
            'stocked' => count($stocked),
            'low' => count(array_filter($stocked, $isLow)),
            'value' => array_sum(array_column($stocked, 'stock_value')),
            'retail' => array_sum(array_map(fn ($r) => max((float) $r['stock_qty'], 0) * (float) $r['price'], $stocked)),
        ];
        if ($f['low']) $rows = array_values(array_filter($rows, $isLow));

        $columns = [
            ['key' => 'sku', 'label' => 'SKU', 'html' => fn ($r) => '<span class="nowrap muted">' . e($r['sku']) . '</span>'],
            ['key' => 'name', 'label' => 'Name', 'html' => fn ($r) => '<span class="' . ($r['active'] ? 'bold' : 'muted') . '">' . e($r['name']) . '</span>'
                . ($r['generic_name'] ? '<div class="muted small">' . e($r['generic_name']) . ($r['brand'] ? ' · ' . e($r['brand']) : '') . '</div>' : ($r['brand'] ? '<div class="muted small">' . e($r['brand']) . '</div>' : ''))],
            ['key' => 'barcode', 'label' => 'Barcode', 'html' => fn ($r) => '<span class="nowrap muted small">' . e($r['barcode'] ?: ($r['plu'] ? 'PLU ' . $r['plu'] : '')) . '</span>'],
            ['key' => 'category_name', 'label' => 'Category'],
            ['key' => 'type_short', 'label' => 'Type'],
            ['key' => 'uom', 'label' => 'Unit'],
            ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'html' => fn ($r) => (float) $r['price'] ? money($r['price']) : '<span class="muted">—</span>'],
            ['key' => 'unit_cost', 'label' => 'Avg cost', 'type' => 'money', 'html' => fn ($r) => $r['on_hand'] === null ? '' : Items::cost4($r['unit_cost'])],
            ['key' => 'margin_pct', 'label' => 'Margin %', 'type' => 'percent', 'html' => fn ($r) => $r['margin_pct'] === null ? ''
                : '<span class="' . self::marginClass($r['margin_pct']) . '">' . number_format($r['margin_pct'], 2) . '%</span>'],
            ['key' => 'on_hand', 'label' => 'On hand', 'type' => 'qty', 'html' => fn ($r) => $r['on_hand'] === null ? ''
                : '<span class="' . ($r['on_hand'] < 0 ? 'text-red bold' : '') . '">' . qty($r['on_hand']) . '</span>'],
            ['key' => 'reorder', 'label' => 'Reorder pt', 'type' => 'qty'],
            ['key' => 'stock_value', 'label' => 'Stock value', 'type' => 'money', 'total' => true],
            ['key' => 'flags', 'label' => 'Flags', 'html' => fn ($r) => implode(' ', array_map(fn ($x) => badge($x), array_filter(explode(' ', str_replace('VAT-exempt', 'VAT-exempt', $r['flags'])))))],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
        ];
        $subtitle = $f['inactive'] ? 'Including inactive items' : 'Active items';
        if ($x = Table::export($req, 'items', 'Item Master List', $subtitle, $columns, $rows)) return $x;

        return view('inventory/items/index', [
            'title' => 'Items & Prices', 'rows' => $rows, 'columns' => $columns, 'stats' => $stats, 'f' => $f,
            'categories' => DB::all('SELECT id, name FROM categories ORDER BY sort_order, name'),
        ]);
    }

    public function create(Request $req)
    {
        return $this->form(null);
    }

    public function show(Request $req, string $id)
    {
        $item = DB::one('SELECT i.*, u.abbr AS uom FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.id = ?', [(int) $id]);
        if (!$item) throw HttpException::notFound('Item');
        return $this->form($item);
    }

    public function store(Request $req): Response
    {
        $id = Items::create($this->input($req));
        flash('success', 'Item created.');
        return redirect("/inventory/items/$id");
    }

    public function update(Request $req, string $id): Response
    {
        $data = $this->input($req);
        Items::update((int) $id, $data);
        $item = Inventory::item((int) $id);
        $costIgnored = isset($data['avg_cost']) && $data['avg_cost'] !== '' && abs((float) $item['avg_cost'] - num($data['avg_cost'])) > 0.00005
            && Inventory::isStocked($item);
        flash('success', $costIgnored ? 'Saved. Cost was not changed because the item already has stock history.' : 'Item saved.');
        return redirect("/inventory/items/$id");
    }

    public function delete(Request $req, string $id): Response
    {
        $deleted = Items::delete((int) $id);
        flash('success', $deleted ? 'Item deleted.' : 'Item has history, so it was deactivated instead of deleted.');
        return redirect('/inventory/items');
    }

    /** JSON: units an item can be entered in, e.g. [{"uom_id":2,"abbr":"pc","factor":1,"price":12,"barcode":"480..."}, {"uom_id":10,"abbr":"box","factor":100,...}] */
    public function units(Request $req, string $id): Response
    {
        return json(Inventory::units((int) $id));
    }

    /** JSON: lots with stock for an item (for the lot pickers on write-off / issuance lines): [{"id":3,"label":"LOT1 · exp 12 Nov 2026 · 40 on hand"}] */
    public function batches(Request $req, string $id): Response
    {
        $out = [];
        foreach (Batches::options((int) $id) as $bid => $label) $out[] = ['id' => $bid, 'label' => $label];
        return json($out);
    }

    /** Submitted form fields; the color only counts when "custom color" is ticked. */
    private function input(Request $req): array
    {
        $data = $req->all();
        if (empty($data['use_color'])) $data['color'] = null;
        $data['uoms'] = array_values($data['uoms'] ?? []);
        return $data;
    }

    /** The item page. After a failed save the submitted values (old input) are shown again. */
    private function form(?array $item): string
    {
        $id = $item ? (int) $item['id'] : 0;
        $old = old('_form') === 'item' ? $GLOBALS['__old'] : null;
        $values = $old ?? $item ?? ['item_type' => 'product', 'active' => 1, 'sellable' => 1, 'track_batches' => Settings::get('business_type') === 'pharmacy' ? 1 : 0,
            'sort_order' => 0, 'price' => '', 'avg_cost' => '', 'wholesale_price' => '', 'wholesale_min_qty' => ''];
        if ($old) $values += ['active' => 0, 'sellable' => 0, 'vat_exempt' => 0, 'rx_required' => 0, 'track_batches' => 0];

        $altUnits = $old ? array_values($old['uoms'] ?? []) : DB::all('SELECT uom_id, factor, price, barcode FROM item_uoms WHERE item_id = ? ORDER BY factor', [$id]);
        $uoms = DB::all('SELECT id, name, abbr FROM uoms ORDER BY name');
        $tax = Settings::tax();

        return view('inventory/items/edit', [
            'title' => $item ? $item['name'] : 'New item',
            'item' => $item,
            'v' => $values,
            'altUnits' => $altUnits,
            'uomOptions' => array_column(array_map(fn ($u) => ['id' => $u['id'], 'label' => "{$u['name']} ({$u['abbr']})"], $uoms), 'label', 'id'),
            'uomAbbr' => array_column($uoms, 'abbr', 'id'),
            'categories' => array_column(DB::all('SELECT id, name FROM categories ORDER BY sort_order, name'), 'name', 'id'),
            'batches' => $id && (int) ($item['track_batches'] ?? 0) ? Batches::forItem($id, false) : [],
            'hasMovements' => $id && DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$id]),
            'readonly' => !can('inventory.manage'),
            'vatRate' => $tax['vatRegistered'] ? $tax['vatRate'] : 0.0,
            'businessType' => Settings::get('business_type'),
        ]);
    }

    /** Text color for a margin %: negative is red, under 10% is a warning. */
    public static function marginClass(?float $pct): string
    {
        if ($pct === null) return '';
        return $pct < 0 ? 'text-red bold' : ($pct < 10 ? 'text-amber' : 'text-green');
    }
}
