/* Web POS billing — search/scan -> cart -> split pay -> save + 80mm receipt.
 * Totals shown here are for the cashier; the SERVER recomputes prices + GST on
 * save (PosSaleRepository), so the screen never decides the money. */
(function () {
  'use strict';
  var root = document.getElementById('pos');
  if (!root) { return; }
  var shop = root.getAttribute('data-shop');
  var urlSearch = root.getAttribute('data-search');
  var urlSale = root.getAttribute('data-sale');
  var urlReceipt = root.getAttribute('data-receipt');
  var cart = [];
  var payments = [];
  var activeIdx = -1, results = [];

  function $(id) { return document.getElementById(id); }
  function token() { var i = root.querySelector('input[type="hidden"][name]'); return i ? { name: i.name, val: i.value, el: i } : null; }
  function setToken(t) { var i = token(); if (i && t) { i.el.value = t; } }
  function notify(type, msg) { if (window.App && App.toast) { App.toast(type, msg); } else if (type === 'error') { alert(msg); } }
  function rupee(n) { return '₹' + (Math.round(n * 100) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  // ---- search / scan ----
  var searchBox = $('posSearch'), resBox = $('posResults');
  var timer;
  function doSearch() {
    var q = searchBox.value.trim();
    if (q.length < 1) { resBox.classList.add('d-none'); return; }
    fetch(urlSearch + '?shop_id=' + shop + '&q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(function (res) {
        results = (res && res.items) || []; activeIdx = results.length ? 0 : -1; renderResults();
      }).catch(function () {});
  }
  function renderResults() {
    if (!results.length) { resBox.innerHTML = '<div class="item text-secondary">No match</div>'; resBox.classList.remove('d-none'); return; }
    resBox.innerHTML = results.map(function (it, i) {
      return '<div class="item' + (i === activeIdx ? ' active' : '') + '" data-i="' + i + '">' +
        '<div class="d-flex justify-content-between"><span>' + esc(it.title) + ' <span class="text-secondary small">' + esc(it.sku) + '</span></span>' +
        '<span>' + rupee(parseFloat(it.unit_price != null ? it.unit_price : it.base_price) || 0) + ' · <span class="text-secondary small">stk ' + (parseFloat(it.available) || 0) + '</span></span></div></div>';
    }).join('');
    resBox.classList.remove('d-none');
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  resBox.addEventListener('click', function (e) { var it = e.target.closest('.item'); if (it && it.getAttribute('data-i') !== null) { addToCart(results[+it.getAttribute('data-i')]); } });
  searchBox.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(doSearch, 180); });
  searchBox.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, results.length - 1); renderResults(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); renderResults(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (results[activeIdx]) { addToCart(results[activeIdx]); } }
    else if (e.key === 'Escape') { resBox.classList.add('d-none'); }
  });

  // ---- cart ----
  function addToCart(it) {
    if (!it) { return; }
    var ex = cart.find(function (c) { return c.variant_id === it.id; });
    if (ex) { ex.qty += 1; } else { cart.push({ variant_id: it.id, sku: it.sku, title: it.title, qty: 1, unit_price: parseFloat(it.unit_price != null ? it.unit_price : it.base_price) || 0, tax_rate: parseFloat(it.tax_rate) || 0 }); }
    searchBox.value = ''; resBox.classList.add('d-none'); results = []; renderCart(); searchBox.focus();
  }
  function renderCart() {
    var tb = $('posCart');
    if (!cart.length) { tb.innerHTML = '<tr id="posEmpty"><td colspan="5" class="text-center text-secondary py-5">Scan or search to add items.</td></tr>'; computeTotals(); return; }
    tb.innerHTML = cart.map(function (c, i) {
      return '<tr class="pos-cartrow"><td><div>' + esc(c.title) + '</div><div class="text-secondary small">' + esc(c.sku) + '</div></td>' +
        '<td class="text-center"><div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary js-dec" data-i="' + i + '">−</button>' +
        '<input class="form-control form-control-sm text-center js-qty" data-i="' + i + '" value="' + c.qty + '" style="width:54px">' +
        '<button class="btn btn-outline-secondary js-inc" data-i="' + i + '">+</button></div></td>' +
        '<td class="text-end">' + rupee(c.unit_price) + '</td><td class="text-end fw-semibold">' + rupee(c.unit_price * c.qty) + '</td>' +
        '<td class="text-end"><button class="btn btn-sm btn-link text-danger js-rm" data-i="' + i + '">×</button></td></tr>';
    }).join('');
    computeTotals();
  }
  $('posCart').addEventListener('click', function (e) {
    var i; var inc = e.target.closest('.js-inc'); var dec = e.target.closest('.js-dec'); var rm = e.target.closest('.js-rm');
    if (inc) { i = +inc.getAttribute('data-i'); cart[i].qty += 1; renderCart(); }
    else if (dec) { i = +dec.getAttribute('data-i'); cart[i].qty = Math.max(1, cart[i].qty - 1); renderCart(); }
    else if (rm) { i = +rm.getAttribute('data-i'); cart.splice(i, 1); renderCart(); }
  });
  $('posCart').addEventListener('change', function (e) {
    if (e.target.classList.contains('js-qty')) { var i = +e.target.getAttribute('data-i'); cart[i].qty = Math.max(1, parseFloat(e.target.value) || 1); renderCart(); }
  });

  // ---- totals (display only) ----
  function totals() {
    var sub = 0, gst = 0;
    cart.forEach(function (c) {
      var gross = c.unit_price * c.qty; sub += gross;
      var taxable = c.tax_rate > 0 ? gross / (1 + c.tax_rate / 100) : gross; gst += gross - taxable;
    });
    var disc = Math.max(0, parseFloat($('posDisc').value) || 0);
    var del = deliverMode ? Math.max(0, parseFloat($('delFee').value) || 0) : 0;
    var grand = Math.max(0, sub - disc) + del;
    var rounded = Math.round(grand);
    return { sub: sub, gst: gst, disc: disc, del: del, round: rounded - grand, grand: rounded };
  }
  function computeTotals() {
    var t = totals();
    $('posSub').textContent = rupee(t.sub); $('posGst').textContent = rupee(t.gst);
    $('posRound').textContent = rupee(t.round); $('posTotal').textContent = rupee(t.grand);
    renderPayRows(); updatePaid();
  }
  $('posDisc').addEventListener('input', computeTotals);

  // ---- payments ----
  document.getElementById('posTenders').addEventListener('click', function (e) {
    var b = e.target.closest('.pos-pay-btn'); if (!b) { return; }
    var tender = b.getAttribute('data-tender');
    var remaining = Math.max(0, totals().grand - paid());
    payments.push({ tender_type: tender, amount: remaining });
    renderPayRows(); updatePaid();
  });
  function paid() { return payments.reduce(function (s, p) { return s + (parseFloat(p.amount) || 0); }, 0); }
  function renderPayRows() {
    var host = $('posPayRows');
    host.innerHTML = payments.map(function (p, i) {
      return '<div class="input-group input-group-sm mb-1"><span class="input-group-text text-capitalize" style="width:70px">' + p.tender_type + '</span>' +
        '<input type="number" step="0.01" class="form-control js-pamt" data-i="' + i + '" value="' + p.amount + '">' +
        '<button class="btn btn-outline-danger js-prm" data-i="' + i + '">×</button></div>';
    }).join('');
  }
  $('posPayRows').addEventListener('input', function (e) { if (e.target.classList.contains('js-pamt')) { payments[+e.target.getAttribute('data-i')].amount = parseFloat(e.target.value) || 0; updatePaid(); } });
  $('posPayRows').addEventListener('click', function (e) { var rm = e.target.closest('.js-prm'); if (rm) { payments.splice(+rm.getAttribute('data-i'), 1); renderPayRows(); updatePaid(); } });
  function updatePaid() {
    var t = totals(); var pd = paid();
    $('posPaid').textContent = rupee(pd); $('posChange').textContent = rupee(Math.max(0, pd - t.grand));
    if ($('posUnpaid')) { $('posUnpaid').textContent = Math.max(0, t.grand - pd).toFixed(2); }
    $('posSave').disabled = cart.length === 0 || (!creditMode && pd + 0.001 < t.grand);
  }

  // ---- temp item + credit (udhaar) ----
  var creditMode = false;
  $('tempAdd').addEventListener('click', function () {
    var name = $('tempName').value.trim(), price = parseFloat($('tempPrice').value) || 0, qty = parseFloat($('tempQty').value) || 1, tax = parseFloat($('tempTax').value) || 0;
    if (!name || price <= 0) { return; }
    cart.push({ variant_id: 0, is_temp: true, sku: 'TEMP', title: name, qty: qty, unit_price: price, tax_rate: tax });
    $('tempName').value = ''; $('tempPrice').value = ''; $('tempQty').value = '1'; $('tempTax').value = '0';
    renderCart(); searchBox.focus();
  });
  $('posCredit').addEventListener('change', function () {
    creditMode = this.checked;
    $('posCreditFields').classList.toggle('d-none', !creditMode);
    updatePaid();
  });
  var deliverMode = false;
  $('posDeliver').addEventListener('change', function () {
    deliverMode = this.checked;
    $('posDeliverFields').classList.toggle('d-none', !deliverMode);
    computeTotals();
  });
  $('delFee').addEventListener('input', computeTotals);

  // ---- save ----
  function uuid() { var s = ''; for (var i = 0; i < 16; i++) { s += (Math.floor((1 + i * 7) % 16)).toString(16); } return s + (Date.now ? '' : ''); }
  function clientUuid() { return 'wp' + new Date().getTime() + Math.floor((parseFloat('0.' + (Math.round(totals().grand * 97) % 9999))) * 9999); }
  var saving = false;
  $('posSave').addEventListener('click', function () {
    if (saving || !cart.length) { return; }
    saving = true; $('posSave').disabled = true;
    var fd = new FormData();
    var tk = token(); if (tk) { fd.append(tk.name, tk.val); }
    fd.append('shop_id', shop); fd.append('bill_discount', $('posDisc').value || '0'); fd.append('client_uuid', clientUuid());
    var ri = 0, ti = 0;
    cart.forEach(function (c) {
      if (c.variant_id > 0) { fd.append('cart[' + ri + '][variant_id]', c.variant_id); fd.append('cart[' + ri + '][qty]', c.qty); ri++; }
      else { fd.append('temp_lines[' + ti + '][name]', c.title); fd.append('temp_lines[' + ti + '][price]', c.unit_price); fd.append('temp_lines[' + ti + '][qty]', c.qty); fd.append('temp_lines[' + ti + '][tax_rate]', c.tax_rate); ti++; }
    });
    payments.forEach(function (p, i) { fd.append('payments[' + i + '][tender_type]', p.tender_type); fd.append('payments[' + i + '][amount]', p.amount); });
    if (creditMode) {
      fd.append('mode', 'credit'); fd.append('customer_name', $('custName').value); fd.append('customer_mobile', $('custMobile').value); fd.append('due_date', $('custDue').value || '');
    }
    if (deliverMode) {
      fd.append('deliver', '1'); fd.append('deliver_name', $('delName').value); fd.append('deliver_phone', $('delPhone').value);
      fd.append('deliver_address', $('delAddress').value); fd.append('delivery_fee', $('delFee').value || '0'); fd.append('deliver_date', $('delDate').value || '');
    }
    fetch(urlSale, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res.csrf) { setToken(res.csrf); }
        if (res.ok) { window.open(urlReceipt + '/' + res.sale_id, '_blank'); reset(); notify('success', 'Sale saved.'); }
        else { notify('error', res.message || 'Could not save the sale.'); }
      }).catch(function () { notify('error', 'Network error.'); })
      .finally(function () { saving = false; updatePaid(); });
  });
  $('posClear').addEventListener('click', reset);
  function reset() {
    cart = []; payments = []; $('posDisc').value = '0';
    creditMode = false; $('posCredit').checked = false; $('posCreditFields').classList.add('d-none');
    if ($('custName')) { $('custName').value = ''; $('custMobile').value = ''; $('custDue').value = ''; }
    deliverMode = false; $('posDeliver').checked = false; $('posDeliverFields').classList.add('d-none');
    if ($('delName')) { $('delName').value = ''; $('delPhone').value = ''; $('delAddress').value = ''; $('delFee').value = '0'; $('delDate').value = ''; }
    renderCart(); searchBox.focus();
  }

  // ---- hotkeys ----
  document.addEventListener('keydown', function (e) {
    if (e.key === 'F2') { e.preventDefault(); searchBox.focus(); searchBox.select(); }
    else if (e.key === 'F6') { e.preventDefault(); $('posDisc').focus(); $('posDisc').select(); }
    else if (e.key === 'F9') { e.preventDefault(); var a = $('posPayRows').querySelector('.js-pamt'); if (a) { a.focus(); } else { document.querySelector('.pos-pay-btn').click(); } }
    else if (e.key === 'F12') { e.preventDefault(); if (!$('posSave').disabled) { $('posSave').click(); } }
  });

  renderCart();
})();
