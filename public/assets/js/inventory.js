/**
 * Inventory screens (plain JavaScript). Loaded at the end of the inventory views; uses window.App from app.js.
 *
 *  <button data-ask="Post this document?" data-ask-message="…" data-ok="Post">   confirm, then submit with this button
 *  <button data-print>                                print the page (only #print-root is printed, see app.css)
 *  <form data-item-form>      item page: sections per item type, live margin % for the item and its pack sizes
 *  <form data-doc-form>       purchases / issuance / write-offs: units and lots per item, costs and totals, auto-added blank line
 *  <form data-count-sheet>    count sheet: live variance and totals, filters, Enter / arrow keys move between inputs
 *
 * JSON blocks embedded by the views: #inv-items (document item data by id), #inv-type-hints, #inv-uom-abbr.
 * The server recalculates and validates everything on save; these figures are only a preview.
 */
(function () {
  'use strict';
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const n = (v) => { const x = parseFloat(v); return Number.isFinite(x) ? x : 0; };
  const r2 = (v) => Math.round(v * 100) / 100;
  const r4 = (v) => Math.round(v * 10000) / 10000;
  const json = (id) => { const el = document.getElementById(id); return el ? JSON.parse(el.textContent) : {}; };
  const fmt4 = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
  const cost4 = (v) => fmt4.format(v);

  // ------------------------------------------------------------------ units an item can be entered in (cached)
  const unitsCache = {};
  function unitsFor(itemId) {
    if (!unitsCache[itemId]) {
      unitsCache[itemId] = App.api('GET', `/api/inventory/items/${itemId}/units`).catch((err) => {
        delete unitsCache[itemId];
        App.toast(err.message, 'error');
        return [];
      });
    }
    return unitsCache[itemId];
  }

  /** Replace a unit <select>'s options; each option carries data-factor (base units per 1). */
  function fillUnits(select, units, selected) {
    select.innerHTML = units.map((u) => `<option value="${u.uom_id}" data-factor="${u.factor}">${App.esc(u.abbr)}</option>`).join('');
    if (selected && units.some((u) => String(u.uom_id) === String(selected))) select.value = String(selected);
  }

  /** Base units per 1 of the selected unit, or null when unknown. */
  function factorOf(select) {
    const o = select && select.selectedOptions[0];
    return o && o.dataset.factor !== '' ? n(o.dataset.factor) : null;
  }

  // ------------------------------------------------------------------ confirm buttons, printing, unsaved changes
  document.addEventListener('click', async (e) => {
    if (e.target.closest('[data-print]')) { window.print(); return; }
    const btn = e.target.closest('button[data-ask]');
    if (!btn || !btn.form) return;
    e.preventDefault();
    if (!btn.form.reportValidity()) return;
    const message = [btn.dataset.askMessage, btn.form.dataset.askExtra].filter(Boolean).join(' ');
    if (await App.ask({ title: btn.dataset.ask, message, okText: btn.dataset.ok || 'OK' })) btn.form.requestSubmit(btn);
  });

  /** Warn before leaving a form with unsaved changes. */
  function warnUnsaved(form) {
    let dirty = false;
    const mark = (e) => { if (e.target.name) dirty = true; };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  // ------------------------------------------------------------------ item form
  function initItemForm(form) {
    const hints = json('inv-type-hints');
    const abbr = json('inv-uom-abbr');
    const typeSel = $('[data-item-type]', form);
    const baseSel = $('[data-base-unit]', form);
    const price = $('[data-price]', form);
    const avgCost = $('[data-avg-cost]', form);
    const vatExempt = $('[data-vat-exempt]', form);
    const whPrice = $('[data-wholesale-price]', form);
    const whQty = $('[data-wholesale-qty]', form);
    const packs = $('[data-rows="uoms"]', form);
    const vatRate = n(form.dataset.vatRate);
    const type = () => typeSel.value;
    const out = (key, text) => { const el = $(`[data-out="${key}"]`, form); if (el) el.textContent = text; };
    const tone = (el, t) => { if (!el) return; el.classList.remove('stat-red', 'stat-amber', 'stat-green'); if (t) el.classList.add('stat-' + t); };
    const divisor = () => (vatExempt && vatExempt.checked ? 1 : 1 + vatRate);

    function showSections() {
      $$('[data-show-for]', form).forEach((el) => el.classList.toggle('hidden', !el.dataset.showFor.split(' ').includes(type())));
      $('[data-type-hint]', form).textContent = hints[type()] || '';
    }

    function updateAbbr() {
      $$('[data-base-abbr]', form).forEach((el) => { el.textContent = abbr[baseSel.value] || 'base unit'; });
    }

    /** Margin figures from the selling price and (for products) the average / opening cost. */
    function costing() {
      const p = n(price.value);
      const cost = type() === 'product' && avgCost ? n(avgCost.value) : 0;
      const net = p / divisor();
      const margin = net - cost;
      out('net', p ? App.peso(net) : '—');
      out('net-sub', vatExempt && vatExempt.checked ? 'VAT-exempt item' : vatRate ? `÷ ${(1 + vatRate).toFixed(2)}` : 'non-VAT');
      out('cost', type() === 'product' ? App.peso(cost) : 'n/a (service)');
      out('margin', p ? App.peso(margin) : '—');
      out('margin-pct', p && net > 0 ? `${((margin / net) * 100).toFixed(2)}% of net price` : '');
      out('markup', p && cost > 0 ? `${((margin / cost) * 100).toFixed(1)}%` : '—');
      const wp = n(whPrice && whPrice.value);
      const wq = n(whQty && whQty.value);
      out('wholesale-note', wp && wq ? `wholesale ${App.peso(wp)} from ${wq} ${abbr[baseSel.value] || ''} (${(((wp / divisor() - cost) / (wp / divisor() || 1)) * 100).toFixed(1)}% margin)` : '');
      const pct = net > 0 ? (margin / net) * 100 : null;
      tone($('[data-tone="margin"]', form), pct === null ? '' : pct < 0 ? 'red' : pct < 10 ? 'amber' : 'green');
      tone($('[data-tone="markup"]', form), '');
      $('[data-out="margin-warn"]', form).classList.toggle('hidden', !(p > 0 && cost > 0 && margin < 0));
      // pack units: margin of the pack price vs. factor x cost
      $$('[data-row]', packs).forEach((tr) => {
        const f = n($('[data-field="factor"]', tr).value);
        const pp = n($('[data-field="price"]', tr).value);
        const cell = $('[data-pack-margin]', tr);
        if (!cell) return;
        if (!pp || !f) { cell.textContent = pp ? '' : (f && p ? `≈ ${App.peso(p * f)} at unit price` : ''); return; }
        const pnet = pp / divisor();
        const pcost = f * cost;
        const ppct = pnet > 0 ? ((pnet - pcost) / pnet) * 100 : 0;
        cell.textContent = type() === 'product' && cost ? `${ppct.toFixed(1)}%` : '';
        cell.classList.toggle('text-red', ppct < 0);
      });
    }

    typeSel.addEventListener('change', () => {
      if (form.dataset.new === '1') $('[data-sellable]', form).checked = true;
      showSections();
      costing();
    });
    baseSel.addEventListener('change', () => { updateAbbr(); costing(); });
    [price, avgCost, vatExempt, whPrice, whQty].forEach((el) => { if (el) { el.addEventListener('input', costing); el.addEventListener('change', costing); } });
    packs.addEventListener('input', costing);
    form.addEventListener('rows:change', () => { updateAbbr(); costing(); });

    showSections();
    costing();
    warnUnsaved(form);
  }

  // ------------------------------------------------------------------ purchase / issuance / write-off form
  const lotsCache = {};
  function lotsFor(itemId) {
    if (!lotsCache[itemId]) {
      lotsCache[itemId] = App.api('GET', `/api/inventory/items/${itemId}/batches`).catch(() => { delete lotsCache[itemId]; return []; });
    }
    return lotsCache[itemId];
  }

  function initDocForm(form) {
    const items = json('inv-items');
    const isRec = form.dataset.docType === 'RECEIVE';
    const vatRate = n(form.dataset.vatRate);
    const box = $('[data-rows="lines"]', form);
    const field = (tr, name) => $(`[data-field="${name}"]`, tr);

    /** Keep one empty line at the bottom to type the next item into. */
    function ensureBlankRow() {
      const rows = $$('[data-row]', box);
      const last = rows[rows.length - 1];
      if (!last || field(last, 'item_id').value) App.addRow('lines');
    }

    function syncTotal(tr) {
      const q = field(tr, 'qty').value;
      const c = field(tr, 'unit_cost').value;
      if (q !== '' && c !== '') field(tr, 'line_total').value = r2(n(q) * n(c)).toFixed(2);
    }

    /** Purchase cost pre-filled from the item's last cost, converted to the chosen unit. */
    function setAutoCost(tr) {
      const it = items[field(tr, 'item_id').value];
      const f = factorOf(field(tr, 'uom_id'));
      const c = it ? r4(n(it.cost) * (f === null ? 1 : f)) : 0;
      field(tr, 'unit_cost').value = c ? String(c) : '';
      tr.dataset.auto = c ? '1' : '';
      syncTotal(tr);
    }

    /** Lot fields only matter for lot-tracked items: purchases get lot no. + expiry, issues / write-offs a lot picker. */
    async function syncLotFields(tr) {
      const id = field(tr, 'item_id').value;
      const it = items[id];
      const tracked = !!(it && it.lots);
      $$('[data-lot-field]', tr).forEach((el) => {
        el.disabled = !tracked;
        el.classList.toggle('hidden', !tracked);
        if (!tracked && el.tagName !== 'SELECT') el.value = '';
      });
      const picker = field(tr, 'batch_id');
      if (picker && tracked && id) {
        const keep = picker.value;
        const lots = await lotsFor(id);
        picker.innerHTML = '<option value="">FEFO (earliest expiry first)</option>' + lots.map((l) => `<option value="${l.id}">${App.esc(l.label)}</option>`).join('');
        if (keep && lots.some((l) => String(l.id) === String(keep))) picker.value = keep;
      }
    }

    /** Line value: the purchase line total, or for issuance / write-off qty x factor x average cost. */
    function lineCost(tr) {
      if (isRec) return n(field(tr, 'line_total').value);
      const it = items[field(tr, 'item_id').value];
      const f = factorOf(field(tr, 'uom_id'));
      return it && f !== null ? n(field(tr, 'qty').value) * f * n(it.unit_cost) : 0;
    }

    function recalc() {
      let total = 0;
      let count = 0;
      $$('[data-row]', box).forEach((tr) => {
        const has = !!field(tr, 'item_id').value;
        const c = has ? lineCost(tr) : 0;
        if (has) { count++; total += c; }
        if (!isRec) $('[data-est]', tr).textContent = has ? App.peso(c) : '';
      });
      $('[data-line-count]', form).textContent = `${count} item${count === 1 ? '' : 's'}`;
      $('[data-doc-total]', form).textContent = App.peso(total);
      const vatBox = $('[data-vat-inclusive]', form);
      const vat = isRec && vatBox && vatBox.checked && vatRate ? total - total / (1 + vatRate) : 0;
      $('[data-vat-note]', form).textContent = vat ? `incl. input VAT ${App.peso(vat)} · net ${App.peso(total - vat)}` : '';
      form.dataset.askExtra = `Total: ${App.peso(total)}.`;
    }

    box.addEventListener('change', async (e) => {
      const tr = e.target.closest('[data-row]');
      if (!tr) return;
      const name = e.target.dataset.field;
      if (name === 'item_id') {
        const id = e.target.value;
        const unitSel = field(tr, 'uom_id');
        if (id) {
          fillUnits(unitSel, await unitsFor(id), items[id] && items[id].base);
          if (isRec) setAutoCost(tr);
          await syncLotFields(tr);
          ensureBlankRow();
          field(tr, 'qty').focus();
        } else {
          unitSel.innerHTML = '';
          syncLotFields(tr);
        }
      } else if (name === 'uom_id' && isRec && tr.dataset.auto === '1') {
        setAutoCost(tr);
      }
      recalc();
    });
    box.addEventListener('input', (e) => {
      const tr = e.target.closest('[data-row]');
      const name = e.target.dataset.field;
      if (tr && isRec && (name === 'qty' || name === 'unit_cost')) {
        if (name === 'unit_cost') tr.dataset.auto = '';
        syncTotal(tr);
      }
      if (tr && isRec && name === 'line_total') {
        tr.dataset.auto = '';
        const q = n(field(tr, 'qty').value);
        if (q) field(tr, 'unit_cost').value = String(r4(n(e.target.value) / q));
      }
      recalc();
    });
    form.addEventListener('rows:change', () => { ensureBlankRow(); recalc(); });

    const mode = $('[data-payment-mode]', form);
    if (mode) {
      const sync = () => {
        $('[data-bank-field]', form).classList.toggle('hidden', mode.value !== 'bank');
        $('[data-supplier-required]', form).classList.toggle('hidden', mode.value !== 'credit');
      };
      mode.addEventListener('change', sync);
      sync();
      $('[data-vat-inclusive]', form).addEventListener('change', recalc);
    }

    // Existing lines (drafts re-opened, failed saves): hide lot fields for items that are not lot-tracked
    $$('[data-row]', box).forEach((tr) => { if (field(tr, 'item_id').value) syncLotFields(tr); });

    ensureBlankRow();
    recalc();
    warnUnsaved(form);
  }

  // ------------------------------------------------------------------ count sheet
  function initCountSheet(form) {
    const lines = $$('tr[data-line]', form);
    const inputs = $$('input[data-count]', form);
    const search = $('[data-count-search]', form);
    const signed = (v, fmt) => (v > 0 ? '+' : '') + fmt(v);
    const setTone = (el, v) => {
      el.classList.remove('text-red', 'text-green');
      if (Math.abs(v) >= 0.00005) el.classList.add(v < 0 ? 'text-red' : 'text-green');
    };
    const stat = (key, text, v) => { const el = $(`[data-stat="${key}"]`); if (!el) return; el.textContent = text; if (v !== undefined) setTone(el, v); };

    function recalc() {
      let counted = 0;
      let short = 0;
      let over = 0;
      lines.forEach((tr) => {
        const input = $('[data-count]', tr);
        const varCell = $('[data-var]', tr);
        const valCell = $('[data-value]', tr);
        tr.dataset.counted = input.value === '' ? '' : '1';
        if (input.value === '') {
          tr.dataset.variance = '';
          varCell.textContent = '';
          valCell.textContent = '';
          return;
        }
        const v = r4(n(input.value) - n(tr.dataset.sys));
        const value = r2(v * n(tr.dataset.cost));
        counted++;
        if (value < 0) short += value; else over += value;
        tr.dataset.variance = Math.abs(v) >= 0.00005 ? '1' : '';
        varCell.textContent = signed(v, App.qty);
        valCell.textContent = signed(value, App.money);
        setTone(varCell, v);
        setTone(valCell, value);
      });
      stat('counted', `${counted} / ${lines.length}`);
      stat('short', App.peso(short), short);
      stat('over', App.peso(over), over);
      stat('net', App.peso(short + over), short + over);
      stat('net-foot', App.peso(short + over), short + over);
      const changed = inputs.filter((i) => i.value !== i.defaultValue).length;
      $('[data-dirty-note]', form).textContent = changed ? `${changed} unsaved change${changed === 1 ? '' : 's'}` : '';
    }

    function filter() {
      const q = search.value.trim().toLowerCase();
      const onlyUncounted = $('[data-count-filter="uncounted"]', form).checked;
      const onlyVariance = $('[data-count-filter="variance"]', form).checked;
      let shown = 0;
      lines.forEach((tr) => {
        const ok = (!q || tr.dataset.search.includes(q)) && (!onlyUncounted || !tr.dataset.counted) && (!onlyVariance || tr.dataset.variance);
        tr.classList.toggle('hidden', !ok);
        if (ok) shown++;
      });
      $$('tr[data-group]', form).forEach((g) => {
        let any = false;
        for (let el = g.nextElementSibling; el && !el.hasAttribute('data-group'); el = el.nextElementSibling) {
          if (!el.classList.contains('hidden')) any = true;
        }
        g.classList.toggle('hidden', !any);
      });
      $('[data-count-shown]', form).textContent = `${shown} of ${lines.length} items`;
    }

    search.addEventListener('input', filter);
    $$('[data-count-filter]', form).forEach((cb) => cb.addEventListener('change', filter));
    if (!inputs.length) return; // posted or cancelled: figures come from the server

    form.addEventListener('input', (e) => { if (e.target.matches('[data-count]')) recalc(); });
    form.addEventListener('keydown', (e) => {
      if (!e.target.matches('[data-count]') || !['Enter', 'ArrowDown', 'ArrowUp'].includes(e.key)) return;
      e.preventDefault();
      const visible = inputs.filter((i) => !i.closest('tr').classList.contains('hidden'));
      const next = visible[visible.indexOf(e.target) + (e.key === 'ArrowUp' ? -1 : 1)];
      if (next) next.focus();
    });
    recalc();
    warnUnsaved(form);
  }

  document.addEventListener('DOMContentLoaded', () => {
    $$('form[data-item-form]').forEach(initItemForm);
    $$('form[data-doc-form]').forEach(initDocForm);
    $$('form[data-count-sheet]').forEach(initCountSheet);
  });
})();
