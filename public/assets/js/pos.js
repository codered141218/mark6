/**
 * POS cashier screen (plain JavaScript, no framework).
 *
 * The page (views/pos/index.php) stays loaded all shift: everything happens through the JSON API under
 * /api/pos (app/Controllers/Pos/PosApiController.php), so a Bluetooth printer connection is never lost.
 *
 * Screens
 *   Sale screen   - item tiles / barcode search on the left, the current sale on the right (phones: one at a time).
 *                   This is the home screen: a new sale is always ready for the next customer.
 *   Held sales    - cards of sales put on hold (customer went back for something) to resume later.
 * Workflow: scan or tap items -> (discount / price change / customer) -> PAY -> receipt -> next sale.
 * Returns and refunds are made from the Receipts dialog against the original receipt.
 *
 * Built for Android tablets and PCs with a barcode scanner: numbers (amounts, quantities, cash counts, PINs) are
 * typed on an on-screen keypad so the phone keyboard never covers the screen; text fields keep the normal keyboard
 * and the layout shrinks above it (see section 8, "Screen & keyboard"). A scanner just types into the search box
 * and presses Enter: item barcodes, pack barcodes (1 box = 100 tab), SKUs and weighing-scale barcodes all work,
 * and "3*" before a scan sells 3 of the next item.
 *
 * Sections of this file
 *   1. Helpers            small formatting / DOM utilities
 *   2. State              everything the screen knows (S) + start-up data from PHP (B)
 *   3. API calls          one function per endpoint
 *   4. Sale actions       the business flow (add item, discount, hold, pay, ...)
 *   5. Rendering          top bar, held sales, items, sale panel, phone bottom bar
 *   6. Dialogs            generic modal + keypad / PIN pad, then one function per dialog
 *   7. Printing hooks     calls into window.Printer (public/assets/js/printer.js) with error handling
 *   8. Screen & keyboard  on-screen keyboard handling, install as an app
 *   9. Events & start-up  click delegation (data-act="..."), search box / scanner, auto-refresh
 *
 * Buttons carry data-act="name"; one click handler per area maps the name to a function (see section 9).
 */
(function () {
  'use strict';

  // =====================================================================================================
  // 1. Helpers
  // =====================================================================================================
  const $ = (sel, root = document) => root.querySelector(sel);
  const esc = App.esc;
  const peso = App.peso;
  const money = App.money;
  const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
  const qtyStr = (n) => App.qty(n);
  const NOTE_CHIPS = ['Gift', 'Senior', 'Delivery', 'Pick-up later', 'Fragile', 'Check expiry'];
  const MONEY_KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'];
  const INT_KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', 'C', '0', '⌫'];
  const finePointer = window.matchMedia('(pointer: fine)').matches; // mouse: autofocus inputs; touch: avoid popping the keyboard

  /** "2026-10-08 13:05:00" -> Date (server times are local times). */
  const toDate = (s) => new Date(String(s).replace(' ', 'T'));
  const fmtTime = (s) => (s ? toDate(s).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '');
  function fmtDate(s) {
    if (!s) return '';
    const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }
  /** Server clock minus this device's clock (time zone / clock differences), so elapsed times are right. */
  const clockOffset = toDate(window.POS_BOOT.server_now).getTime() - Date.now();
  const minutesSince = (s) => (Date.now() + clockOffset - toDate(s).getTime()) / 60000;
  /** Minutes since a sale was started, as "12m" / "1h 05m". */
  function elapsed(s) {
    const m = Math.max(0, Math.floor(minutesSince(s)));
    return m < 60 ? `${m}m` : `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
  }
  const denomLabel = (d) => (d >= 1 ? '₱' + d.toLocaleString() : `${Math.round(d * 100)}¢`);
  const plural = (n, word) => `${qtyStr(n)} ${word}${Number(n) === 1 ? '' : 's'}`;

  // =====================================================================================================
  // 2. State
  // =====================================================================================================
  /** Start-up data embedded by views/pos/index.php (= PosApiController::bootstrap() + accounts/links). */
  const B = window.POS_BOOT;
  const CAN = B.can || {};
  const can = (...perms) => perms.some((p) => CAN[p]);
  /** True when the user lacks a permission, so a manager PIN must be asked first. */
  const needPin = (perm) => !can(perm);

  const S = {
    session: B.session,             // open business day (cash session) or null
    menu: { categories: [], items: [] },   // items: one entry per sellable unit (key "itemId:uomId")
    held: [],                       // held (open) sales for the held-sales screen
    sale: null,                     // sale being rung up: a ticket from the server, or a local draft (no items yet)
    view: 'sale',                   // 'sale' | 'held'
    pane: 'menu',                   // phones only: 'menu' | 'order'
    cat: 'all',                     // selected category id or 'all'
    multiplier: 1,                  // quantity for the next item ("3*" on the scanner or the Qty button)
    pending: [],                    // items tapped / scanned whose server reply has not arrived yet (shown greyed)
    highlight: null,                // {item_id, uom_id} of the line last tapped / scanned: highlighted and scrolled into view
    queue: Promise.resolve(),       // sale changes run one after another (fast taps / scans never get lost)
    installPrompt: null,            // Chrome's "install app" event, when available
  };

  const activeLines = (t) => (t ? t.items.filter((i) => i.status === 'active') : []);
  const itemCount = (t) => activeLines(t).reduce((s, i) => s + Number(i.qty), 0);
  const isSc = (kind) => kind === 'sc' || kind === 'pwd';
  const hasRx = (t) => activeLines(t).some((i) => i.rx_required);
  const saleTitle = (t) => t.customer_name || (t.draft ? 'New sale' : t.ticket_no);

  /** A new sale lives only in the browser until its first item is added (so no empty sales are left behind). */
  function draftSale() {
    return {
      draft: true, id: null, ticket_no: 'New sale', customer_name: null, customer_id: null, notes: null, rx_note: null, status: 'open',
      items: [], payments: [], returns: [], discount_type: 'none', discount_id: null, discount_name: null, discount_rate: 0,
      sc_count: 0, sc_details: [], subtotal: 0, discount_amount: 0, sc_discount: 0, promo_discount: 0,
      vat_amount: 0, vat_exempt_sales: 0, vatable_sales: 0, total: 0, refunded_total: 0, created_at: null, created_by_name: B.user.name,
    };
  }

  // ---- discount presets (Administration → Discounts): {id, name, kind: sc|pwd|percent|amount, value|null, scope, requires_approval}
  const presetsFor = (scope) => (B.discounts || []).filter((p) => p.scope === 'both' || p.scope === scope);
  const presetNeedsPin = (p) => !!p.requires_approval && needPin('pos.discount');
  function presetHint(p) {
    if (isSc(p.kind)) return `${B.tax.vatRegistered ? 'VAT-exempt + ' : ''}${Math.round(B.tax.scRate * 100)}%`;
    if (p.value === null) return p.kind === 'percent' ? 'enter %' : 'enter ₱';
    return p.kind === 'percent' ? `${Number(p.value)}%` : peso(p.value);
  }

  // =====================================================================================================
  // 3. API calls (App.api adds the CSRF header and throws Error(message) on failure)
  // =====================================================================================================
  const get = (path) => App.api('GET', '/api/pos' + path);
  const post = (path, body) => App.api('POST', '/api/pos' + path, body || {});
  const Api = {
    menu: () => get('/menu'),
    lookup: (code) => get('/lookup?code=' + encodeURIComponent(code)),       // {found, item_id, uom_id, qty, price, scale}
    held: () => get('/orders'),                                             // {orders, session}
    create: (data) => post('/orders', data),
    show: (id) => get(`/orders/${id}`),
    update: (id, data) => post(`/orders/${id}`, data),                      // customer_name, customer_id, notes, rx_note
    addItem: (id, data) => post(`/orders/${id}/items`, data),               // {item_id, qty, uom_id, price, pin}
    updateLine: (id, line, data) => post(`/orders/${id}/items/${line}`, data),   // {qty, notes, price, pin}
    removeLine: (id, line) => post(`/orders/${id}/items/${line}/void`, {}),
    discountLine: (id, line, data) => post(`/orders/${id}/items/${line}/discount`, data),  // {discount_id, value, sc_person, pin}
    discount: (id, data) => post(`/orders/${id}/discount`, data),           // {discount_id, value, sc_details, pin}
    pay: (id, payments, extra) => post(`/orders/${id}/pay`, { payments, ...extra }),
    void: (id, reason, pin) => post(`/orders/${id}/void`, { reason, pin }),
    reprint: (id) => post(`/orders/${id}/reprint`),
    receipts: (q) => get('/receipts' + (q ? '?q=' + encodeURIComponent(q) : '')),
    customers: () => get('/customers'),
    createReturn: (id, data) => post(`/orders/${id}/returns`, data),        // {lines:[{id, qty}], method, reason, restock, pin} -> {return, ticket}
    voidReturn: (id, reason, pin) => post(`/returns/${id}/void`, { reason, pin }),
    openDay: (data) => post('/day/open', data),
    xreading: () => get('/day/xreading'),
    closeDay: (data) => post('/day/close', data),
    payouts: () => get('/payouts'),
    addPayout: (data) => post('/payouts', data),
    voidPayout: (id, reason, pin) => post(`/payouts/${id}/void`, { reason, pin }),
  };

  const showError = (e) => App.toast(e.message, 'error');

  /** Show a spinner on a button while fn() runs (errors are passed on). */
  async function spin(btn, fn) {
    if (!btn) return fn();
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = label; }
  }
  /** Run an API call with a busy button. Returns the result, or null after showing the error. */
  async function run(fn, btn) {
    try { return await spin(btn, fn); } catch (e) { showError(e); return null; }
  }
  /**
   * Run an action that may need a manager's approval: fn(pin) calls the API.
   * ask = true asks for the PIN first (the user's role lacks the permission); if the server still says a PIN is
   * needed (or it was wrong) the PIN pad opens again. Returns the result, or null when cancelled / failed.
   */
  async function withPin(fn, { ask = false, btn = null } = {}) {
    let pin = '';
    if (ask) { pin = await askPin(); if (pin === null) return null; }
    for (;;) {
      try { return await spin(btn, () => fn(pin)); } catch (e) {
        if (!/manager (authorization|PIN)/i.test(e.message)) { showError(e); return null; }
        pin = await askPin(pin ? 'Wrong PIN, or that manager may not approve this. Try again.' : e.message);
        if (pin === null) return null;
      }
    }
  }

  async function loadMenu() {
    try { S.menu = await Api.menu(); renderMenu(); } catch (e) { showError(e); }
  }
  async function loadHeld() {
    try {
      const r = await Api.held();
      S.held = r.orders;
      if ((r.session && r.session.id) !== (S.session && S.session.id)) S.session = r.session; // opened / closed on another terminal
      renderTop();
      if (S.view === 'held') renderHeld();
    } catch (e) { showError(e); }
  }

  // =====================================================================================================
  // 4. Sale actions
  // =====================================================================================================
  /** Create the sale on the server (when its first item is added). Returns the server ticket. */
  async function ensureSale(d) {
    if (!d.draft) return d;
    if (!d.created) {
      d.created = await Api.create({ customer_name: d.customer_name, customer_id: d.customer_id, notes: d.notes });
      if (S.sale === d) S.sale = d.created;
    }
    return d.created;
  }

  /**
   * Queue a change to the current sale: fn(sale) calls the API and returns the updated ticket.
   * Changes run one after another, each on the sale that was open when the button was pressed.
   */
  function mutate(fn) {
    const target = S.sale;
    const job = S.queue.then(async () => setSale(await fn(await ensureSale(target))));
    S.queue = job.catch(() => {});
    return job;
  }

  /** Show an updated ticket, unless the cashier already moved to another sale. */
  function setSale(t) {
    if (S.sale && (S.sale.id === t.id || (S.sale.created && S.sale.created.id === t.id))) {
      S.sale = t;
      if (S.view === 'sale') renderOrder();
    }
    return t;
  }

  /** Start a fresh sale (the home screen). */
  function newSale(pane = 'menu') {
    S.sale = draftSale();
    S.view = 'sale';
    S.pane = pane;
    S.multiplier = 1;
    S.pending = [];
    S.highlight = null;
    $('#pos-search').value = '';
    render();
    if (finePointer) $('#pos-search').focus();   // never on tablets: it would pop the keyboard
  }

  async function resumeSale(id) {
    const t = await run(() => Api.show(id));
    if (!t) return;
    if (t.status !== 'open') { App.toast(`${t.ticket_no} is already ${t.status}`, 'info'); loadHeld(); return; }
    S.sale = t;
    S.view = 'sale';
    S.pane = 'order';
    render();
  }

  /** Put the current sale on hold (it stays open on the server) and start a new one. */
  function holdSale() {
    const t = S.sale;
    if (!t || t.draft || !activeLines(t).length) { newSale(); return; }
    S.queue.then(() => {
      App.toast(`${t.ticket_no} on hold${t.customer_name ? ' for ' + t.customer_name : ''}`);
      newSale();
      loadHeld();
    });
  }

  function showHeld() {
    S.view = 'held';
    render();
    loadHeld();
  }

  /**
   * Add an item to the sale. data = {item_id, uom_id (pack unit or null), qty}; the pending multiplier ("3*") is
   * applied to the first item added after it was set.
   */
  function addItem(data, menuItem, pend = null) {
    if (!S.session) { App.toast('Open the business day first', 'error'); dropPending(pend); return; }
    if (!S.sale || S.view !== 'sale') newSale();
    const qty = (data.qty || 1) * S.multiplier;
    if (S.multiplier !== 1) { S.multiplier = 1; renderMultiplier(); }
    if (menuItem && menuItem.item_type === 'product' && menuItem.stock_qty <= 0) App.toast(`${menuItem.name}: out of stock in the system`, 'info');
    // Show the line at once (greyed) while the server records it: on a remote database the reply takes a moment
    S.highlight = { item_id: data.item_id, uom_id: data.uom_id || null };
    pend = pend || showPending(menuItem ? menuItem.name : 'Item', qty, menuItem ? menuItem.price : null, menuItem ? menuItem.uom : '');
    Object.assign(pend, { qty, price: menuItem ? menuItem.price : pend.price, uom: menuItem ? menuItem.uom : pend.uom, name: menuItem ? menuItem.name : pend.name });
    renderOrder();
    mutate((t) => Api.addItem(t.id, { item_id: data.item_id, uom_id: data.uom_id || null, qty }))
      .then(() => dropPending(pend), (e) => { dropPending(pend); showError(e); });
  }

  /** A placeholder line in the sale panel until the server confirms the item. */
  function showPending(name, qty = 1, price = null, uom = '') {
    const pend = { name, qty, price, uom };
    S.pending.push(pend);
    renderOrder();
    return pend;
  }
  function dropPending(pend) {
    if (!pend) return;
    S.pending = S.pending.filter((p) => p !== pend);
    renderOrder();
  }

  /** A code typed or scanned into the search box: "3*" sets the quantity, "3*CODE" sells 3, otherwise look the code up. */
  async function scan(raw) {
    let code = raw.trim();
    if (!code) return;
    let qty = 1;
    const mult = code.match(/^(\d+(?:\.\d+)?)\s*[*xX×]\s*(.*)$/);
    if (mult) {
      qty = Number(mult[1]);
      code = mult[2].trim();
      if (!(qty > 0)) { App.toast('Quantity must be greater than zero', 'error'); return; }
      if (!code) { S.multiplier = qty; renderMultiplier(); App.toast(`Next item × ${qtyStr(qty)}`, 'info'); return; }
    }
    if (!S.sale || S.view !== 'sale') newSale();
    const pend = showPending(code, qty);   // the scanned code shows up immediately; the name follows from the lookup
    try {
      const r = await Api.lookup(code);
      if (r.found) {
        const mi = S.menu.items.find((i) => i.id === r.item_id && (i.uom_id || null) === (r.uom_id || null));
        pend.name = r.name;
        pend.price = r.price;
        addItem({ item_id: r.item_id, uom_id: r.uom_id, qty: qty * (r.qty || 1) }, mi, pend);
        if (r.scale) App.toast(`${r.name}: ${qtyStr(r.qty)} kg from the scale barcode`, 'info');
        return;
      }
    } catch (e) { dropPending(pend); showError(e); return; }
    const first = visibleItems()[0];
    if (first) addItem({ item_id: first.id, uom_id: first.uom_id, qty }, first, pend);
    else { dropPending(pend); App.toast(`No item matches "${code}"`, 'error'); }
  }

  /**
   * Apply a discount preset to the whole receipt (line = null) or one sale line.
   * Open presets (no value) ask the % / ₱ on the keypad; extra = SC/PWD details. Returns the ticket or null.
   */
  async function applyPreset(p, line = null, extra = {}, btn = null) {
    let value;
    if (p.value === null && !isSc(p.kind)) {
      value = await askNumber({ title: p.name, label: p.kind === 'percent' ? 'Discount in %' : 'Discount amount in ₱', mode: 'money',
        prefix: p.kind === 'amount' ? '₱' : '', suffix: p.kind === 'percent' ? '%' : '', max: p.kind === 'percent' ? 100 : null, okText: 'Apply' });
      if (value === null) return null;
    }
    const data = { discount_id: p.id, value, ...extra };
    const t = await withPin((pin) => mutate((o) => (line
      ? Api.discountLine(o.id, line.id, { ...data, pin })
      : Api.discount(o.id, { ...data, pin }))), { ask: presetNeedsPin(p), btn });
    if (t) App.toast(`${p.name} applied${line ? ' to ' + line.name : ''}`);
    return t;
  }

  async function startPay() {
    if (!activeLines(S.sale).length) return;
    await S.queue;
    payDialog(S.sale);
  }

  /** After a successful payment: success screen, receipt, fresh sale for the next customer. */
  async function afterPaid(t) {
    newSale();
    loadMenu(); // stock changed
    const cfg = Printer.config();
    const job = paidDialog(t, cfg.autoPrint);
    if (cfg.autoPrint) job.printed = await Print.receipt(t, false);
  }

  /** Cancel the sale being rung up (nothing was paid yet). */
  async function cancelSale() {
    const o = S.sale;
    if (!o || o.draft || !activeLines(o).length) { newSale(); return; }
    const ok = await App.ask({ title: `Cancel ${o.ticket_no}?`, message: 'All items on this sale are removed. Nothing has been charged.', danger: true, okText: 'Cancel sale' });
    if (!ok) return;
    await S.queue;
    if (await run(() => Api.void(o.id, 'Cancelled at the counter', ''))) {
      App.toast(`${o.ticket_no} cancelled`);
      newSale();
      loadHeld();
    }
  }

  function logout() {
    App.ask({ title: 'Log out?', message: S.sale && !S.sale.draft && activeLines(S.sale).length ? 'The current sale stays on hold.' : '', okText: 'Log out' })
      .then((ok) => { if (ok) $('#logout-form').submit(); });
  }

  // =====================================================================================================
  // 5. Rendering
  // =====================================================================================================
  function render() {
    const root = $('#pos');
    root.classList.toggle('is-board', S.view === 'held');
    root.classList.toggle('is-order', S.view === 'sale');
    root.classList.toggle('pane-menu', S.pane === 'menu');
    root.classList.toggle('pane-order', S.pane === 'order');
    renderTop();
    if (S.view === 'held') renderHeld();
    else { renderMenu(); renderOrder(); }
  }

  function renderTop() {
    const s = S.session;
    const p = printerState();
    const top = [
      '<span class="brand-logo pos-logo" title="Mark6 Retail Suite">M6</span>',
      `<span class="title">${esc(B.business.name)}</span>`,
      s ? `<span class="chip chip-open">● Day open · ${esc(fmtDate(s.business_date))}</span>` : '<span class="chip chip-closed">Day not open</span>',
      '<span class="grow"></span>',
      S.view === 'held' ? '<button class="btn btn-primary" type="button" data-act="newsale">+ New sale</button>' : '',
      `<button class="btn btn-ghost${S.view === 'held' ? ' on' : ''}" type="button" data-act="held">⏸ Held <span class="count">${S.held.length}</span></button>`,
      '<button class="btn btn-ghost" type="button" data-act="receipts">Receipts</button>',
      can('pos.petty_cash') && s ? '<button class="btn btn-ghost" type="button" data-act="payout">Payout</button>' : '',
      can('pos.xreading', 'pos.close_day') && s ? '<button class="btn btn-ghost" type="button" data-act="xread">X-Read</button>' : '',
      can('pos.close_day') && s ? '<button class="btn btn-ghost" type="button" data-act="eod">End of Day</button>' : '',
      can('pos.open_day') && !s ? '<button class="btn btn-success" type="button" data-act="openday">Open Day</button>' : '',
      `<button class="btn btn-ghost printer-chip ${p.cls}" type="button" data-act="printer" title="Printer">⎙ ${esc(p.text)}${p.queue ? ` <span class="count">${p.queue}</span>` : ''}</button>`,
      S.installPrompt ? '<button class="btn btn-ghost install-btn" type="button" data-act="install">⬇ Install app</button>' : '',
      B.links.backOffice ? `<a class="btn btn-ghost" href="${esc(B.links.backOffice)}">Back office</a>` : '',
      `<span class="chip user-chip">${esc(B.user.name)}</span>`,
      '<button class="btn btn-ghost" type="button" data-act="logout">Log out</button>',
    ];
    $('#pos-top').innerHTML = top.join('');
  }

  function renderHeld() {
    const total = S.held.reduce((sum, o) => sum + o.total, 0);
    const cards = S.held.map((o) => {
      const mins = minutesSince(o.created_at);
      return `<div class="order-card" role="button" tabindex="0" data-act="resume" data-id="${o.id}">
          <span class="oc-head"><span class="oc-title">${esc(o.customer_name || o.ticket_no)}</span>
            <span class="oc-time${mins > 120 ? ' late' : mins > 30 ? ' slow' : ''}">${elapsed(o.created_at)}</span>
            <button type="button" class="oc-more" data-act="cardMenu" data-id="${o.id}" aria-label="More">⋯</button></span>
          <span class="oc-sub">${esc([o.customer_name ? o.ticket_no : '', o.created_by_name, o.notes].filter(Boolean).join(' · '))}</span>
          <span class="oc-foot"><span>${plural(o.item_count, 'item')}</span><b>${peso(o.total)}</b></span>
        </div>`;
    }).join('');
    const closedMsg = can('pos.open_day') ? 'Press “Open Day” and enter the beginning cash to start selling.' : 'Ask a cashier or manager to open the day.';
    $('#board').innerHTML = `
      ${S.session ? '' : `<div class="alert alert-warn">The business day is not open. ${closedMsg}</div>`}
      <div class="board-actions">
        <button class="btn btn-primary btn-xl" type="button" data-act="newsale" ${S.session ? '' : 'disabled'}>+ New sale</button>
        <span class="grow"></span>
        <span class="muted board-sum">${plural(S.held.length, 'held sale')} · ${peso(total)}</span>
        <button class="btn btn-lg" type="button" data-act="refresh" title="Refresh">↻</button>
      </div>
      <div class="board-grid">${cards || '<div class="empty board-empty">No sales on hold. Press “Hold” on a sale to keep it for later.</div>'}</div>`;
  }

  /** Items shown as tiles: search (exact barcode / SKU / PLU first, then name / generic / brand) or the selected category. */
  function visibleItems() {
    const q = $('#pos-search').value.trim();
    const items = S.menu.items;
    if (q) {
      const lq = q.toLowerCase();
      const exact = items.filter((i) => (i.barcode && i.barcode === q) || (i.sku && i.sku.toLowerCase() === lq) || (i.plu && i.plu === q));
      if (exact.length) return exact;
      return items.filter((i) => [i.name, i.generic_name, i.brand, i.sku].some((f) => f && f.toLowerCase().includes(lq)));
    }
    return S.cat === 'all' ? items : items.filter((i) => i.category_id === Number(S.cat));
  }

  function renderMultiplier() {
    const b = $('#pos-qty');
    if (!b) return;
    b.textContent = S.multiplier === 1 ? 'Qty' : `× ${qtyStr(S.multiplier)}`;
    b.classList.toggle('on', S.multiplier !== 1);
  }

  function renderMenu() {
    if (S.view !== 'sale') return;
    const q = $('#pos-search').value.trim();
    $('#pos-search-clear').classList.toggle('hidden', !q);
    const catColor = {};
    S.menu.categories.forEach((c) => { catColor[c.id] = c.color; });
    $('#cat-bar').innerHTML = `<button type="button" class="cat-btn${S.cat === 'all' && !q ? ' active' : ''}" data-cat="all">All</button>`
      + S.menu.categories.map((c) => `<button type="button" class="cat-btn${String(S.cat) === String(c.id) && !q ? ' active' : ''}" data-cat="${c.id}"
          ${c.color ? `style="--c:${esc(c.color)}"` : ''}>${esc(c.name)}</button>`).join('');
    const tiles = visibleItems().map((i) => {
      const product = i.item_type === 'product';
      const left = product ? Math.floor(i.stock_qty / (i.factor || 1)) : null;
      const out = product && left <= 0;
      const color = i.color || catColor[i.category_id];
      const sub = i.pack ? `${esc(i.uom)} of ${qtyStr(i.factor)}` : (i.generic_name || i.brand || '');
      return `<button type="button" class="tile${out ? ' out' : ''}${i.pack ? ' pack' : ''}" data-key="${esc(i.key)}" ${color ? `style="--c:${esc(color)}"` : ''}>
          <span class="tile-name">${esc(i.pack ? i.name.replace(/ \(.*\)$/, '') : i.name)}${i.rx_required ? ' <span class="tile-rx">Rx</span>' : ''}</span>
          ${sub ? `<span class="tile-sub">${sub}</span>` : ''}
          <span><span class="tile-price">${peso(i.price)}</span><span class="tile-unit">/${esc(i.uom)}</span>${product ? `<span class="tile-stock"> · ${out ? 'out of stock' : left + ' left'}</span>` : ''}</span>
        </button>`;
    }).join('');
    $('#tiles').innerHTML = tiles || `<div class="empty" style="grid-column:1/-1">${q ? 'No item matches “' + esc(q) + '”. Press Enter to look the barcode up.' : 'No items. Add sellable items under Inventory → Items & Prices.'}</div>`;
    renderMultiplier();
  }

  function renderOrder() {
    const t = S.sale;
    if (S.view !== 'sale' || !t) return;
    const active = activeLines(t);
    const hl = (l) => S.highlight && l.item_id === S.highlight.item_id && (l.uom_id || null) === S.highlight.uom_id;
    const lines = t.items.map((l) => `
      <div class="tline${hl(l) ? ' selected flash' : ''}" data-act="line" data-id="${l.id}">
        <span class="tline-qty">${qtyStr(l.qty)}<small>${esc(l.uom_label || '')}</small></span>
        <div>
          <div class="tline-name">${esc(l.name)}${l.rx_required ? ' <span class="badge badge-red">Rx</span>' : ''}${l.vat_exempt ? ' <span class="badge badge-gray">VAT-ex</span>' : ''}</div>
          ${l.generic_name ? `<div class="tline-note">${esc(l.generic_name)}</div>` : ''}
          ${l.notes ? `<div class="tline-note">${esc(l.notes)}</div>` : ''}
          ${l.discount_kind ? `<div class="tline-disc"><span>${esc(l.discount_name || 'Discount')}</span><span>−${money(l.discount_amount)}</span></div>` : ''}
          <div class="muted small">@ ${money(l.price)}${l.factor > 1 ? ` · ${qtyStr(l.qty * l.factor)} pcs` : ''}</div>
        </div>
        <span class="tline-amt">${money(l.line_total)}</span>
      </div>`).join('');
    const receiptDisc = t.discount_type !== 'none' && t.discount_name ? t.discount_name : '';
    const scLabel = `${receiptDisc || 'SC/PWD'} discount${t.sc_count > 1 ? ' ×' + t.sc_count : ''}`;
    const promoLabel = `Discounts${receiptDisc && !isSc(t.discount_type) ? ' (' + receiptDisc + ')' : ''}`;
    const sub = [t.draft ? 'New sale' : t.ticket_no, t.created_at ? fmtTime(t.created_at) : '', t.created_by_name].filter(Boolean).join(' · ');
    const off = (cond) => (cond ? '' : 'disabled');
    $('#order-pane').innerHTML = `
      <div class="ticket-head">
        <div class="row between gap-sm">
          <button type="button" class="order-title" data-act="details" title="Customer, notes, prescription">
            <b>${esc(t.customer_name || 'Walk-in customer')}</b> <span class="muted small">✎</span>
          </button>
          ${hasRx(t) ? `<span class="badge ${t.rx_note ? 'badge-green' : 'badge-red'}" title="Prescription">${t.rx_note ? 'Rx noted' : 'Rx needed'}</span>` : ''}
        </div>
        <div class="muted small">${esc(sub)}${t.notes ? ' · ' + esc(t.notes) : ''}</div>
      </div>
      <div class="ticket-lines">${lines}${S.pending.map((p) => `
        <div class="tline pending selected"><span class="tline-qty">${qtyStr(p.qty)}<small>${esc(p.uom || '')}</small></span>
          <div><div class="tline-name">${esc(p.name)}</div><div class="muted small">adding…</div></div>
          <span class="tline-amt">${p.price !== null && p.price !== undefined ? money(p.price * p.qty) : '…'}</span></div>`).join('')}${lines || S.pending.length ? '' : '<div class="empty">Scan a barcode or tap items to add them.</div>'}</div>
      <div class="ticket-totals">
        <div class="row"><span>Subtotal · ${plural(itemCount(t), 'item')}</span><span>${money(t.subtotal)}</span></div>
        ${t.sc_discount > 0 ? `<div class="row text-green"><span>${esc(scLabel)}</span><span>−${money(t.sc_discount)}</span></div>` : ''}
        ${t.promo_discount > 0 ? `<div class="row text-green"><span>${esc(promoLabel)}</span><span>−${money(t.promo_discount)}</span></div>` : ''}
        ${B.tax.vatRegistered ? `<div class="row muted small"><span>VAT incl. ${money(t.vat_amount)}${t.vat_exempt_sales > 0 ? ' · VAT-exempt ' + money(t.vat_exempt_sales) : ''}</span></div>` : ''}
        <div class="row grand"><span>TOTAL${S.pending.length ? ' <span class="muted small">updating…</span>' : ''}</span><span>${peso(t.total)}</span></div>
      </div>
      <div class="ticket-actions">
        <button type="button" class="btn" data-act="discount" ${off(active.length)}>Discount</button>
        <button type="button" class="btn" data-act="details">Customer</button>
        <button type="button" class="btn" data-act="hold" ${off(active.length && !t.draft)}>Hold</button>
        <button type="button" class="btn" data-act="quote" ${off(active.length)}>Print quote</button>
        <button type="button" class="btn btn-danger" data-act="cancel" ${off(active.length)}>Cancel sale</button>
        ${can('pos.settle') ? `<button type="button" class="btn btn-success pay" data-act="pay" ${off(active.length)}>PAY ${peso(t.total)}</button>` : ''}
      </div>`;
    // Follow the line just tapped / scanned (the pending placeholder while it is being added, then the real line)
    const follow = [...$('#order-pane').querySelectorAll('.tline.selected')].pop();
    if (follow) follow.scrollIntoView({ block: 'nearest' });
    renderBottom();
  }

  /** Phones: "Items | Sale · N items · ₱total" switcher. */
  function renderBottom() {
    const t = S.sale;
    if (!t || S.view !== 'sale') { $('#pos-bottom').innerHTML = ''; return; }
    const n = itemCount(t);
    $('#pos-bottom').innerHTML = `
      <button type="button" class="${S.pane === 'menu' ? 'on' : ''}" data-act="pane" data-pane="menu">Items</button>
      <button type="button" class="${S.pane === 'order' ? 'on' : ''}" data-act="pane" data-pane="order">Sale · ${plural(n, 'item')} · <b>${peso(t.total)}</b></button>`;
  }

  // =====================================================================================================
  // 6. Dialogs
  // =====================================================================================================
  /**
   * Open a modal <dialog>. Returns {el, $, close}.
   *   title, body, foot (HTML), cls (extra class), onSubmit (Enter / submit button), onClose,
   *   actions: { name: fn(button, event) } for buttons with data-act="name" inside the dialog.
   * Buttons with data-x close the dialog.
   */
  function modal({ title, body, foot = '', cls = '', onSubmit, onClose, actions = {} }) {
    const d = document.createElement('dialog');
    d.className = `modal pos-dlg ${cls}`;
    d.innerHTML = `<form method="dialog" novalidate>
        <div class="modal-head"><h3>${esc(title)}</h3><button type="button" class="icon-btn" data-x aria-label="Close">✕</button></div>
        <div class="modal-body">${body}</div>
        ${foot ? `<div class="modal-foot">${foot}</div>` : ''}
      </form>`;
    document.body.appendChild(d);
    let closed = false;
    const m = {
      el: d,
      $: (sel) => d.querySelector(sel),
      close() {
        if (closed) return;
        closed = true;
        d.close();
        d.remove();
        if (onClose) onClose();
      },
    };
    d.addEventListener('cancel', (e) => { e.preventDefault(); m.close(); });
    d.querySelector('form').addEventListener('submit', (e) => { e.preventDefault(); if (onSubmit) onSubmit(e.submitter); });
    d.addEventListener('click', (e) => {
      if (e.target.closest('[data-x]')) { m.close(); return; }
      const b = e.target.closest('[data-act]');
      if (b && actions[b.dataset.act] && !b.disabled) actions[b.dataset.act](b, e);
    });
    d.showModal();
    // Focus the main field: number fields never open the keyboard; text fields only with a mouse
    const first = d.querySelector('[autofocus]');
    if (first && (finePointer || first.readOnly)) { first.focus(); if (first.select && !first.readOnly) first.select(); }
    return m;
  }

  // ------------------------------------------------------------------ on-screen number entry
  /** A number field typed with the on-screen keypad (never opens the phone keyboard). mode: money (decimals) | int. */
  const numField = (name, value = '', mode = 'money', attrs = '') =>
    `<input class="input num-field" name="${name}" value="${esc(value)}" data-num="${mode}" readonly inputmode="none" autocomplete="off" ${attrs}>`;
  const keypad = (keys, cls = '', extra = '') => `<div class="keypad ${cls}">${keys.map((k) => `<button type="button" class="btn" data-key="${k}">${k}</button>`).join('')}${extra}</div>`;
  /** A − value + stepper for small whole numbers. */
  const stepper = (name, value, min = 1) => `<div class="stepper" data-min="${min}"><button type="button" class="btn" data-step="-1">−</button>
      ${numField(name, value, 'int')}<button type="button" class="btn" data-step="1">+</button></div>`;

  /** Apply a keypad key to a value. mode: money (one dot, no leading zeros) | int (no leading zeros) | text (PINs). */
  function keyInto(value, k, mode = 'money') {
    if (k === '⌫') return value.slice(0, -1);
    if (k === 'C') return '';
    if (k === '.' && (mode !== 'money' || value.includes('.'))) return value;
    const v = value + k;
    return mode === 'text' ? v : v.replace(/^0+(?=\d)/, '');
  }

  /**
   * Wire a dialog's keypad to its number fields (data-num). Tap a field to select it; the first key replaces
   * its value. Steppers (data-step) change the field next to them. A physical keyboard works too.
   * onChange(field) runs after every change.
   */
  function numpad(root, onChange) {
    let active = null;
    let fresh = true;
    const fields = () => [...root.querySelectorAll('[data-num]')].filter((el) => el.offsetParent !== null);
    const select = (el) => {
      if (active) active.classList.remove('active');
      active = el;
      fresh = true;
      if (el) el.classList.add('active');
    };
    const changed = (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); if (onChange) onChange(el); };
    const type = (k) => {
      if (k === 'next') { const all = fields(); select(all[all.indexOf(active) + 1] || all[0]); return; }
      if (!active) return;
      active.value = keyInto(fresh && k !== '⌫' ? '' : active.value, k, active.dataset.num);
      fresh = false;
      changed(active);
    };
    root.addEventListener('click', (e) => {
      const step = e.target.closest('[data-step]');
      if (step) {
        const box = step.closest('.stepper');
        const f = box.querySelector('[data-num]');
        f.value = String(Math.max(Number(box.dataset.min) || 0, (Number(f.value) || 0) + Number(step.dataset.step)));
        select(f);
        changed(f);
        return;
      }
      const f = e.target.closest('[data-num]');
      if (f) { select(f); return; }
      const k = e.target.closest('[data-key]');
      if (k) type(k.dataset.key);
    });
    root.addEventListener('keydown', (e) => {
      if (e.target.matches('input:not([data-num]), textarea, select') || e.ctrlKey || e.metaKey || e.altKey) return;
      const k = /^[0-9.]$/.test(e.key) ? e.key : e.key === 'Backspace' ? '⌫' : null;
      if (k) { e.preventDefault(); type(k); }
    });
    select(root.querySelector('[data-num]'));
    return { select, type };
  }

  /** Ask one number on the keypad. Resolves the number, or null when cancelled. */
  function askNumber({ title, label = '', value = '', mode = 'money', prefix = '', suffix = '', max = null, okText = 'OK' }) {
    return new Promise((resolve) => {
      let result = null;
      const m = modal({
        title,
        cls: 'dlg-number',
        body: `${label ? `<div class="field-label mb-sm">${esc(label)}</div>` : ''}
          <div class="num-display">${prefix ? `<span>${esc(prefix)}</span>` : ''}${numField('n', value, mode, 'autofocus')}${suffix ? `<span>${esc(suffix)}</span>` : ''}</div>
          ${keypad(mode === 'money' ? MONEY_KEYS : INT_KEYS, 'mt')}`,
        foot: `<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">${esc(okText)}</button>`,
        onSubmit: () => {
          const v = Number(m.$('[name=n]').value);
          if (!(v > 0)) { App.toast('Enter a number greater than zero', 'error'); return; }
          if (max !== null && v > max) { App.toast(`The maximum is ${max}`, 'error'); return; }
          result = v;
          m.close();
        },
        onClose: () => resolve(result),
      });
      numpad(m.el);
    });
  }

  /** Manager PIN pad. Resolves the PIN, or null when cancelled. */
  function askPin(message = 'This needs a manager’s approval. Ask a manager to enter their PIN.') {
    return new Promise((resolve) => {
      let result = null;
      const m = modal({
        title: 'Manager PIN',
        cls: 'dlg-pin',
        body: `<p class="muted mt-0">${esc(message)}</p>
          <div class="pin-dots"></div>
          <input type="hidden" name="pin" data-num="text">
          ${keypad(INT_KEYS, 'mt')}`,
        foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">Approve</button>',
        onSubmit: () => {
          const pin = m.$('[name=pin]').value;
          if (!pin) return;
          result = pin;
          m.close();
        },
        onClose: () => resolve(result),
      });
      const dots = () => {
        const n = m.$('[name=pin]').value.length;
        m.$('.pin-dots').innerHTML = n ? '●'.repeat(n) : '<span class="muted">Enter PIN</span>';
      };
      // The hidden field cannot be tapped: select it by hand so the keypad types into it
      numpad(m.el, dots).select(m.$('[name=pin]'));
      m.$('[type=submit]').focus();
      dots();
    });
  }

  // ------------------------------------------------------------------ sale details (customer, account, notes, prescription)
  let customerCache = null;
  async function customerOptions(selected) {
    if (!customerCache) customerCache = await Api.customers();
    return '<option value="">— none —</option>' + customerCache.map((c) => `<option value="${c.id}"${Number(selected) === c.id ? ' selected' : ''}>${esc(c.name)} (bal ${money(c.balance)})</option>`).join('');
  }

  function detailsDialog() {
    const t = S.sale;
    const m = modal({
      title: 'Customer & notes',
      body: `<label class="field"><span class="field-label">Customer name</span><input class="input" name="customer_name" value="${esc(t.customer_name || '')}" placeholder="Walk-in" autocomplete="off" ${finePointer ? 'autofocus' : ''}></label>
        <label class="field mt"><span class="field-label">Customer account (for charge / credit sales)</span><select class="input" name="customer_id"><option value="">Loading…</option></select></label>
        <label class="field mt"><span class="field-label">Notes</span><input class="input" name="notes" value="${esc(t.notes || '')}" autocomplete="off"></label>
        <div class="note-chips">${NOTE_CHIPS.map((n) => `<button type="button" class="btn btn-sm" data-act="note" data-note="${esc(n)}">${esc(n)}</button>`).join('')}</div>
        <label class="field mt"><span class="field-label">Prescription (Rx) — doctor, patient, Rx no.</span>
          <input class="input" name="rx_note" value="${esc(t.rx_note || '')}" placeholder="e.g. Dr. Santos · PRC 12345 · for Juan dela Cruz" autocomplete="off"></label>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">Save</button>',
      actions: { note: (b) => { const n = m.$('[name=notes]'); n.value = (n.value ? n.value + ', ' : '') + b.dataset.note; } },
      onSubmit: async (btn) => {
        const v = (n) => m.$(`[name=${n}]`).value.trim();
        const data = { customer_name: v('customer_name'), customer_id: Number(v('customer_id')) || null, notes: v('notes'), rx_note: v('rx_note') };
        if (S.sale.draft) {
          Object.assign(S.sale, { customer_name: data.customer_name || null, customer_id: data.customer_id, notes: data.notes || null, rx_note: data.rx_note || null });
          renderOrder();
          m.close();
          return;
        }
        if (await run(() => mutate((o) => Api.update(o.id, data)), btn)) m.close();
      },
    });
    customerOptions(t.customer_id).then((html) => { if (m.el.isConnected) m.$('[name=customer_id]').innerHTML = html; }).catch(showError);
  }

  // ------------------------------------------------------------------ sale line: quantity, price, note, discount, remove
  function lineDialog(line) {
    const sale = S.sale;
    const presets = presetsFor('item');
    const receiptSc = isSc(sale.discount_type);
    const m = modal({
      title: line.name,
      cls: 'dlg-line',
      body: `<div class="qty-row">
          <button type="button" class="btn btn-lg" data-act="minus">−</button>
          ${numField('qty', qtyStr(line.qty), 'money', 'data-act="qty"')}
          <button type="button" class="btn btn-lg" data-act="plus">+</button>
        </div>
        <p class="muted small">${esc(line.uom_label || 'pc')}${line.factor > 1 ? ` of ${qtyStr(line.factor)}` : ''} · ${peso(line.price)} each${line.generic_name ? ' · ' + esc(line.generic_name) : ''}</p>
        <div class="form-grid">
          <label class="field"><span class="field-label">Unit price</span>${numField('price', money(line.price).replace(/,/g, ''), 'money', 'data-act="price"')}
            <span class="field-hint">${can('pos.discount') ? 'Changing the price is logged' : 'A price change needs a manager PIN'}</span></label>
          <label class="field"><span class="field-label">Note</span><input class="input" name="notes" value="${esc(line.notes || '')}" placeholder="optional" autocomplete="off"></label>
        </div>

        <div class="line-section">
          <div class="row between"><span class="field-label">Discount this item</span>
            ${line.discount_kind ? `<span class="tline-disc">${esc(line.discount_name)} −${money(line.discount_amount)}</span>` : ''}</div>
          ${receiptSc ? '<p class="muted small">The whole receipt has a Senior Citizen / PWD discount. Remove it to discount single items.</p>' : `
          <div class="preset-grid">
            ${presets.map((p) => `<button type="button" class="preset${line.discount_id === p.id ? ' sel' : ''}" data-act="preset" data-id="${p.id}">
              <b>${esc(p.name)}</b><small>${esc(presetHint(p))}${presetNeedsPin(p) ? ' · PIN' : ''}</small></button>`).join('')}
            ${line.discount_kind ? '<button type="button" class="preset preset-remove" data-act="removeDisc"><b>Remove</b><small>no discount</small></button>' : ''}
          </div>
          <div class="sc-person hidden"></div>`}
        </div>`,
      foot: `<button type="button" class="btn btn-danger btn-lg" data-act="remove">Remove item</button>
        <span class="grow"></span>
        <button type="button" class="btn btn-lg" data-x>Cancel</button>
        <button type="submit" class="btn btn-primary btn-lg">Update</button>`,
      actions: {
        minus: () => { qty.value = qtyStr(Math.max(1, (Number(qty.value) || 1) - 1)); },
        plus: () => { qty.value = qtyStr((Number(qty.value) || 0) + 1); },
        qty: async () => {
          const v = await askNumber({ title: `Quantity — ${line.name}`, okText: 'Set quantity' });
          if (v !== null) qty.value = qtyStr(v);
        },
        price: async () => {
          const v = await askNumber({ title: `Unit price — ${line.name}`, prefix: '₱', okText: 'Set price' });
          if (v !== null) price.value = money(v).replace(/,/g, '');
        },
        preset: async (b) => {
          const p = presets.find((x) => x.id === Number(b.dataset.id));
          if (isSc(p.kind)) { scPersonForm(p); return; }
          if (await applyPreset(p, line, {}, b)) m.close();
        },
        scApply: async (b) => {
          const person = { name: val('sc_name'), id_no: val('sc_id') };
          if (!person.name || !person.id_no) { App.toast('Enter the name and ID number', 'error'); return; }
          const p = presets.find((x) => x.id === Number(b.dataset.id));
          if (await applyPreset(p, line, { sc_person: person }, b)) m.close();
        },
        pickPerson: (b) => {
          const p = sale.sc_details[Number(b.dataset.i)];
          m.$('[name=sc_name]').value = p.name;
          m.$('[name=sc_id]').value = p.id_no;
        },
        removeDisc: async (b) => {
          if (await run(() => mutate((o) => Api.discountLine(o.id, line.id, { discount_type: 'none' })), b)) { App.toast('Discount removed'); m.close(); }
        },
        remove: async (b) => {
          if (await run(() => mutate((o) => Api.removeLine(o.id, line.id)), b)) m.close();
        },
      },
      onSubmit: async (btn) => {
        const q = Number(qty.value);
        if (!(q > 0)) { App.toast('Quantity must be greater than zero', 'error'); return; }
        const p = Number(price.value);
        if (!(p >= 0)) { App.toast('Enter a valid price', 'error'); return; }
        const priceChanged = r2(p) !== r2(line.price);
        const data = { qty: q, notes: notes.value };
        if (priceChanged) data.price = r2(p);
        const ok = await withPin((pin) => mutate((o) => Api.updateLine(o.id, line.id, { ...data, pin })), { ask: priceChanged && needPin('pos.discount'), btn });
        if (ok) m.close();
      },
    });
    const qty = m.$('[name=qty]');
    const price = m.$('[name=price]');
    const notes = m.$('[name=notes]');
    const val = (name) => { const el = m.$(`[name=${name}]`); return el ? el.value.trim() : ''; };

    /** SC / PWD on one item: the person's name + ID (people already on this sale can be picked). */
    function scPersonForm(p) {
      const box = m.$('.sc-person');
      box.classList.remove('hidden');
      box.innerHTML = `<div class="field-label mt">${esc(p.name)} — who is this item for?</div>
        ${sale.sc_details.length ? `<div class="note-chips">${sale.sc_details.map((x, i) => `<button type="button" class="btn btn-sm" data-act="pickPerson" data-i="${i}">${esc(x.name)} · ${esc(x.id_no)}</button>`).join('')}</div>` : ''}
        <div class="form-grid mt">
          <label class="field"><span class="field-label">Name</span><input class="input" name="sc_name" autocomplete="off"></label>
          <label class="field"><span class="field-label">${p.kind === 'sc' ? 'OSCA' : 'PWD'} ID no.</span><input class="input" name="sc_id" autocomplete="off"></label>
        </div>
        <button type="button" class="btn btn-primary btn-lg mt" data-act="scApply" data-id="${p.id}">Apply ${esc(p.name)}</button>`;
      box.scrollIntoView({ block: 'nearest' });
    }
  }

  // ------------------------------------------------------------------ discount on the whole receipt (presets)
  function discountDialog() {
    const t = S.sale;
    const presets = presetsFor('order');
    const itemDiscounts = activeLines(t).filter((l) => l.discount_kind).length;
    const m = modal({
      title: 'Discount — whole receipt',
      cls: 'dlg-discount',
      body: `${t.discount_type !== 'none' ? `<div class="alert alert-success small">Now: <b>${esc(t.discount_name || 'Discount')}</b> on the whole receipt.</div>` : ''}
        ${itemDiscounts ? `<p class="muted small mt-0">${plural(itemDiscounts, 'item')} on this sale already ${itemDiscounts === 1 ? 'has' : 'have'} its own discount (tap the item to change it).</p>` : ''}
        <div class="preset-grid big">
          ${presets.map((p) => `<button type="button" class="preset${t.discount_id === p.id ? ' sel' : ''}" data-act="preset" data-id="${p.id}">
            <b>${esc(p.name)}</b><small>${esc(presetHint(p))}${presetNeedsPin(p) ? ' · PIN' : ''}</small></button>`).join('')}
        </div>
        <div class="sc-form hidden"></div>`,
      foot: `${t.discount_type !== 'none' ? '<button type="button" class="btn btn-danger btn-lg" data-act="remove">Remove discount</button>' : ''}
        <span class="grow"></span><button type="button" class="btn btn-lg" data-x>Close</button>
        <button type="button" class="btn btn-primary btn-lg hidden" data-act="scApply">Apply</button>`,
      actions: {
        preset: async (b) => {
          const p = presets.find((x) => x.id === Number(b.dataset.id));
          if (isSc(p.kind)) { scForm(p); return; }
          if (await applyPreset(p, null, {}, b)) m.close();
        },
        remove: async (b) => {
          if (await run(() => mutate((o) => Api.discount(o.id, { discount_type: 'none' })), b)) { App.toast('Discount removed'); m.close(); }
        },
        scApply: async (b) => {
          const n = Math.max(Number(f.count) || 1, 1);
          const people = Array.from({ length: n }, (_, i) => f.people[i] || { name: '', id_no: '' });
          if (people.some((x) => !x.name.trim() || !x.id_no.trim())) { App.toast('Enter the name and ID number of each person', 'error'); return; }
          if (await applyPreset(f.preset, null, { sc_details: people }, b)) m.close();
        },
      },
    });
    // SC / PWD on the whole receipt: name + ID of each buyer (usually one)
    const f = { preset: null, count: String(Math.max(t.sc_count || 1, 1)), people: t.sc_details.map((p) => ({ ...p })) };
    function scForm(p) {
      f.preset = p;
      m.el.querySelectorAll('[data-act=preset]').forEach((b) => b.classList.toggle('sel', Number(b.dataset.id) === p.id));
      const apply = m.$('[data-act=scApply]');
      apply.classList.remove('hidden');
      apply.textContent = `Apply ${p.name}`;
      paintSc();
      m.$('.sc-form').scrollIntoView({ block: 'nearest' });
    }
    function paintSc() {
      const box = m.$('.sc-form');
      const p = f.preset;
      const n = Math.max(Number(f.count) || 1, 1);
      const who = p.kind === 'sc' ? 'senior citizen' : 'PWD';
      box.classList.remove('hidden');
      box.innerHTML = `<div class="alert alert-info small mt">${B.tax.vatRegistered ? 'VAT-exempt + ' : ''}${Math.round(B.tax.scRate * 100)}% off the whole purchase
          (medicines, basic necessities and prime commodities bought by the ${who}). Enter the buyer’s name and ID number for the BIR sales book.</div>
        <div class="form-grid">
          <label class="field"><span class="field-label">No. of ${who}s buying</span>${stepper('count', f.count)}</label>
        </div>
        ${Array.from({ length: n }, (_, i) => `<div class="form-grid mt">
          <label class="field"><span class="field-label">Name #${i + 1}</span><input class="input" data-p="${i}" data-k="name" autocomplete="off" value="${esc((f.people[i] || {}).name || '')}"></label>
          <label class="field"><span class="field-label">${p.kind === 'sc' ? 'OSCA' : 'PWD'} ID no.</span><input class="input" data-p="${i}" data-k="id_no" autocomplete="off" value="${esc((f.people[i] || {}).id_no || '')}"></label>
        </div>`).join('')}`;
    }
    m.el.addEventListener('input', (e) => {
      const el = e.target;
      if (el.dataset.p !== undefined) {
        const i = Number(el.dataset.p);
        f.people[i] = { name: '', id_no: '', ...f.people[i], [el.dataset.k]: el.value };
      } else if (el.name === 'count') {
        f.count = el.value;
        paintSc();
      }
    });
    numpad(m.el);
  }

  // ------------------------------------------------------------------ held sale card ⋯ menu
  function cardMenu(id) {
    const o = S.held.find((x) => x.id === id);
    if (!o) return;
    const m = modal({
      title: `${o.customer_name || 'Held sale'} · ${o.ticket_no}`,
      cls: 'dlg-card',
      body: `<div class="col gap-sm">
          <button type="button" class="btn btn-xl" data-act="resume">Resume sale</button>
          <button type="button" class="btn btn-xl" data-act="quote">⎙ Print quote</button>
          <button type="button" class="btn btn-xl btn-danger" data-act="cancel">Cancel this sale</button>
        </div>`,
      actions: {
        resume: () => { m.close(); resumeSale(id); },
        quote: async (b) => { const t = await run(() => Api.show(id), b); if (t) { m.close(); Print.receipt(t, false); } },
        cancel: async (b) => {
          const ok = await App.ask({ title: `Cancel ${o.ticket_no}?`, message: 'The held sale is removed. Nothing was charged.', danger: true, okText: 'Cancel sale' });
          if (!ok) return;
          if (await run(() => Api.void(id, 'Held sale cancelled', ''), b)) { m.close(); App.toast(`${o.ticket_no} cancelled`); loadHeld(); }
        },
      },
    });
  }

  // ------------------------------------------------------------------ payment
  function payDialog(t) {
    const methods = B.payment_methods;
    const label = (k) => (methods.find((x) => x.key === k) || {}).label || k;
    const pays = [];           // [{method, amount, reference, customer_id}]
    let method = 'cash';
    let customers = null;      // loaded when "charge" is chosen
    const total = t.total;
    const needsRx = hasRx(t) && !t.rx_note;
    const sums = () => {
      const paid = r2(pays.reduce((s, p) => s + p.amount, 0));
      const nonCash = r2(pays.filter((p) => p.method !== 'cash').reduce((s, p) => s + p.amount, 0));
      return { paid, nonCash, remaining: r2(Math.max(total - paid, 0)), change: r2(Math.max(paid - total, 0)) };
    };

    const m = modal({
      title: `Payment — ${saleTitle(t)} · ${t.ticket_no}`,
      cls: 'wide dlg-pay',
      body: `<div class="pay-grid">
          <div>
            <div class="pay-stats">
              <div class="stat stat-brand"><div class="stat-label">Amount due</div><div class="stat-value">${peso(total)}</div></div>
              <div class="stat pay-rem"><div class="stat-label"></div><div class="stat-value"></div></div>
            </div>
            ${needsRx ? `<label class="field mt"><span class="field-label">Prescription (Rx) — doctor / patient / Rx no. *</span>
              <input class="input" name="rx_note" placeholder="e.g. Dr. Santos · PRC 12345 · Juan dela Cruz" autocomplete="off">
              <span class="field-hint">This sale has prescription items. Keep the prescription on file.</span></label>` : ''}
            <div class="field-label mt mb-sm">Payment method</div>
            <div class="pay-methods">${methods.map((x) => `<button type="button" class="btn" data-act="method" data-m="${x.key}">${esc(x.label)}</button>`).join('')}</div>
            <label class="field mt pay-ref"><span class="field-label">Approval / reference no.</span>
              <input class="input" name="reference" placeholder="e.g. card approval code or GCash ref no." autocomplete="off"></label>
            <label class="field mt pay-cust"><span class="field-label">Customer account</span><select class="input" name="customer"><option value="">Loading…</option></select></label>
            <div class="field-label mt mb-sm">Payments</div>
            <div class="pay-list"></div>
          </div>
          <div>
            ${numField('entry', '', 'money', 'autofocus')}
            <div class="quick-cash mt"></div>
            ${keypad(MONEY_KEYS, 'mt')}
            <div class="row gap-sm mt"><button type="button" class="btn btn-lg grow" data-key="C">Clear</button>
              <button type="button" class="btn btn-dark btn-lg grow pay-add" data-act="add"></button></div>
          </div>
        </div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-success btn-lg pay-complete"></button>',
      actions: {
        method: (b) => { method = b.dataset.m; if (method === 'charge') loadCustomers(); paint(); },
        add: () => add(),
        quick: (b) => add(Number(b.dataset.v)),
        removePay: (b) => { pays.splice(Number(b.dataset.i), 1); paint(); },
      },
      onSubmit: (btn) => complete(btn),
    });
    const entryEl = m.$('[name=entry]');
    entryEl.classList.add('pay-entry');
    const entry = () => entryEl.value;
    const pad = numpad(m.el, () => paint());

    async function loadCustomers() {
      if (customers) return;
      try {
        customers = await Api.customers();
        m.$('[name=customer]').innerHTML = '<option value="">Select customer…</option>'
          + customers.map((c) => `<option value="${c.id}"${t.customer_id === c.id ? ' selected' : ''}>${esc(c.name)} (bal ${money(c.balance)})</option>`).join('');
      } catch (e) { showError(e); }
    }

    /** Add a payment line. Non-cash payments are capped at what is still due. */
    function add(amountArg) {
      const { nonCash, remaining } = sums();
      let amount = r2(amountArg !== undefined ? amountArg : entry() === '' ? remaining : entry());
      if (!(amount > 0)) return false;
      if (method !== 'cash' && r2(nonCash + amount) > total) {
        amount = r2(total - nonCash);
        if (!(amount > 0)) { App.toast('Non-cash payments cannot exceed the amount due', 'error'); return false; }
      }
      const p = current(amount);
      if (!p) return false;
      pays.push(p);
      entryEl.value = '';
      pad.select(entryEl);
      m.$('[name=reference]').value = '';
      method = 'cash';
      paint();
      return true;
    }
    function current(amount) {
      const customerId = Number(m.$('[name=customer]').value) || null;
      if (method === 'charge' && !customerId) { App.toast('Select the customer account to charge', 'error'); return null; }
      return { method, amount, reference: method === 'cash' ? null : m.$('[name=reference]').value.trim() || null, customer_id: method === 'charge' ? customerId : null };
    }

    async function complete(btn) {
      const rx = needsRx ? m.$('[name=rx_note]').value.trim() : '';
      if (needsRx && !rx) { App.toast('Enter the prescription details for the Rx items', 'error'); m.$('[name=rx_note]').focus(); return; }
      let list = pays.slice();
      // Nothing added yet: pay the typed amount (or the full amount) with the selected method
      if (!list.length) {
        const p = current(r2(entry() === '' ? total : entry()));
        if (!p) return;
        list = [p];
      } else if (entry() !== '') {
        if (!add()) return;
        list = pays.slice();
      }
      const paid = await run(() => Api.pay(t.id, list, { rx_note: rx || undefined }), btn);
      if (!paid) return;
      m.close();
      afterPaid(paid);
    }

    function paint() {
      const { paid, remaining, change } = sums();
      const rem = m.$('.pay-rem');
      rem.className = `stat pay-rem ${change > 0 ? 'stat-green' : remaining > 0 ? 'stat-amber' : ''}`;
      rem.querySelector('.stat-label').textContent = change > 0 ? 'Change' : 'Remaining';
      rem.querySelector('.stat-value').textContent = peso(change > 0 ? change : remaining);
      m.el.querySelectorAll('[data-act=method]').forEach((b) => b.classList.toggle('sel', b.dataset.m === method));
      m.$('.pay-ref').classList.toggle('hidden', method === 'cash' || method === 'charge');
      m.$('.pay-cust').classList.toggle('hidden', method !== 'charge');
      entryEl.placeholder = money(remaining || total);
      // Quick cash: exact amount + next round bills
      const due = remaining || total;
      const quick = [...new Set([due, ...[20, 50, 100, 500, 1000].map((s) => Math.ceil(due / s) * s)])].filter((v) => v >= due).sort((a, b) => a - b).slice(0, 5);
      m.$('.quick-cash').innerHTML = method === 'cash' && remaining > 0
        ? quick.map((v) => `<button type="button" class="btn btn-lg" data-act="quick" data-v="${v}">${v === due ? 'Exact' : '₱' + v.toLocaleString()}</button>`).join('') : '';
      m.$('.pay-add').textContent = `Add ${label(method)}`;
      m.$('.pay-list').innerHTML = pays.length ? pays.map((p, i) => `<div class="pay-line">
          <span>${esc(label(p.method))}${p.reference ? ` <span class="muted small">#${esc(p.reference)}</span>` : ''}</span>
          <span class="row gap-sm"><b>${peso(p.amount)}</b><button type="button" class="icon-btn" data-act="removePay" data-i="${i}" aria-label="Remove">✕</button></span>
        </div>`).join('') : `<div class="muted small">Add one or more payments, or just press Complete to pay the full amount with ${esc(label(method))}.</div>`;
      const ready = pays.length ? paid >= total || entry() !== '' : true;
      const btn = m.$('.pay-complete');
      btn.disabled = !ready;
      btn.textContent = `Complete payment${change > 0 ? ' · Change ' + peso(change) : ''}`;
    }
    paint();
  }

  /** Success screen after payment. Returns {printed} so the caller can record the automatic print. */
  function paidDialog(t, autoPrint) {
    const job = { printed: false };
    const m = modal({
      title: 'Payment complete',
      cls: 'dlg-paid',
      body: `<div class="paid-box">
          <div class="paid-check">✓</div>
          <div class="muted">${esc(t.receipt_no)}${t.customer_name ? ' · ' + esc(t.customer_name) : ''} · Total ${peso(t.total)}</div>
          <div class="paid-change-label">CHANGE</div>
          <div class="paid-change">${peso(t.change_amount)}</div>
        </div>`,
      foot: `<button type="button" class="btn btn-lg" data-act="print">⎙ ${autoPrint ? 'Print again' : 'Print receipt'}</button><span class="grow"></span>
        <button type="submit" class="btn btn-primary btn-lg">Next customer</button>`,
      actions: {
        print: async () => { const ok = await Print.receipt(t, job.printed); job.printed = job.printed || ok; },
      },
      onSubmit: () => { m.close(); if (finePointer) $('#pos-search').focus(); },
    });
    m.$('[type=submit]').focus(); // Enter = next customer
    return job;
  }

  // ------------------------------------------------------------------ receipts: search, view, reprint, return / refund, void
  function receiptsDialog() {
    let selected = null;
    let timer = null;
    let rows = [];
    const m = modal({
      title: 'Receipts',
      cls: 'wide dlg-receipts',
      body: `<div class="rcpt-grid">
          <div class="rcpt-left">
            <input class="input" name="q" type="search" placeholder="Receipt / sale no. or customer (blank = current day)" autocomplete="off" enterkeyhint="search">
            <div class="rcpt-list"><div class="empty"><span class="spinner dark"></span></div></div>
          </div>
          <div class="rcpt-right"><div class="empty">Select a receipt</div></div>
        </div>`,
      actions: {
        pick: async (b) => {
          const t = await run(() => Api.show(Number(b.dataset.id)));
          if (t) { selected = t; paintList(); paintPreview(); }
        },
        reprint: async (b) => {
          const t = await run(() => Api.reprint(selected.id), b);
          if (t) Print.receipt(t, true);
        },
        refund: () => returnDialog(selected, (t) => { selected = t; load(); paintPreview(); }),
        printReturn: (b) => {
          const r = selected.returns.find((x) => x.id === Number(b.dataset.id));
          if (r) Print.refund(r, selected);
        },
        voidReturn: async (b) => {
          const r = await App.ask({ title: `Void return ${b.dataset.no}?`, danger: true, okText: 'Void return', input: 'Reason', required: true,
            message: 'The refund is reversed in the books and the returned stock is taken out again.' });
          if (!r) return;
          const res = await withPin((pin) => Api.voidReturn(Number(b.dataset.id), r.value, pin), { ask: needPin('pos.refund'), btn: b });
          if (res) { selected = res.ticket; App.toast('Return voided'); load(); paintPreview(); }
        },
        voidReceipt: async (b) => {
          const r = await App.ask({
            title: `Void receipt ${selected.receipt_no}?`, danger: true, okText: 'Void receipt', input: 'Reason', required: true,
            message: 'The whole sale is reversed: stock returns to inventory, the sale is reversed in the books and any cash refund comes out of the drawer. For a partial return use Return / refund instead.',
          });
          if (!r) return;
          const t = await withPin((pin) => Api.void(selected.id, r.value, pin), { ask: needPin('pos.void_receipt'), btn: b });
          if (t) { selected = t; App.toast('Receipt voided'); load(); paintPreview(); loadMenu(); }
        },
      },
    });
    async function load() {
      try { rows = await Api.receipts(m.$('[name=q]').value.trim()); paintList(); } catch (e) { showError(e); }
    }
    function paintList() {
      m.$('.rcpt-list').innerHTML = rows.map((t) => `<div class="tline rcpt-row${selected && selected.id === t.id ? ' selected' : ''}" data-act="pick" data-id="${t.id}">
          <div><b>${esc(t.receipt_no || t.ticket_no)}</b>${t.refunded_total > 0 ? ' <span class="badge badge-blue">refunded ' + money(t.refunded_total) + '</span>' : ''}
            <div class="muted small">${esc(fmtTime(t.paid_at || t.created_at))} · ${esc(t.cashier || '')}${t.customer_name ? ' · ' + esc(t.customer_name) : ''}</div></div>
          <div class="right"><b>${peso(t.total)}</b><div><span class="badge ${t.status === 'paid' ? 'badge-green' : 'badge-red'}">${t.status === 'void' && !t.receipt_no ? 'cancelled' : esc(t.status)}</span></div></div>
        </div>`).join('') || '<div class="empty">No receipts.</div>';
    }
    function paintPreview() {
      const t = selected;
      const returns = (t.returns || []).filter((r) => r.status === 'posted');
      const refundable = t.status === 'paid' && activeLines(t).some((l) => l.qty - l.returned_qty > 0.00005);
      m.$('.rcpt-right').innerHTML = `<div class="receipt-preview">${previewReceipt(t)}</div>
        ${returns.length ? `<div class="mt"><div class="field-label mb-sm">Returns on this receipt</div>${returns.map((r) => `<div class="pick-row">
            <div><b>${esc(r.return_no)}</b> <span class="muted small">${esc(fmtTime(r.created_at))} · ${esc(r.reason || '')}</span>
              <div class="muted small">${r.lines.map((l) => `${qtyStr(l.qty)} × ${esc(l.name)}`).join(', ')}</div></div>
            <div class="row gap-sm"><b>${peso(r.refund_amount)}</b>
              <button type="button" class="btn" data-act="printReturn" data-id="${r.id}" title="Print refund slip">⎙</button>
              <button type="button" class="btn" data-act="voidReturn" data-id="${r.id}" data-no="${esc(r.return_no)}">Void</button></div>
          </div>`).join('')}</div>` : ''}
        <div class="row gap-sm mt wrap">
          ${can('pos.reprint') && t.receipt_no ? '<button type="button" class="btn btn-lg grow" data-act="reprint">⎙ Reprint</button>' : ''}
          ${refundable && S.session ? '<button type="button" class="btn btn-lg grow" data-act="refund">↩ Return / refund</button>' : ''}
          ${t.status === 'paid' && !returns.length ? '<button type="button" class="btn btn-danger btn-lg grow" data-act="voidReceipt">Void receipt</button>' : ''}
        </div>`;
      m.$('.rcpt-right').scrollIntoView({ block: 'nearest' });
    }
    m.$('[name=q]').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 300); });
    load();
  }

  // ------------------------------------------------------------------ return / refund against a paid receipt
  /** Pick the items and quantities being returned, how the customer is refunded and whether the goods go back to stock. */
  function returnDialog(t, onDone) {
    const lines = activeLines(t).map((l) => ({ ...l, available: r2(l.qty - l.returned_qty) })).filter((l) => l.available > 0.00005);
    const sel = {};
    const methods = B.refund_methods || [{ key: 'cash', label: 'Cash from drawer' }];
    // Estimated refund: the line's share of what the customer actually paid (the server computes the exact amount)
    const lineNet = (l) => Math.max(l.line_total - (l.discount_amount || 0), 0);
    const paidShare = t.subtotal > 0 ? t.total / activeLines(t).reduce((s, l) => s + lineNet(l), 0) : 1;
    const m = modal({
      title: `Return / refund — ${t.receipt_no}`,
      cls: 'dlg-return',
      body: `<div class="row gap-sm mb"><button type="button" class="btn" data-act="all">Everything</button><button type="button" class="btn" data-act="clear">Clear</button></div>
        <div class="split-lines"></div>
        <div class="form-grid mt">
          <label class="field"><span class="field-label">Refund by *</span><select class="input" name="method">${methods.map((x) => `<option value="${x.key}">${esc(x.label)}</option>`).join('')}</select></label>
          <label class="field"><span class="field-label">Reason *</span><input class="input" name="reason" list="return-reasons" placeholder="e.g. wrong item, defective, expired" autocomplete="off">
            <datalist id="return-reasons"><option value="Wrong item"><option value="Customer changed mind"><option value="Defective / damaged"><option value="Expired"><option value="Overcharged"></datalist></label>
        </div>
        <label class="checkbox mt"><input type="checkbox" name="restock" checked> Goods are in good condition — put them back in stock</label>
        <p class="muted small mt-0">Untick for damaged / expired goods: they are written off instead.</p>`,
      foot: '<span class="muted split-sum"></span><span class="grow"></span><button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">Refund</button>',
      actions: {
        all: () => { lines.forEach((l) => { sel[l.id] = l.available; }); paint(); },
        clear: () => { lines.forEach((l) => { sel[l.id] = 0; }); paint(); },
        less: (b) => setQ(b.dataset.id, (sel[b.dataset.id] || 0) - 1),
        more: (b) => setQ(b.dataset.id, (sel[b.dataset.id] || 0) + 1),
        whole: (b) => setQ(b.dataset.id, Infinity),
        qty: async (b) => {
          const l = lines.find((x) => String(x.id) === b.dataset.id);
          const v = await askNumber({ title: `Return quantity — ${l.name}`, max: l.available, okText: 'Set' });
          if (v !== null) setQ(b.dataset.id, v);
        },
      },
      onSubmit: async (btn) => {
        const chosen = Object.entries(sel).filter(([, q]) => q > 0).map(([id, q]) => ({ id: Number(id), qty: q }));
        if (!chosen.length) { App.toast('Select the items being returned', 'error'); return; }
        const reason = m.$('[name=reason]').value.trim();
        if (!reason) { App.toast('Enter the reason for the return', 'error'); m.$('[name=reason]').focus(); return; }
        const data = { lines: chosen, method: m.$('[name=method]').value, reason, restock: m.$('[name=restock]').checked ? 1 : 0 };
        const r = await withPin((pin) => Api.createReturn(t.id, { ...data, pin }), { ask: needPin('pos.refund'), btn });
        if (!r) return;
        m.close();
        App.toast(`${r.return.return_no}: refunded ${peso(r.return.refund_amount)}`);
        loadMenu();
        if (onDone) onDone(r.ticket);
        Print.refund(r.return, r.ticket);
      },
    });
    function setQ(id, q) {
      const l = lines.find((x) => String(x.id) === String(id));
      sel[id] = Math.max(0, Math.min(l.available, q));
      paint();
    }
    function paint() {
      m.$('.split-lines').innerHTML = lines.map((l) => `<div class="split-line">
          <div><b>${esc(l.name)}</b><div class="muted small">${qtyStr(l.qty)} ${esc(l.uom_label || '')} × ${money(l.price)}${l.returned_qty > 0 ? ` · ${qtyStr(l.returned_qty)} already returned` : ''}</div></div>
          <div class="row gap-sm">
            <button type="button" class="btn" data-act="less" data-id="${l.id}">−</button>
            <button type="button" class="btn btn-ghost split-q" data-act="qty" data-id="${l.id}">${qtyStr(sel[l.id] || 0)}</button>
            <button type="button" class="btn" data-act="more" data-id="${l.id}">+</button>
            <button type="button" class="btn btn-ghost" data-act="whole" data-id="${l.id}">All</button>
          </div></div>`).join('') || '<div class="empty">Everything on this receipt was already returned.</div>';
      const est = lines.reduce((s, l) => s + ((sel[l.id] || 0) / l.qty) * lineNet(l) * paidShare, 0);
      m.$('.split-sum').textContent = `Refund ≈ ${peso(est)}`;
    }
    paint();
  }

  // ------------------------------------------------------------------ drawer payout (petty cash from the cash drawer)
  function payoutDialog() {
    const accts = B.expense_accounts;
    const m = modal({
      title: 'Cash payout from the drawer',
      cls: 'wide dlg-payout',
      body: `<p class="muted mt-0">Small expenses paid with cash from this drawer (fare, supplies, load…). They reduce the expected cash at end of day.</p>
        <div class="form-grid">
          <label class="field"><span class="field-label">Expense type *</span><select class="input" name="account_id"><option value="">Select…</option>
            ${accts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></label>
          <label class="field"><span class="field-label">Amount (₱) *</span>${numField('amount', '', 'money', 'data-act="amount" placeholder="Tap to enter"')}</label>
          <label class="field"><span class="field-label">Paid to</span><input class="input" name="payee" placeholder="e.g. Meralco, tricycle" autocomplete="off"></label>
          <label class="field"><span class="field-label">Description</span><input class="input" name="description" placeholder="e.g. plastic bags, delivery fare" autocomplete="off"></label>
          <label class="field"><span class="field-label">OR / receipt no.</span><input class="input" name="or_no" autocomplete="off"></label>
        </div>
        <div class="right mt"><button type="submit" class="btn btn-primary btn-lg">Record payout</button></div>
        <h3 class="mt-lg mb">Payouts today</h3>
        <div class="payout-list"><span class="spinner dark"></span></div>`,
      actions: {
        amount: async (b) => {
          const v = await askNumber({ title: 'Payout amount', prefix: '₱', okText: 'OK' });
          if (v !== null) b.value = money(v).replace(/,/g, '');
        },
        voidPayout: async (b) => {
          const r = await App.ask({ title: `Void payout ${b.dataset.no}?`, input: 'Reason', required: true, danger: true, okText: 'Void payout' });
          if (!r) return;
          if (await withPin((pin) => Api.voidPayout(Number(b.dataset.id), r.value, pin), { btn: b })) { App.toast('Payout voided'); load(); }
        },
      },
      onSubmit: async (btn) => {
        const v = (n) => m.$(`[name=${n}]`).value.trim();
        if (!v('account_id')) { App.toast('Select the expense type', 'error'); return; }
        if (!(Number(v('amount')) > 0)) { App.toast('Enter the amount', 'error'); return; }
        const data = { source: 'drawer', account_id: Number(v('account_id')), amount: Number(v('amount')), payee: v('payee'), description: v('description'), or_no: v('or_no') };
        if (await run(() => Api.addPayout(data), btn)) {
          App.toast(`Payout of ${peso(data.amount)} recorded`);
          ['amount', 'payee', 'description', 'or_no'].forEach((n) => { m.$(`[name=${n}]`).value = ''; });
          load();
        }
      },
    });
    async function load() {
      try {
        const list = await Api.payouts();
        const total = list.filter((p) => p.status === 'posted').reduce((s, p) => s + Number(p.amount), 0);
        m.$('.payout-list').innerHTML = list.length ? list.map((p) => `<div class="pick-row${p.status !== 'posted' ? ' muted-row' : ''}">
            <div><b>${esc(p.description || p.account_name)}</b> <span class="muted small">${esc(p.doc_no)} · ${esc(p.account_name || '')}${p.payee ? ' · ' + esc(p.payee) : ''} · ${esc(fmtTime(p.created_at))}</span></div>
            <div class="row gap-sm"><b>${peso(p.amount)}</b>
              ${p.status === 'posted' ? `<button type="button" class="btn" data-act="voidPayout" data-id="${p.id}" data-no="${esc(p.doc_no)}">Void</button>` : '<span class="badge badge-red">void</span>'}</div>
          </div>`).join('') + `<div class="row between mt bold"><span>Total</span><span>${peso(total)}</span></div>` : '<div class="muted small">None yet.</div>';
      } catch (e) { showError(e); }
    }
    load();
  }

  // ------------------------------------------------------------------ X-reading (mid-day)
  async function xReadDialog() {
    const r = await run(() => Api.xreading(), $('[data-act="xread"]'));
    if (!r) return;
    modal({
      title: 'X-Reading (current day so far)',
      cls: 'dlg-reading',
      body: `<div class="receipt-preview">${previewReading(r, false)}</div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Close</button><button type="button" class="btn btn-primary btn-lg" data-act="print">⎙ Print</button>',
      actions: { print: () => Print.reading(r, false) },
    });
  }

  // ------------------------------------------------------------------ cash counting (open day / end of day)
  /** Denomination rows (tap a row, type the count on the keypad; "Next" moves down). */
  function denomCounter() {
    return `<div class="cash-count">
        <div>
          <div class="denoms">${B.denominations.map((d) => `<div class="row denom">
            <span class="denom-label">${denomLabel(d)}</span><span class="muted">×</span>
            ${numField('d' + String(d).replace('.', '_'), '', 'int', `data-denom="${d}" placeholder="0"`)}
            <span class="denom-amt mono">0.00</span></div>`).join('')}</div>
          <div class="row between mt count-total"><span>Total counted</span><span class="count-sum">₱0.00</span></div>
        </div>
        ${keypad(INT_KEYS, 'count-keys', '<button type="button" class="btn btn-dark key-next" data-key="next">Next ↓</button>')}
      </div>`;
  }
  /** Read the denomination fields: {counts: {"1000": 2, ...}, total}. */
  function readCounts(root) {
    const counts = {};
    let total = 0;
    root.querySelectorAll('[data-denom]').forEach((el) => {
      const n = Number(el.value) || 0;
      const d = Number(el.dataset.denom);
      el.closest('.denom').querySelector('.denom-amt').textContent = money(d * n);
      if (n > 0) { counts[el.dataset.denom] = n; total += d * n; }
    });
    total = r2(total);
    const sum = root.querySelector('.count-sum');
    if (sum) sum.textContent = peso(total);
    return { counts, total };
  }

  function openDayDialog() {
    let mode = 'count';
    const m = modal({
      title: 'Open business day — beginning cash',
      cls: 'wide dlg-cash',
      body: `<label class="field"><span class="field-label">Business date</span><input class="input" type="date" name="business_date" value="${esc(B.today)}">
          <span class="field-hint">Sales after midnight still count to this date until the day is closed.</span></label>
        <div class="tabs mt"><button type="button" class="tab active" data-act="mode" data-mode="count">Count by denomination</button>
          <button type="button" class="tab" data-act="mode" data-mode="amount">Enter total</button></div>
        <div class="mode-count">${denomCounter()}</div>
        <div class="mode-amount hidden"><div class="field-label mb-sm">Beginning cash / change fund (₱)</div>
          <div class="cash-count"><div>${numField('opening_cash', '', 'money', 'placeholder="0.00"')}</div>${keypad(MONEY_KEYS, 'count-keys')}</div></div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-success btn-lg open-btn">Open day</button>',
      actions: {
        mode: (b) => {
          mode = b.dataset.mode;
          m.el.querySelectorAll('[data-act=mode]').forEach((x) => x.classList.toggle('active', x === b));
          m.$('.mode-count').classList.toggle('hidden', mode !== 'count');
          m.$('.mode-amount').classList.toggle('hidden', mode !== 'amount');
          pad.select(mode === 'count' ? m.$('[data-denom]') : m.$('[name=opening_cash]'));
          paint();
        },
      },
      onSubmit: async (btn) => {
        const c = readCounts(m.el);
        const data = { business_date: m.$('[name=business_date]').value, opening_cash: mode === 'count' ? c.total : r2(m.$('[name=opening_cash]').value),
          denominations: mode === 'count' ? c.counts : null };
        const s = await run(() => Api.openDay(data), btn);
        if (!s) return;
        m.close();
        S.session = s;
        App.toast('Business day opened');
        newSale();
      },
    });
    const paint = () => {
      const total = mode === 'count' ? readCounts(m.el).total : r2(m.$('[name=opening_cash]').value);
      m.$('.open-btn').textContent = `Open day with ${peso(total)}`;
    };
    const pad = numpad(m.el, paint);
    paint();
  }

  /** End of day: blind cash count -> Z-reading with expected / counted / short-over. */
  function endOfDayDialog() {
    const open = S.held.length;
    const m = modal({
      title: 'End of day — cash count',
      cls: 'wide dlg-cash',
      body: `${open ? `<div class="alert alert-warn">There are still ${plural(open, 'held sale')}. Pay or cancel them before closing the day.</div>` : ''}
        <div class="alert alert-info small">Count all the cash in the drawer, including the beginning cash. The expected amount is shown only after you submit
          (blind count). Any shortage or overage is posted to the books automatically.</div>
        ${denomCounter()}
        <label class="field mt"><span class="field-label">Remarks</span><input class="input" name="notes" autocomplete="off"></label>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-danger btn-lg">Close day</button>',
      onSubmit: async (btn) => {
        const c = readCounts(m.el);
        const ok = await App.ask({ title: 'Close the business day?', okText: 'Close day', danger: true,
          message: `Counted cash: ${peso(c.total)}. After closing, no more sales can be recorded for this day and the Z-reading is generated.` });
        if (!ok) return;
        const z = await run(() => Api.closeDay({ denominations: c.counts, notes: m.$('[name=notes]').value.trim() }), btn);
        if (!z) return;
        m.close();
        S.session = null;
        S.sale = draftSale();
        S.view = 'sale';
        render();
        loadHeld();
        zResultDialog(z);
      },
    });
    numpad(m.el, () => readCounts(m.el));
  }

  function zResultDialog(z) {
    const v = z.cash.variance;
    modal({
      title: 'Z-Reading — day closed',
      cls: 'dlg-reading',
      body: `<div class="z-summary ${v === 0 ? 'ok' : v < 0 ? 'short' : 'over'}">
          <div><span>Expected</span><b>${peso(z.cash.expected)}</b></div>
          <div><span>Counted</span><b>${peso(z.cash.counted)}</b></div>
          <div><span>${v === 0 ? 'Balanced' : v < 0 ? 'SHORT' : 'OVER'}</span><b>${peso(Math.abs(v))}</b></div>
        </div>
        <div class="receipt-preview mt">${previewReading(z, true)}</div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Done</button><button type="button" class="btn btn-primary btn-lg" data-act="print">⎙ Print Z-Reading</button>',
      actions: { print: () => Print.reading(z, true) },
    });
  }

  // ------------------------------------------------------------------ printer: status, reconnect, quick settings
  function printerDialog() {
    const m = modal({
      title: 'Printer',
      cls: 'dlg-printer',
      body: '<div class="printer-body"></div>',
      foot: `<a class="btn btn-lg" href="${esc(B.links.printerSetup)}" target="_blank" rel="noopener">Open printer setup ↗</a><span class="grow"></span>
        <button type="button" class="btn btn-lg" data-x>Close</button>`,
      actions: {
        reconnect: (b) => run(() => Printer.reconnect(), b),
        connect: (b) => run(() => Printer.connect(), b),
        retry: (b) => run(() => Printer.retryQueue(), b),
        clear: () => Printer.clearQueue(),
        test: () => printSafe('Test print', (o) => Printer.testPrint({ business: B.business, ...o })),
        toggle: (b) => { Printer.saveConfig({ [b.dataset.key]: b.checked }); },
      },
    });
    function paint(s) {
      if (!m.el.isConnected) return;
      const c = Printer.config();
      const p = printerState(s);
      const linkable = c.method === 'bluetooth' || c.method === 'serial';
      m.$('.printer-body').innerHTML = `
        <div class="printer-status ${p.cls}"><b>${esc(p.long)}</b>
          <div class="small">${c.paper} mm paper${s.lastError && !s.connected ? ' · ' + esc(s.lastError) : ''}</div></div>
        ${s.queue ? `<div class="alert alert-warn mt">${plural(s.queue, 'print job')} waiting for the printer.
            <div class="row gap-sm mt"><button type="button" class="btn" data-act="retry">Print now</button><button type="button" class="btn" data-act="clear">Discard</button></div></div>` : ''}
        <div class="printer-actions mt">
          ${linkable ? `<button type="button" class="btn btn-primary btn-xl" data-act="reconnect" ${s.connected || s.connecting ? 'disabled' : ''}>↻ Reconnect</button>
            <button type="button" class="btn btn-xl" data-act="connect">＋ Connect new printer</button>` : ''}
          <button type="button" class="btn btn-xl" data-act="test">⎙ Test print</button>
        </div>
        <div class="col gap-sm mt">
          <label class="checkbox"><input type="checkbox" data-act="toggle" data-key="autoPrint" ${c.autoPrint ? 'checked' : ''}> Print the receipt automatically after payment</label>
          <label class="checkbox"><input type="checkbox" data-act="toggle" data-key="openDrawer" ${c.openDrawer ? 'checked' : ''}> Open the cash drawer when a receipt prints</label>
        </div>`;
    }
    Printer.onStatus(paint);
  }

  // =====================================================================================================
  // 7. Printing hooks (window.Printer from printer.js)
  // =====================================================================================================
  const printCtx = (extra) => ({ business: B.business, tax: B.tax, methods: B.payment_methods, refundMethods: B.refund_methods, ...extra });
  const Print = {
    receipt: (t, reprint) => printSafe(t.status === 'open' ? 'Quote' : 'Receipt', (o) => Printer.printReceipt(t, printCtx({ reprint: !!reprint, ...o }))),
    refund: (r, t) => printSafe('Refund slip', (o) => Printer.printReturn(r, t, printCtx(o))),
    reading: (r, isZ) => printSafe(isZ ? 'Z-reading' : 'X-reading', (o) => Printer.printReading(r, printCtx(o), isZ)),
  };

  /**
   * Run a print job. Resolves true when printed.
   * - Printer asleep / out of range: printer.js keeps the job (err.queued) and prints it on reconnect.
   * - Other failures: offer Retry / Print with browser.
   */
  async function printSafe(what, job) {
    try {
      await job({});
      return true;
    } catch (err) {
      if (err.queued) return queuedDialog(what, err, job);
      App.toast(`${what}: ${err.message}`, 'error');
      return printFailedDialog(what, err, job);
    }
  }

  function queuedDialog(what, err, job) {
    return new Promise((resolve) => {
      let ok = false;
      const m = modal({
        title: `${what} saved`,
        body: `<div class="alert alert-warn">The printer is not connected. The ${esc(what.toLowerCase())} is saved and will print by itself when the printer reconnects.</div>
          <p class="muted small">Check that the printer is switched on, charged and near this device.</p>`,
        foot: '<button type="button" class="btn btn-lg" data-act="browser">Print with browser instead</button><span class="grow"></span><button type="submit" class="btn btn-primary btn-lg">OK</button>',
        onSubmit: () => m.close(),
        onClose: () => resolve(ok),
        actions: {
          browser: async (b) => {
            Printer.dropJob(err.jobId);
            if (await run(async () => { await job({ method: 'browser' }); return true; }, b)) { ok = true; m.close(); }
          },
        },
      });
    });
  }

  function printFailedDialog(what, err, job) {
    return new Promise((resolve) => {
      let ok = false;
      const linkable = ['bluetooth', 'serial'].includes(Printer.config().method);
      const m = modal({
        title: `${what} not printed`,
        body: `<div class="alert alert-error">${esc(err.message)}</div><p class="muted">Check that the printer is on, has paper and is near this device.</p>`,
        foot: `<button type="button" class="btn btn-lg" data-x>Close</button><span class="grow"></span>
          <button type="button" class="btn btn-lg" data-act="browser">Print with browser</button>
          <button type="button" class="btn btn-primary btn-lg" data-act="retry">${linkable && !Printer.isConnected() ? 'Connect & retry' : 'Retry'}</button>`,
        onClose: () => resolve(ok),
        actions: {
          retry: async (b) => {
            // This click lets the browser show its printer picker when the printer was never chosen
            const r = await run(async () => {
              if (linkable && !Printer.isConnected()) { try { await Printer.reconnect(); } catch (e) { await Printer.connect(); } }
              await job({});
              return true;
            }, b);
            if (r) { ok = true; m.close(); }
          },
          browser: async (b) => {
            const r = await run(async () => { await job({ method: 'browser' }); return true; }, b);
            if (r) { ok = true; m.close(); }
          },
        },
      });
    });
  }

  /** Printer chip text / colour for the top bar, from Printer.status(). */
  function printerState(s = Printer.status()) {
    const names = { browser: 'Browser print', rawbt: 'RawBT app', bluetooth: 'Bluetooth', serial: 'USB / serial' };
    const name = names[s.method] || s.method;
    if (s.method === 'browser' || s.method === 'rawbt') return { cls: 'ok', text: name, long: `${name} — ready`, queue: 0 };
    if (s.connecting) return { cls: 'busy', text: 'Connecting…', long: `Connecting to ${s.deviceName || 'the printer'}…`, queue: s.queue };
    if (s.connected) return { cls: 'ok', text: 'Connected', long: `Connected: ${s.deviceName || name}`, queue: s.queue };
    return { cls: 'warn', text: 'Not connected', long: `${name} printer not connected${s.deviceName ? ' (' + s.deviceName + ')' : ''}`, queue: s.queue };
  }

  /** On-screen previews use the same layout as the printed slip (printer.js document model). */
  function previewOf(doc) {
    const paper = Number(Printer.config().paper) === 80 ? 80 : 58;
    return `<div class="paper-${paper}">${Printer._internals.html(doc, paper)}</div>`;
  }
  const previewReceipt = (t) => previewOf(Printer._internals.receiptDoc(t, printCtx({})));
  const previewReading = (r, isZ) => previewOf(Printer._internals.readingDoc(r, printCtx({}), isZ));

  // =====================================================================================================
  // 8. Screen & keyboard (Android tablets) + install as an app
  // =====================================================================================================
  /**
   * Keep everything above the on-screen keyboard. The viewport meta has interactive-widget=resizes-content
   * (the page shrinks when the keyboard opens); for older Chrome we also follow window.visualViewport and
   * expose its size as CSS variables: --app-h (visible height) and --app-top (offset), used by .pos and dialogs.
   * html.kb-open is set while the keyboard is up (the visible height dropped well below the full height).
   */
  function trackViewport() {
    const vv = window.visualViewport;
    const html = document.documentElement;
    let fullH = 0;
    let lastW = 0;
    const update = () => {
      const h = vv ? vv.height : window.innerHeight;
      const w = vv ? vv.width : window.innerWidth;
      if (w !== lastW) { fullH = 0; lastW = w; }            // rotated: measure the full height again
      fullH = Math.max(fullH, h);
      html.style.setProperty('--app-h', h + 'px');
      html.style.setProperty('--app-top', (vv ? vv.offsetTop : 0) + 'px');
      const open = h < fullH * 0.8;
      if (html.classList.contains('kb-open') && !open) keyboardClosed();
      html.classList.toggle('kb-open', open);
      if (open) keepFocusedVisible();
    };
    if (vv) { vv.addEventListener('resize', update); vv.addEventListener('scroll', update); }
    window.addEventListener('resize', update);
    update();
  }
  /** Inputs that open the phone keyboard (not our keypad number fields). */
  const isTyping = (el) => el.matches('input:not([readonly]):not([type=checkbox]):not([type=radio]):not([type=date]), textarea');
  function keepFocusedVisible() {
    const el = document.activeElement;
    if (el && isTyping(el) && el.id !== 'pos-search') el.scrollIntoView({ block: 'nearest' });
  }
  /** The keyboard was closed with the Android back button: leave search mode too. */
  function keyboardClosed() {
    if (document.activeElement === search) search.blur();
  }

  /** Register the (pass-through) service worker so Chrome offers "Install app". Needs https or localhost. */
  function setupInstall() {
    if ('serviceWorker' in navigator && window.isSecureContext) {
      navigator.serviceWorker.register(App.url('/sw.js')).catch(() => {});
    }
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      S.installPrompt = e;
      renderTop();
    });
    window.addEventListener('appinstalled', () => { S.installPrompt = null; renderTop(); App.toast('Installed — open “Mark6 POS” from the home screen'); });
  }
  async function installApp() {
    const p = S.installPrompt;
    if (!p) return;
    p.prompt();
    await p.userChoice;
    S.installPrompt = null;
    renderTop();
  }

  // =====================================================================================================
  // 9. Events & start-up
  // =====================================================================================================
  /** Top bar, held sales, sale panel and phone bar buttons (data-act). */
  const ACTIONS = {
    held: () => { if (S.view === 'held') loadHeld(); else S.queue.then(showHeld); },
    newsale: () => newSale(),
    receipts: receiptsDialog,
    payout: payoutDialog,
    xread: xReadDialog,
    eod: async () => { await loadHeld(); endOfDayDialog(); },
    openday: openDayDialog,
    printer: printerDialog,
    install: installApp,
    logout,
    refresh: () => { loadHeld(); loadMenu(); },
    resume: (b) => resumeSale(Number(b.dataset.id)),
    cardMenu: (b) => cardMenu(Number(b.dataset.id)),
    details: detailsDialog,
    line: (b) => S.queue.then(() => {
      const l = S.sale && S.sale.items.find((i) => i.id === Number(b.dataset.id));
      if (l) { S.highlight = { item_id: l.item_id, uom_id: l.uom_id || null }; renderOrder(); lineDialog(l); }
    }),
    discount: () => S.queue.then(discountDialog),
    hold: holdSale,
    quote: () => S.queue.then(() => { if (activeLines(S.sale).length) Print.receipt(S.sale, false); }),
    cancel: cancelSale,
    pay: startPay,
    pane: (b) => { S.pane = b.dataset.pane; render(); },
    qty: async () => {
      const v = await askNumber({ title: 'Quantity for the next item', value: '', okText: 'Set' });
      if (v !== null) { S.multiplier = v; renderMultiplier(); }
    },
  };

  const pos = document.getElementById('pos');
  pos.addEventListener('click', (e) => {
    const tile = e.target.closest('[data-key]');
    if (tile && tile.closest('#tiles')) {
      const item = S.menu.items.find((i) => i.key === tile.dataset.key);
      if (item) addItem({ item_id: item.id, uom_id: item.uom_id, qty: 1 }, item);
      return;
    }
    const cat = e.target.closest('[data-cat]');
    if (cat) { S.cat = cat.dataset.cat === 'all' ? 'all' : Number(cat.dataset.cat); search.value = ''; renderMenu(); return; }
    const b = e.target.closest('[data-act]');
    if (b && !b.disabled && ACTIONS[b.dataset.act]) ACTIONS[b.dataset.act](b, e);
  });
  // Held-sale cards are not <button>s (they contain the ⋯ button): open them with Enter / Space too
  pos.addEventListener('keydown', (e) => {
    const card = e.target.closest('.order-card');
    if (card && e.target === card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); resumeSale(Number(card.dataset.id)); }
  });

  // ---- Item search / barcode scanner.
  // While the search box has focus (keyboard up) the top bar and categories fold away so the results get the room.
  // Tapping a tile adds it and keeps the results and the keyboard; Enter looks the code up (barcode scanners).
  const search = $('#pos-search');
  search.addEventListener('focus', () => pos.classList.add('searching'));
  search.addEventListener('blur', () => pos.classList.remove('searching'));
  search.addEventListener('input', renderMenu);
  search.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = search.value;
    search.value = '';
    renderMenu();
    scan(code);
  });
  $('#pos-search-clear').addEventListener('mousedown', (e) => e.preventDefault());   // keep the keyboard open
  $('#pos-search-clear').addEventListener('click', () => { search.value = ''; renderMenu(); });
  $('#pos-search-done').addEventListener('click', () => search.blur());
  // Tiles must not take the focus away from the search box (that would close the keyboard)
  $('#tiles').addEventListener('mousedown', (e) => { if (document.activeElement === search) e.preventDefault(); });

  // Typing on a physical keyboard / scanner anywhere on the sale screen goes to the search box (no tap needed)
  document.addEventListener('keydown', (e) => {
    if (document.querySelector('dialog[open]') || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
    if (e.target.closest('input, textarea, select')) return;
    if (S.view !== 'sale') newSale();
    search.focus();
  });
  // A text field in a dialog that gets the keyboard: scroll it into view once the keyboard is up
  document.addEventListener('focusin', (e) => {
    if (isTyping(e.target) && e.target.closest('dialog')) setTimeout(() => e.target.scrollIntoView({ block: 'nearest' }), 350);
  });

  // Printer status in the top bar; keep the tablet awake while the POS is open (if chosen in Printer setup)
  Printer.onStatus(() => renderTop());
  if (Printer.config().keepAwake) Printer.keepAwake(true);

  // Multiple terminals: refresh the held sales and the session every 20 s (and when the tab comes back into view)
  setInterval(() => { if (!document.hidden) loadHeld(); }, 20000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { loadHeld(); loadMenu(); } });

  trackViewport();
  setupInstall();
  S.sale = draftSale();
  render();
  loadMenu();
  loadHeld();
})();
