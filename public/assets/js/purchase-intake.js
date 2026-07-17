/* Purchase intake (Add Inventory) — keyboard-first, ports WPF flow + Appendix A math.
   Server re-computes authoritative totals on save; this is a live preview only. */
(function () {
  'use strict';
  var root = document.getElementById('pi');
  if (!root) return;

  var URLS = {
    search: root.dataset.search, store: root.dataset.store,
    supSearch: root.dataset.supsearch, supStore: root.dataset.supstore, exit: root.dataset.exit,
  };
  var csrfInput = root.querySelector('input[type="hidden"][name]');
  function csrf() { return csrfInput ? { name: csrfInput.name, value: csrfInput.value } : { name: '', value: '' }; }
  function setCsrf(v) { if (csrfInput && v) csrfInput.value = v; }
  var num = function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; };
  var r3 = function (n) { return Math.round(n * 1000) / 1000; };

  var lines = [];
  var seq = 0;

  // ---------- line math (Appendix A.1) ----------
  function compute(l) {
    var pp = num(l.purchase_price), qty = num(l.qty);
    var disc1 = num(l.disc_amt) > 0 ? num(l.disc_amt) : pp * num(l.disc_pct) / 100;
    var disc2 = num(l.disc_amt2) > 0 ? num(l.disc_amt2) : pp * num(l.disc_pct2) / 100;
    var net = Math.max(0, pp - disc1 - disc2);
    var cgst = r3(net * qty * num(l.cgst_pct) / 100);
    var sgst = r3(net * qty * num(l.sgst_pct) / 100);
    var igst = r3(net * qty * num(l.igst_pct) / 100);
    var cess = r3(net * qty * num(l.cess_pct) / 100);
    var without = r3(net * qty);
    return { net: net, disc1: disc1, disc2: disc2, cgst: cgst, sgst: sgst, igst: igst, cess: cess,
             without: without, discTotal: r3((disc1 + disc2) * qty), lineTotal: r3(without + cgst + sgst + igst + cess) };
  }

  // ---------- render ----------
  var elLines = document.getElementById('piLines');
  var elEmpty = document.getElementById('piEmpty');

  function fld(l, key, label, step) {
    return '<div><span class="lbl">' + label + '</span>' +
      '<input class="form-control" type="number" step="' + (step || '0.01') + '" data-id="' + l._id + '" data-k="' + key +
      '" value="' + (l[key] != null ? l[key] : 0) + '"></div>';
  }

  function render() {
    elEmpty.style.display = lines.length ? 'none' : '';
    elLines.innerHTML = lines.map(function (l) {
      var c = compute(l);
      return '<div class="pi-line">' +
        '<div class="pi-mode">' + (l.mode === 'update' ? 'UPDATE' : 'INSERT') + '</div>' +
        '<div><div class="fw-semibold small">' + escapeHtml(l.product_name || '') + '</div>' +
          '<div class="text-muted" style="font-size:.7rem">' + escapeHtml(l.barcode || '') +
          (l.hsn_code ? ' · HSN ' + escapeHtml(l.hsn_code) : '') + '</div></div>' +
        '<div class="d-flex flex-wrap gap-1">' +
          fld(l, 'purchase_price', 'Purchase') + fld(l, 'mrp', 'MRP') + fld(l, 'sale_price', 'Sale') +
          fld(l, 'qty', 'Qty', '1') + fld(l, 'free_qty', 'Free', '1') +
          fld(l, 'disc_pct', 'Disc%') + fld(l, 'disc_amt', 'Disc₹') + fld(l, 'disc_amt2', 'Addl₹') +
        '</div>' +
        '<div class="d-flex flex-wrap gap-1">' +
          fld(l, 'cgst_pct', 'CGST%') + fld(l, 'sgst_pct', 'SGST%') + fld(l, 'igst_pct', 'IGST%') + fld(l, 'cess_pct', 'CESS%') +
          '<div><span class="lbl">Total</span><div class="fw-bold pt-1">' + c.lineTotal.toFixed(2) + '</div></div>' +
        '</div>' +
        '<div><button class="btn btn-sm btn-outline-danger" data-rm="' + l._id + '">Remove</button></div>' +
      '</div>';
    }).join('');
    footer();
  }

  function footer() {
    var f = { sub: 0, disc: 0, qty: 0, sgst: 0, cgst: 0, igst: 0, cess: 0, grand: 0 };
    lines.forEach(function (l) {
      var c = compute(l);
      f.sub += c.without; f.disc += c.discTotal; f.qty += num(l.qty);
      f.sgst += c.sgst; f.cgst += c.cgst; f.igst += c.igst; f.cess += c.cess; f.grand += c.lineTotal;
    });
    f.grand += num(document.getElementById('piApmc').value);
    set('ftSub', f.sub.toFixed(3)); set('ftDisc', f.disc.toFixed(3)); set('ftQty', f.qty);
    set('ftSgst', f.sgst.toFixed(3)); set('ftCgst', f.cgst.toFixed(3)); set('ftIgst', f.igst.toFixed(3));
    set('ftCess', f.cess.toFixed(3)); set('ftGrand', Math.round(f.grand));
  }
  function set(id, v) { document.getElementById(id).textContent = v; }
  function escapeHtml(s) { return (s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // live edit
  elLines.addEventListener('input', function (e) {
    var id = e.target.dataset.id, k = e.target.dataset.k;
    if (!id) return;
    var l = lines.find(function (x) { return x._id == id; });
    if (l) { l[k] = e.target.value; footer(); var row = e.target.closest('.pi-line'); var t = row.querySelector('.fw-bold'); }
  });
  elLines.addEventListener('change', function () { render(); });
  elLines.addEventListener('click', function (e) {
    var rm = e.target.dataset.rm;
    if (rm) { lines = lines.filter(function (x) { return x._id != rm; }); render(); }
  });
  document.getElementById('piApmc').addEventListener('input', footer);

  function addLine(p) {
    lines.push({
      _id: ++seq, mode: 'insert', variant_id: p.variant_id, product_id: p.product_id,
      product_name: p.product_name, barcode: p.barcode || '', hsn_code: p.hsn_code || '',
      purchase_price: num(p.purchase_price), mrp: num(p.mrp), sale_price: num(p.sale_price),
      qty: 1, free_qty: 0, disc_pct: 0, disc_amt: 0, disc_pct2: 0, disc_amt2: 0,
      cgst_pct: num(p.cgst_pct), sgst_pct: num(p.sgst_pct), igst_pct: num(p.igst_pct), cess_pct: num(p.cess_pct),
    });
    render();
  }

  // ---------- product search ----------
  var search = document.getElementById('piSearch'), results = document.getElementById('piResults');
  var sIdx = -1, sItems = [], tmr;
  search.addEventListener('input', function () {
    clearTimeout(tmr);
    var q = search.value.trim();
    if (q.length < 1) { hide(results); return; }
    tmr = setTimeout(function () {
      fetch(URLS.search + '?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (j) {
        sItems = (j && j.items) || []; sIdx = -1;
        results.innerHTML = sItems.map(function (it, i) {
          return '<div class="item" data-i="' + i + '">' + escapeHtml(it.product_name) +
            ' <span class="text-muted">' + escapeHtml(it.sku || it.barcode || '') + '</span>' +
            ' <span class="float-end">MRP ' + num(it.mrp).toFixed(2) + '</span></div>';
        }).join('') || '<div class="item text-muted">No products</div>';
        results.style.display = 'block';
      });
    }, 180);
  });
  results.addEventListener('click', function (e) {
    var i = e.target.closest('.item') && e.target.closest('.item').dataset.i;
    if (i != null && sItems[i]) { addLine(sItems[i]); search.value = ''; hide(results); search.focus(); }
  });
  search.addEventListener('keydown', function (e) {
    if (results.style.display === 'none') return;
    if (e.key === 'ArrowDown') { sIdx = Math.min(sIdx + 1, sItems.length - 1); paint(); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { sIdx = Math.max(sIdx - 1, 0); paint(); e.preventDefault(); }
    else if (e.key === 'Enter') { if (sItems[sIdx] || sItems[0]) { addLine(sItems[sIdx] || sItems[0]); search.value = ''; hide(results); } e.preventDefault(); }
    else if (e.key === 'Escape') { hide(results); }
  });
  function paint() { Array.prototype.forEach.call(results.children, function (c, i) { c.classList.toggle('active', i === sIdx); }); }
  function hide(el) { el.style.display = 'none'; }

  // ---------- supplier search/select ----------
  var sup = document.getElementById('piSupplier'), supRes = document.getElementById('piSupplierResults');
  var supItems = [], supTmr;
  sup.addEventListener('input', function () {
    clearTimeout(supTmr);
    supTmr = setTimeout(function () {
      fetch(URLS.supSearch + '?q=' + encodeURIComponent(sup.value.trim())).then(function (r) { return r.json(); }).then(function (j) {
        supItems = (j && j.items) || [];
        supRes.innerHTML = supItems.map(function (it, i) {
          return '<div class="item" data-i="' + i + '">' + escapeHtml(it.name) + ' <span class="text-muted">' + escapeHtml(it.phone || it.gstin || '') + '</span></div>';
        }).join('') || '<div class="item text-muted">No suppliers</div>';
        supRes.style.display = 'block';
      });
    }, 180);
  });
  supRes.addEventListener('click', function (e) {
    var i = e.target.closest('.item') && e.target.closest('.item').dataset.i;
    if (i != null && supItems[i]) selectSupplier(supItems[i]);
  });
  function selectSupplier(s) {
    document.getElementById('piSupplierId').value = s.id;
    sup.value = s.name; hide(supRes);
    var card = document.getElementById('piSupplierCard');
    card.style.display = 'block';
    card.innerHTML = '<strong>' + escapeHtml(s.name) + '</strong> · GSTIN ' + escapeHtml(s.gstin || '-') +
      ' · ' + escapeHtml(s.city || '') + ' ' + escapeHtml(s.state_code || '') +
      '<br>' + escapeHtml(s.address || '');
  }

  // ---------- new supplier modal ----------
  var modal = document.getElementById('piSupModal');
  function openModal() { modal.style.display = 'flex'; document.getElementById('sName').focus(); }
  function closeModal() { modal.style.display = 'none'; }
  document.getElementById('piNewSupplier').addEventListener('click', openModal);
  document.getElementById('sCancel').addEventListener('click', closeModal);
  document.getElementById('sSave').addEventListener('click', function () {
    var body = {
      name: val('sName'), gstin: val('sGst'), aadhaar_no: val('sAadhaar'), phone: val('sPhone'),
      email: val('sEmail'), address: val('sAddress'), city: val('sCity'), state_code: val('sState'),
      pincode: val('sPin'), salesman_name: val('sSalesman'), salesman_phone: val('sSalesPhone'),
    };
    if (!body.name) { alert('Supplier name is required'); return; }
    post(URLS.supStore, body).then(function (j) {
      if (j && j.ok) { setCsrf(j.csrf); selectSupplier(j.supplier); closeModal(); }
      else alert((j && j.message) || 'Could not save supplier');
    });
  });
  function val(id) { return document.getElementById(id).value.trim(); }

  // ---------- save purchase ----------
  document.getElementById('piSave').addEventListener('click', savePurchase);
  document.getElementById('piExit').addEventListener('click', function () { location.href = URLS.exit; });
  function savePurchase() {
    if (!lines.length) { alert('Add at least one product line'); return; }
    var header = {
      supplier_id: document.getElementById('piSupplierId').value || null,
      challan_no: val('piChallan'), supplier_invoice_id: val('piSupInv'),
      invoice_date: document.getElementById('piDate').value, apmc: num(document.getElementById('piApmc').value),
    };
    post(URLS.store, { header: header, lines: lines }).then(function (j) {
      if (j && j.ok) { location.href = 'history'; }
      else { if (j && j.csrf) setCsrf(j.csrf); alert((j && j.message) || 'Could not save purchase'); }
    });
  }

  function post(url, body) {
    var c = csrf(); body[c.name] = c.value;
    return fetch(url, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }

  // ---------- keyboard shortcuts (Appendix C) ----------
  document.addEventListener('keydown', function (e) {
    if (e.ctrlKey && !e.shiftKey) {
      var k = e.key.toLowerCase();
      if (k === 'u') { e.preventDefault(); sup.focus(); sup.select(); }
      else if (k === 'x') { e.preventDefault(); modal.style.display === 'flex' ? closeModal() : openModal(); }
      else if (k === 'i') { e.preventDefault(); search.focus(); }
      else if (k === 'm') { e.preventDefault(); search.focus(); /* Phase 2: inline Add-Product panel */ }
      else if (k === 's') { e.preventDefault(); modal.style.display === 'flex' ? document.getElementById('sSave').click() : savePurchase(); }
      else if (k === 'q') { e.preventDefault(); location.href = URLS.exit; }
    }
    if (e.key === 'Escape') { hide(results); hide(supRes); }
  });

  document.addEventListener('click', function (e) {
    if (!sup.contains(e.target) && !supRes.contains(e.target)) hide(supRes);
    if (!search.contains(e.target) && !results.contains(e.target)) hide(results);
  });

  render();
})();
