<?php
/**
 * Item form (new / edit).
 * Variables: $item (row or null), $v (form values), $altUnits, $uomOptions, $uomAbbr, $categories, $batches, $hasMovements, $readonly, $vatRate, $businessType
 * inventory.js shows the sections that fit the item type and computes margin % live.
 */
use App\Services\Inventory;
use App\Services\Items;

$isNew = !$item;
$stockedItem = $item && Inventory::isStocked($item);
$baseAbbr = $uomAbbr[$v['base_uom_id'] ?? ''] ?? 'base unit';
$num = fn ($x) => $x === null || $x === '' ? '' : (float) $x;
$action = $isNew ? url('/inventory/items') : url('/inventory/items/' . $item['id']);
$rowData = ['uomOptions' => $uomOptions, 'baseAbbr' => $baseAbbr, 'readonly' => $readonly];
$pharmacy = $businessType === 'pharmacy';
?>
<div class="page-header">
  <div>
    <h1><?= e($isNew ? 'New item' : $item['name']) ?></h1>
    <p class="muted">
      <?php if ($isNew): ?>Add a product you stock and sell, or a service / fee.
      <?php else: ?><?= e($item['sku']) ?> · <?= e(Items::TYPES[$item['item_type']]) ?> <?= $item['active'] ? '' : badge('inactive') ?>
        <?= $item['rx_required'] ? badge('Rx') : '' ?> <?= $item['vat_exempt'] ? badge('VAT-exempt') : '' ?><?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/items') ?>">← Back</a>
    <?php if ($stockedItem && can('reports.inventory')): ?>
      <a class="btn" href="<?= url('/reports/inventory', ['report' => 'stockcard', 'item_id' => $item['id']]) ?>">Stock card</a>
    <?php endif; ?>
    <?php if (!$readonly && !$isNew): ?>
      <form method="post" action="<?= url('/inventory/items/' . $item['id'] . '/delete') ?>" class="inline" data-danger data-ok="Delete"
            data-confirm="Delete “<?= e($item['name']) ?>”? Items with history are deactivated instead.">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit">Delete</button>
      </form>
    <?php endif; ?>
    <?php if (!$readonly): ?><button class="btn btn-primary" type="submit" form="item-form"><?= $isNew ? 'Create item' : 'Save' ?></button><?php endif; ?>
  </div>
</div>

<?php if ($readonly): ?><div class="alert alert-info">You can view this item but not change it.</div><?php endif; ?>

<form id="item-form" method="post" action="<?= $action ?>" data-item-form data-item-id="<?= $item['id'] ?? '' ?>"
      data-vat-rate="<?= e($vatRate) ?>" data-new="<?= $isNew ? '1' : '0' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="_form" value="item">
  <fieldset class="stack" style="border:0;padding:0;margin:0;min-width:0" <?= $readonly ? 'disabled' : '' ?>>

    <div class="card">
      <div class="card-head"><h3>General</h3></div>
      <div class="card-body form-grid">
        <label class="field" style="grid-column:span 2"><span class="field-label">Item name *</span>
          <input class="input" name="name" value="<?= e($v['name'] ?? '') ?>" required maxlength="120" placeholder="<?= $pharmacy ? 'e.g. Biogesic 500mg tablet' : 'e.g. Lucky Me Pancit Canton 60g' ?>" <?= $isNew ? 'autofocus' : '' ?>></label>
        <label class="field"><span class="field-label"><?= $pharmacy ? 'Generic name' : 'Generic / description' ?></span>
          <input class="input" name="generic_name" value="<?= e($v['generic_name'] ?? '') ?>" maxlength="120" placeholder="<?= $pharmacy ? 'e.g. Paracetamol' : 'optional' ?>"></label>
        <label class="field"><span class="field-label">Brand</span><input class="input" name="brand" value="<?= e($v['brand'] ?? '') ?>" maxlength="80"></label>
        <label class="field" style="grid-column:span 2"><span class="field-label">Item type *</span>
          <select class="input" name="item_type" data-item-type><?= options(Items::TYPES, $v['item_type']) ?></select>
          <span class="field-hint" data-type-hint><?= e(Items::TYPE_HINTS[$v['item_type']] ?? '') ?></span></label>
        <label class="field"><span class="field-label">Category</span>
          <select class="input" name="category_id"><?= options($categories, $v['category_id'] ?? '', '— None —') ?></select></label>
        <label class="field"><span class="field-label">Selling unit *</span>
          <select class="input" name="base_uom_id" data-base-unit required <?= $hasMovements ? 'disabled' : '' ?>>
            <?= options($uomOptions, $v['base_uom_id'] ?? '', '— Choose —') ?></select>
          <?php if ($hasMovements): ?><input type="hidden" name="base_uom_id" value="<?= e($item['base_uom_id']) ?>"><?php endif; ?>
          <span class="field-hint"><?= $hasMovements ? 'Cannot change: the item has stock movements' : 'Smallest unit you sell and count (pc, tab, kg, m…)' ?></span></label>
        <label class="field"><span class="field-label">SKU</span>
          <input class="input" name="sku" value="<?= e($v['sku'] ?? '') ?>" maxlength="40"><span class="field-hint">Leave blank to auto-number</span></label>
        <label class="field"><span class="field-label">Barcode (per selling unit)</span>
          <input class="input" name="barcode" value="<?= e($v['barcode'] ?? '') ?>" maxlength="60" placeholder="scan or type"><span class="field-hint">EAN / UPC printed on the retail pack</span></label>
        <label class="field"><span class="field-label">PLU (scale / quick code)</span>
          <input class="input" name="plu" value="<?= e($v['plu'] ?? '') ?>" maxlength="10" inputmode="numeric" placeholder="e.g. 1001"><span class="field-hint">Digits only; used by weighing-scale barcodes</span></label>
        <label class="field"><span class="field-label">Sort order</span><input class="input num" type="number" step="1" name="sort_order" value="<?= e((int) ($v['sort_order'] ?? 0)) ?>"></label>
        <div class="field"><span class="field-label">POS tile color</span>
          <div class="row gap-sm" style="height:36px">
            <input type="color" name="color" value="<?= e($v['color'] ?? '' ?: '#868e96') ?>">
            <label class="checkbox"><input type="checkbox" name="use_color" value="1" <?= !empty($v['color']) ? 'checked' : '' ?>> Custom color</label>
          </div></div>
        <div class="field"><span class="field-label">Options</span>
          <label class="checkbox"><input type="checkbox" name="sellable" value="1" data-sellable <?= !empty($v['sellable']) ? 'checked' : '' ?>> Sellable on POS</label>
          <label class="checkbox"><input type="checkbox" name="active" value="1" <?= !empty($v['active']) ? 'checked' : '' ?>> Active</label>
        </div>
        <div class="field"><span class="field-label">Tax &amp; compliance</span>
          <label class="checkbox"><input type="checkbox" name="vat_exempt" value="1" data-vat-exempt <?= !empty($v['vat_exempt']) ? 'checked' : '' ?>> VAT-exempt item</label>
          <label class="checkbox"><input type="checkbox" name="rx_required" value="1" <?= !empty($v['rx_required']) ? 'checked' : '' ?>> Prescription (Rx) required</label>
          <span class="field-hint">VAT-exempt: e.g. medicines for diabetes, hypertension, cholesterol and cancer, basic agricultural produce.</span>
        </div>
        <label class="field" style="grid-column:span 2"><span class="field-label">Description / notes</span>
          <textarea class="input" style="height:auto;padding:8px 10px" rows="2" name="description" maxlength="255"><?= e($v['description'] ?? '') ?></textarea></label>
      </div>
    </div>

    <div class="grid-2" style="align-items:start">
      <div class="card">
        <div class="card-head"><h3>Pricing</h3></div>
        <div class="card-body">
          <div class="form-grid">
            <label class="field"><span class="field-label">Selling price per <span data-base-abbr><?= e($baseAbbr) ?></span></span>
              <input class="input num" type="number" step="0.01" min="0" name="price" value="<?= e($num($v['price'] ?? '')) ?>" placeholder="0.00" data-price>
              <span class="field-hint"><?= $vatRate ? 'VAT-inclusive' : 'non-VAT' ?></span></label>
            <label class="field" data-show-for="product"><span class="field-label"><?= $isNew ? 'Opening' : 'Standard' ?> cost per <span data-base-abbr><?= e($baseAbbr) ?></span></span>
              <?php if ($hasMovements): ?>
                <input class="input num" type="number" value="<?= e((float) $item['avg_cost']) ?>" disabled data-avg-cost>
                <span class="field-hint">Set by purchases (moving average)</span>
              <?php else: ?>
                <input class="input num" type="number" step="any" min="0" name="avg_cost" value="<?= e($num($v['avg_cost'] ?? '')) ?>" placeholder="0.00" data-avg-cost>
                <span class="field-hint"><?= $isNew ? 'Optional starting cost; purchases update it automatically' : 'Only editable while the item has no stock movements' ?></span>
              <?php endif; ?>
            </label>
            <label class="field"><span class="field-label">Wholesale price</span>
              <input class="input num" type="number" step="0.01" min="0" name="wholesale_price" value="<?= e($num($v['wholesale_price'] ?? '')) ?>" placeholder="none" data-wholesale-price></label>
            <label class="field"><span class="field-label">from quantity (<span data-base-abbr><?= e($baseAbbr) ?></span>)</span>
              <input class="input num" type="number" step="any" min="0" name="wholesale_min_qty" value="<?= e($num($v['wholesale_min_qty'] ?? '')) ?>" placeholder="e.g. 12" data-wholesale-qty>
              <span class="field-hint">The POS switches to the wholesale price automatically at this quantity</span></label>
          </div>
          <div class="stats mt" data-costing>
            <div class="stat"><div class="stat-label">Net of VAT</div><div class="stat-value" data-out="net">—</div><div class="stat-sub" data-out="net-sub"></div></div>
            <div class="stat"><div class="stat-label">Unit cost</div><div class="stat-value" data-out="cost">—</div></div>
            <div class="stat" data-tone="margin"><div class="stat-label">Gross margin</div><div class="stat-value" data-out="margin">—</div><div class="stat-sub" data-out="margin-pct"></div></div>
            <div class="stat" data-tone="markup"><div class="stat-label">Markup on cost</div><div class="stat-value" data-out="markup">—</div><div class="stat-sub" data-out="wholesale-note"></div></div>
          </div>
          <div class="alert alert-warn mt hidden" data-out="margin-warn">Selling price is below cost.</div>
        </div>
      </div>

      <div class="card" data-show-for="product">
        <div class="card-head"><h3>Stock settings</h3></div>
        <div class="card-body">
          <div class="form-grid">
            <label class="field"><span class="field-label">Reorder point (<span data-base-abbr><?= e($baseAbbr) ?></span>)</span>
              <input class="input num" type="number" step="any" min="0" name="reorder_point" value="<?= e($num($v['reorder_point'] ?? '')) ?>">
              <span class="field-hint">Flag for reorder at this level</span></label>
            <label class="field"><span class="field-label">Reorder qty (<span data-base-abbr><?= e($baseAbbr) ?></span>)</span>
              <input class="input num" type="number" step="any" min="0" name="reorder_qty" value="<?= e($num($v['reorder_qty'] ?? '')) ?>">
              <span class="field-hint">Suggested quantity to order</span></label>
            <div class="field" style="grid-column:span 2">
              <label class="checkbox"><input type="checkbox" name="track_batches" value="1" <?= !empty($v['track_batches']) ? 'checked' : '' ?>> Track lot numbers &amp; expiry dates</label>
              <span class="field-hint">Purchases ask for lot no. and expiry; sales deduct the soonest-to-expire lot first (FEFO); the Expiry report warns you ahead of time.</span>
            </div>
          </div>
          <?php if ($stockedItem): ?>
            <dl class="kv mt">
              <dt>On hand</dt><dd class="<?= (float) $item['stock_qty'] < 0 ? 'text-red' : '' ?>"><?= qty($item['stock_qty']) ?> <?= e($item['uom']) ?></dd>
              <dt>Average cost</dt><dd>₱<?= Items::cost4($item['avg_cost']) ?> / <?= e($item['uom']) ?></dd>
              <dt>Last cost</dt><dd>₱<?= Items::cost4($item['last_cost']) ?> / <?= e($item['uom']) ?></dd>
              <dt>Stock value</dt><dd><?= peso((float) $item['stock_qty'] * (float) $item['avg_cost']) ?></dd>
              <dt>Retail value</dt><dd><?= peso(max((float) $item['stock_qty'], 0) * (float) $item['price']) ?></dd>
            </dl>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>Pack sizes &amp; purchase units</h3>
        <?php if (!$readonly): ?><button class="btn btn-sm" type="button" data-add-row="uoms">+ Add unit</button><?php endif; ?></div>
      <div class="card-body" style="padding-bottom:0">
        <p class="muted small" style="margin:0">Bigger units of the same item, e.g. <b>1 box = 100 tab</b>, <b>1 case = 24 pc</b>, <b>1 sack = 25 kg</b>.
          Give a unit a <b>price</b> to sell it on the POS as its own tile, and a <b>barcode</b> so scanning the box rings it up. Units without a price are for purchasing only.</p>
      </div>
      <div class="table-wrap">
        <table class="table dense">
          <thead><tr><th>Unit</th><th>Contains</th><th>Selling price</th><th class="right">Margin</th><th>Barcode</th><th></th></tr></thead>
          <tbody data-rows="uoms">
            <?php foreach ($altUnits as $i => $r): ?><?= view('inventory/items/_unit_row', $rowData + ['i' => $i, 'r' => $r], null) ?><?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <template id="uoms-template"><?= view('inventory/items/_unit_row', $rowData + ['i' => '__i__', 'r' => []], null) ?></template>
    </div>

    <?php if ($batches): ?>
      <div class="card">
        <div class="card-head"><h3>Lots</h3><span class="muted small">Sales take from the earliest expiry first</span></div>
        <div class="table-wrap">
          <table class="table dense">
            <thead><tr><th>Lot no.</th><th>Expiry</th><th>Supplier</th><th>Received</th><th class="right">Received qty</th><th class="right">On hand</th><th class="right">Unit cost</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($batches as $b): $days = $b['expiry_date'] ? (int) ((strtotime($b['expiry_date']) - strtotime(today())) / 86400) : null; ?>
                <tr class="<?= (float) $b['qty_on_hand'] <= 0 ? 'muted-row' : '' ?>">
                  <td><b><?= e($b['batch_no'] ?: '—') ?></b></td>
                  <td><?= $b['expiry_date'] ? e(fmt_date($b['expiry_date'])) : '<span class="muted">no expiry</span>' ?></td>
                  <td><?= e($b['supplier_name'] ?? '') ?></td>
                  <td><?= e($b['received_at'] ? fmt_date($b['received_at']) : '') ?></td>
                  <td class="right"><?= qty($b['qty_in']) ?></td>
                  <td class="right bold"><?= qty($b['qty_on_hand']) ?></td>
                  <td class="right"><?= Items::cost4($b['unit_cost']) ?></td>
                  <td><?= (float) $b['qty_on_hand'] <= 0 ? badge('used up') : ($days === null ? '' : ($days < 0 ? badge('EXPIRED') : ($days <= 90 ? badge('EXPIRING') . " <span class=\"muted small\">$days d</span>" : badge('OK')))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </fieldset>

  <?php if (!$readonly): ?>
    <div class="row gap-sm mt">
      <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create item' : 'Save changes' ?></button>
      <a class="btn" href="<?= url('/inventory/items') ?>">Cancel</a>
    </div>
  <?php endif; ?>
</form>

<script type="application/json" id="inv-type-hints"><?= json_encode(Items::TYPE_HINTS, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="inv-uom-abbr"><?= json_encode($uomAbbr, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('js/inventory.js') ?>"></script>
