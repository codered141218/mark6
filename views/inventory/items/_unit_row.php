<?php /** One pack / purchase unit row ("1 box = 100 tab, sells at ₱500, barcode …"). Variables: $i, $r, $uomOptions, $baseAbbr, $readonly */
$num = fn ($k) => isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null ? e((float) $r[$k]) : '';
?>
<tr data-row>
  <td class="nowrap"><span class="muted">1</span>
    <select class="input" style="width:170px;display:inline-block" name="uoms[<?= e($i) ?>][uom_id]" data-field="uom_id"><?= options($uomOptions, $r['uom_id'] ?? '', '— unit —') ?></select></td>
  <td class="nowrap"><span class="muted">=</span>
    <input class="input num" style="width:100px;display:inline-block" type="number" step="any" min="0" name="uoms[<?= e($i) ?>][factor]" data-field="factor" value="<?= $num('factor') ?>" placeholder="qty">
    <span class="bold" data-base-abbr><?= e($baseAbbr) ?></span></td>
  <td><input class="input num" style="width:120px" type="number" step="0.01" min="0" name="uoms[<?= e($i) ?>][price]" data-field="price" value="<?= $num('price') ?>" placeholder="not sold"></td>
  <td class="right nowrap muted small" data-pack-margin></td>
  <td><input class="input" style="width:170px" name="uoms[<?= e($i) ?>][barcode]" data-field="barcode" value="<?= e($r['barcode'] ?? '') ?>" maxlength="60" placeholder="scan or type"></td>
  <td style="width:36px"><?php if (!$readonly): ?><button type="button" class="icon-btn" data-remove-row title="Remove">✕</button><?php endif; ?></td>
</tr>
