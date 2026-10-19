<?php
/**
 * POS cashier screen (single page, driven by public/assets/js/pos.js).
 * Variables: $boot = PosApiController::bootstrap() (session, tax, payment methods, business, user, permissions).
 * The page stays loaded all shift so a Bluetooth printer connection is kept alive.
 */
use App\Core\DB;

// Extra start-up data the POS needs that /api/pos/state does not carry.
$boot['expense_accounts'] = can('pos.petty_cash')
    ? array_map(fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name']], DB::all("SELECT id, name FROM accounts WHERE type = 'expense' AND active = 1 ORDER BY code"))
    : [];
$boot['today'] = today();
$boot['server_now'] = now();
$boot['links'] = [
    'backOffice' => can('dashboard.view', 'inventory.view', 'reports.sales', 'finance.view', 'admin.settings') ? url('/') : null,
    'printerSetup' => url('/printer'),
];
?>
<div class="pos is-order pane-menu" id="pos">
  <header class="pos-top" id="pos-top"></header>

  <div class="pos-body">
    <!-- Held sales (sales put on hold to resume later) -->
    <section class="board-view" id="board"></section>

    <!-- Sale screen: items / scanner on the left, the current sale on the right -->
    <section class="pos-left menu-pane" id="menu-pane">
      <div class="search-row">
        <div class="search-box">
          <input class="input pos-search" id="pos-search" type="text" placeholder="Scan barcode or search name, generic, brand, SKU…" autocomplete="off" autocorrect="off" spellcheck="false" enterkeyhint="go">
          <button class="search-clear hidden" type="button" id="pos-search-clear" aria-label="Clear search">✕</button>
        </div>
        <button class="btn qty-btn" type="button" id="pos-qty" data-act="qty" title="Quantity for the next item (or type 3* before scanning)">Qty</button>
        <button class="btn btn-primary search-done" type="button" id="pos-search-done">Done</button>
      </div>
      <div class="cat-bar" id="cat-bar"></div>
      <div class="tiles" id="tiles"></div>
    </section>
    <aside class="pos-right order-pane" id="order-pane"></aside>
  </div>

  <!-- Phones: switch between items and the sale -->
  <nav class="pos-bottom" id="pos-bottom"></nav>
</div>

<form id="logout-form" method="post" action="<?= url('/logout') ?>" hidden><?= csrf_field() ?></form>
<script>window.POS_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
