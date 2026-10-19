<?php
/** Dashboard. Variables: $d (Dashboard::data), $from, $to, $singleDay, $dailySeries, $hourlySales, $hourlyReceipts */
$k = $d['kpi'];
$p = $d['position'];
$stat = function (string $label, string $value, string $sub = '', string $tone = '') {
    return '<div class="stat' . ($tone ? ' stat-' . $tone : '') . '"><div class="stat-label">' . e($label) . '</div>'
        . '<div class="stat-value">' . e($value) . '</div>' . ($sub !== '' ? '<div class="stat-sub">' . e($sub) . '</div>' : '') . '</div>';
};
$pct = fn ($v) => number_format((float) $v, 1) . '%';
$marginTone = $k['margin_pct'] < 0 ? 'red' : ($k['margin_pct'] < 10 ? 'amber' : 'green');
$x = $d['expiry'];
?>
<div class="page-header">
  <div>
    <h1>Dashboard</h1>
    <p class="muted"><?= e(range_label($from, $to)) ?></p>
  </div>
</div>
<?= view('partials/daterange', ['from' => $from, 'to' => $to], null) ?>

<div class="stack">
  <?php if ($d['session']): ?>
    <div class="alert alert-success">Business day <b><?= e(fmt_date($d['session']['business_date'])) ?></b> is open ·
      <?= (int) $d['open_tickets']['cnt'] ?> held sale(s) worth <?= peso($d['open_tickets']['amt']) ?>.</div>
  <?php else: ?>
    <div class="alert alert-warn">No business day is open on the POS.</div>
  <?php endif; ?>

  <div class="stats">
    <?= $stat('Net sales', peso($k['net_sales']), number_format($k['receipts']) . ' receipts · ' . qty($k['items_sold']) . ' items', 'brand') ?>
    <?= $stat('Sales net of VAT', peso($k['net_of_vat']), 'VAT ' . money($k['vat'])) ?>
    <?= $stat('Average basket', peso($k['avg_ticket']), $k['receipts'] ? qty($k['items_sold'] / $k['receipts']) . ' items per receipt' : '') ?>
    <?= $stat('Gross profit', peso($k['gross_profit']), 'COGS ' . money($k['cogs']), $k['gross_profit'] >= 0 ? 'green' : 'red') ?>
    <?= $stat('Gross margin %', $pct($k['margin_pct']), 'of sales net of VAT', $marginTone) ?>
    <?= $stat('Discounts given', peso($k['discounts']), 'SC / PWD / promo') ?>
    <?= $stat('Returns & refunds', number_format($k['returns']), peso($k['return_amount']), $k['returns'] ? 'amber' : '') ?>
    <?= $stat('Voided receipts', number_format($k['voids']), peso($k['void_amount']), $k['voids'] ? 'red' : '') ?>
    <?= $stat('Damaged / expired', peso($k['wastage']), $k['net_of_vat'] ? $pct($k['wastage'] / $k['net_of_vat'] * 100) . ' of sales' : '', $k['wastage'] ? 'amber' : '') ?>
    <?= $stat('Purchases (stock in)', peso($k['purchases'])) ?>
    <?= $stat('Operating expenses', peso($k['operating_expenses']), 'incl. petty cash ' . money($k['petty_cash'])) ?>
    <?= $stat('Est. net income', peso($k['net_income_est']), 'revenue − COGS − expenses', $k['net_income_est'] >= 0 ? 'green' : 'red') ?>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h3><?= $singleDay ? 'Sales by hour' : 'Daily net sales' ?></h3></div>
      <div class="card-body"><?= view('dashboard/_bar_chart', ['bars' => $singleDay ? $hourlySales : $dailySeries], null) ?></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Sales by category</h3></div>
      <div class="card-body"><?= view('dashboard/_hbars', ['bars' => array_map(fn ($c) => ['label' => $c['name'], 'value' => (float) $c['amount']], $d['categories'])], null) ?></div>
    </div>
  </div>

  <div class="grid-3">
    <div class="card">
      <div class="card-head"><h3>Top 10 items</h3>
        <?php if (can('reports.sales')): ?><a class="small" href="<?= e(url('/reports/sales', ['report' => 'items', 'from' => $from, 'to' => $to])) ?>">All items →</a><?php endif; ?>
      </div>
      <div class="card-body"><?= view('dashboard/_hbars', ['bars' => array_map(fn ($i) => ['label' => $i['name'], 'value' => (float) $i['amount'], 'sub' => '×' . qty($i['qty'])], $d['top_items'])], null) ?></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Payment mix</h3></div>
      <div class="card-body">
        <?= view('dashboard/_hbars', ['bars' => array_map(fn ($p) => ['label' => $p['label'], 'value' => (float) $p['amount']], $d['payments'])], null) ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Busiest hours</h3><span class="muted small">receipts per hour</span></div>
      <div class="card-body"><?= view('dashboard/_bar_chart', ['bars' => $hourlyReceipts, 'height' => 200, 'width' => 400, 'integer' => true, 'format' => fn ($v) => number_format($v) . ' receipts'], null) ?></div>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h3>Cash &amp; financial position</h3><span class="muted small">as of <?= e(fmt_date($to)) ?></span></div>
      <table class="table dense">
        <tbody>
          <tr><td>Cash on hand</td><td class="right"><?= peso($p['cash_on_hand']) ?></td></tr>
          <tr><td>Petty cash fund</td><td class="right"><?= peso($p['petty_cash']) ?></td></tr>
          <?php foreach ($p['banks'] as $b): ?>
            <tr><td><?= e($b['bank_name']) ?><?= $b['account_no'] ? ' ···' . e(substr($b['account_no'], -4)) : '' ?></td><td class="right"><?= peso($b['balance']) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Inventory at cost (current) <span class="muted small">· retail <?= money($p['inventory_retail']) ?></span></td><td class="right"><?= peso($p['inventory_value']) ?></td></tr>
          <tr><td>Receivables <?= $p['ar_overdue'] > 0 ? badge('overdue ' . money($p['ar_overdue']), 'red') : '' ?></td><td class="right"><?= peso($p['ar_total']) ?></td></tr>
          <tr><td>Payables <?= $p['ap_due_count'] > 0 ? badge($p['ap_due_count'] . ' due within 7 days (' . money($p['ap_due_7d']) . ')', 'amber') : '' ?></td><td class="right"><?= peso($p['ap_total']) ?></td></tr>
          <tr><td>Employee advances outstanding</td><td class="right"><?= peso($p['ca_outstanding']) ?></td></tr>
        </tbody>
      </table>
      <?php if ($p['ca_pending'] > 0 && can('ca.approve')): ?>
        <div class="card-body"><div class="alert alert-warn" style="margin:0">
          <a href="<?= url('/cash-advances') ?>"><?= $p['ca_pending'] ?> cash advance request(s) waiting for approval (<?= peso($p['ca_pending_amount']) ?>) →</a>
        </div></div>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Low stock — reorder now</h3>
        <?php if (can('reports.inventory')): ?><a class="small" href="<?= url('/reports/inventory', ['report' => 'reorder']) ?>">Reorder report →</a><?php endif; ?>
      </div>
      <?php if (!$d['low_stock']): ?>
        <div class="empty">All stock levels are above reorder points.</div>
      <?php else: ?>
        <table class="table dense">
          <thead><tr><th>Item</th><th class="right">On hand</th><th class="right">Reorder pt.</th><th class="right">Order qty</th></tr></thead>
          <tbody>
          <?php foreach ($d['low_stock'] as $i): ?>
            <tr>
              <td><?= e($i['name']) ?></td>
              <td class="right <?= (float) $i['stock_qty'] <= 0 ? 'text-red bold' : 'text-amber' ?>"><?= qty($i['stock_qty']) ?> <?= e($i['uom']) ?></td>
              <td class="right"><?= qty($i['reorder_point']) ?></td>
              <td class="right"><?= qty($i['order_qty']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
            <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Expiry watch</h3>
      <span class="row gap-sm">
        <?= $x['expired_lots'] ? badge($x['expired_lots'] . ' expired · ' . money($x['expired_value']), 'red') : '' ?>
        <?= $x['expiring_lots'] ? badge($x['expiring_lots'] . ' expiring within ' . $x['days'] . ' days · ' . money($x['expiring_value']), 'amber') : '' ?>
        <?php if (can('reports.inventory')): ?><a class="small" href="<?= url('/reports/inventory', ['report' => 'expiry']) ?>">Expiry report →</a><?php endif; ?>
      </span>
    </div>
    <?php if (!$d['expiring']): ?>
      <div class="empty">No lots expired or expiring within <?= (int) $x['days'] ?> days.</div>
    <?php else: ?>
      <table class="table dense">
        <thead><tr><th>Item</th><th>Lot</th><th>Expiry</th><th class="right">Days</th><th class="right">On hand</th><th class="right">Value</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($d['expiring'] as $b): ?>
          <tr>
            <td><?= e($b['item']) ?> <span class="muted small"><?= e($b['sku']) ?></span></td>
            <td><?= e($b['batch_no'] ?: '—') ?></td>
            <td><?= e(fmt_date($b['expiry_date'])) ?></td>
            <td class="right <?= $b['days_left'] < 0 ? 'text-red bold' : 'text-amber' ?>"><?= (int) $b['days_left'] ?></td>
            <td class="right"><?= qty($b['qty_on_hand']) ?> <?= e($b['uom']) ?></td>
            <td class="right"><?= money($b['value']) ?></td>
            <td><?= badge($b['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
