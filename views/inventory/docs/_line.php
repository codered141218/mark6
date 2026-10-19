<?php
/**
 * One editable document line. Variables: $i, $l (item_id, qty, uom_id, unit_cost, line_total, batch_no, expiry_date, batch_id, notes, units, batches), $type, $itemOptions
 * Unit options carry data-factor (base units per 1) for the live cost estimate in inventory.js.
 * RECEIVE lines have lot no. + expiry inputs (shown for lot-tracked items); ISSUE / WASTE lines have a lot picker.
 */
$uomId = (string) ($l['uom_id'] ?? '');
$val = fn ($k) => isset($l[$k]) && $l[$k] !== '' && $l[$k] !== null ? e((float) $l[$k]) : '';
$name = fn ($k) => 'lines[' . e($i) . '][' . $k . ']';
?>
<tr data-row>
  <td style="min-width:260px">
    <select class="input" data-combo data-field="item_id" data-placeholder="+ Add item (name, SKU or barcode)…" name="<?= $name('item_id') ?>"><?= options($itemOptions, $l['item_id'] ?? '', '') ?></select>
  </td>
  <td style="width:100px"><input class="input num" type="number" step="any" min="0" data-field="qty" name="<?= $name('qty') ?>" value="<?= $val('qty') ?>"></td>
  <td style="width:96px">
    <select class="input" data-field="uom_id" name="<?= $name('uom_id') ?>">
      <?php foreach ($l['units'] ?? [] as $u): ?>
        <option value="<?= $u['uom_id'] ?>" data-factor="<?= e($u['factor']) ?>" <?= (string) $u['uom_id'] === $uomId ? 'selected' : '' ?>><?= e($u['abbr']) ?></option>
      <?php endforeach; ?>
    </select>
  </td>
  <?php if ($type === 'RECEIVE'): ?>
    <td style="width:120px"><input class="input num" type="number" step="any" min="0" data-field="unit_cost" name="<?= $name('unit_cost') ?>" value="<?= $val('unit_cost') ?>"></td>
    <td style="width:120px"><input class="input num" type="number" step="0.01" min="0" data-field="line_total" name="<?= $name('line_total') ?>" value="<?= $val('line_total') ?>"></td>
    <td style="width:120px"><input class="input" data-field="batch_no" data-lot-field name="<?= $name('batch_no') ?>" value="<?= e($l['batch_no'] ?? '') ?>" maxlength="60" placeholder="lot no."></td>
    <td style="width:150px"><input class="input" type="date" data-field="expiry_date" data-lot-field name="<?= $name('expiry_date') ?>" value="<?= e($l['expiry_date'] ?? '') ?>"></td>
  <?php else: ?>
    <td style="min-width:200px">
      <select class="input" data-field="batch_id" data-lot-field name="<?= $name('batch_id') ?>">
        <?= options($l['batches'] ?? [], $l['batch_id'] ?? '', 'FEFO (earliest expiry first)') ?>
      </select>
    </td>
    <td class="right nowrap" style="width:120px" data-est></td>
  <?php endif; ?>
  <td style="min-width:120px"><input class="input" data-field="notes" name="<?= $name('notes') ?>" value="<?= e($l['notes'] ?? '') ?>" maxlength="255"></td>
  <td style="width:36px"><button type="button" class="icon-btn" data-remove-row title="Remove line">✕</button></td>
</tr>
