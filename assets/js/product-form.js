/* Product form enhancer — Quill (rich text), Select2 (tags/labels/category),
 * Flatpickr (availability dates), and repeatable FAQ/highlight/video rows.
 * Progressive: the form fully works without JS; this only upgrades the UX. */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }
  function appToast(type, msg) { if (window.App && App.toast) { App.toast(type, msg); } else if (type === 'error') { window.alert(msg); } }

  ready(function () {
    var $ = window.jQuery;

    // Quill rich-text editors -> sync into a hidden textarea on input.
    var quills = {};
    if (window.Quill) {
      document.querySelectorAll('.js-quill').forEach(function (el) {
        var name = el.getAttribute('data-target');
        var ta = document.querySelector('textarea[name="' + name + '"]');
        var q = new Quill(el, { theme: 'snow' });
        if (ta && ta.value) { q.clipboard.dangerouslyPasteHTML(ta.value); }
        q.on('text-change', function () {
          if (ta) { ta.value = q.root.innerHTML === '<p><br></p>' ? '' : q.root.innerHTML; }
        });
        quills[name] = q;
      });
    }

    // Select2: searchable category/brand/labels + free-tagging tags.
    if ($ && $.fn && $.fn.select2) {
      $('.js-select').select2({ theme: 'bootstrap-5', width: '100%' });
      $('.js-tags').select2({ theme: 'bootstrap-5', width: '100%', tags: true, tokenSeparators: [','] });
    }

    // Flatpickr date pickers (ISO yyyy-mm-dd to match the DATE columns).
    if (window.flatpickr) {
      document.querySelectorAll('.js-date').forEach(function (el) {
        flatpickr(el, { dateFormat: 'Y-m-d', allowInput: true });
      });
    }

    // AI suggestions (dev-stub server-side): fill SEO / tags / description.
    var form = document.getElementById('productForm');
    function selText(name) {
      var s = document.querySelector('select[name="' + name + '"]');
      return s && s.selectedIndex >= 0 ? s.options[s.selectedIndex].text.replace(/\s+—.*$/, '').trim() : '';
    }
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.js-ai');
      if (!btn || !form) { return; }
      e.preventDefault();
      var tokenInput = form.querySelector('input[type="hidden"][value]');
      var body = new FormData();
      body.append('kind', btn.getAttribute('data-ai'));
      body.append('title', (form.querySelector('input[name="title"]') || {}).value || '');
      body.append('category', selText('category_id'));
      body.append('brand', selText('brand_id'));
      // include the CSRF hidden field the framework rendered into the form
      form.querySelectorAll('input[type="hidden"]').forEach(function (i) { if (i.name && i.name !== 'vendor_id') { body.append(i.name, i.value); } });
      var label = btn.innerHTML; btn.disabled = true; btn.textContent = '…';
      fetch(form.getAttribute('data-ai-url'), { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var d = (res && res.data) || {};
          var kind = btn.getAttribute('data-ai');
          if (kind === 'seo') {
            if (d.meta_title) { form.querySelector('input[name="meta_title"]').value = d.meta_title; }
            if (d.meta_description) { form.querySelector('textarea[name="meta_description"]').value = d.meta_description; }
            if (d.meta_keywords) { form.querySelector('input[name="meta_keywords"]').value = d.meta_keywords; }
          } else if (kind === 'tags' && $ && $.fn.select2) {
            var sel = $('.js-tags');
            (d.tags || []).forEach(function (t) {
              if (!sel.find('option[value="' + t + '"]').length) { sel.append(new Option(t, t, true, true)); }
            });
            sel.trigger('change');
          } else if (kind === 'description') {
            var target = btn.getAttribute('data-fill');
            if (quills[target]) { quills[target].setText(d.description || ''); }
            var ta = form.querySelector('textarea[name="' + target + '"]'); if (ta) { ta.value = d.description || ''; }
          }
        })
        .catch(function () {})
        .finally(function () { btn.disabled = false; btn.innerHTML = label; });
    });

    // Repeatable rows: [data-add] clones the matching <template id="tpl-..">.
    document.addEventListener('click', function (e) {
      var add = e.target.closest('[data-add]');
      if (add) {
        var host = document.querySelector(add.getAttribute('data-add'));
        var tpl = document.getElementById('tpl-' + add.getAttribute('data-tpl'));
        if (host && tpl) { host.appendChild(tpl.content.cloneNode(true)); }
        return;
      }
      var rm = e.target.closest('.js-rm');
      if (rm) {
        var group = rm.closest('.input-group, .row');
        if (group) { group.remove(); }
      }
    });

    // ---- Redesign shell (RB4): context mirror, completeness, cascade, autosave ----
    if (!form) { return; }
    var pid = parseInt(form.getAttribute('data-pid') || '0', 10);
    var autosaveBase = form.getAttribute('data-autosave-base') || '';
    var lookupBase = autosaveBase.replace('products/', 'lookup/');

    function csrfInput() {
      var hidden = form.querySelectorAll('input[type="hidden"]');
      for (var i = 0; i < hidden.length; i++) { if (hidden[i].name && hidden[i].name !== 'vendor_id') { return hidden[i]; } }
      return null;
    }
    function setToken(t) { var ci = csrfInput(); if (ci && t) { ci.value = t; } }

    var byId = function (id) { return document.getElementById(id); };
    function selText(name) { var s = form.querySelector('[name="' + name + '"]'); return s && s.selectedIndex >= 0 ? s.options[s.selectedIndex].text : ''; }

    // live mirror of context + name + preview card
    function val(name) { var e = form.querySelector('[name="' + name + '"]'); return e ? e.value : ''; }
    function mirror() {
      var title = val('title') || 'Product name';
      if (byId('pfName')) { byId('pfName').textContent = val('title') || 'New product'; }
      if (byId('pfCtxCat')) { byId('pfCtxCat').textContent = selText('category_id') || '—'; }
      if (byId('pfCtxType')) { byId('pfCtxType').textContent = selText('product_type') || '—'; }
      if (byId('pfCtxTax')) { byId('pfCtxTax').textContent = selText('tax_class_id') || '—'; }
      // preview card
      if (byId('pfPvTitle')) { byId('pfPvTitle').textContent = title; }
      if (byId('pfPvCat')) { byId('pfPvCat').textContent = selText('category_id') || '—'; }
      var priceEl = form.querySelector('[name="base_price"]'), mrpEl = form.querySelector('[name="mrp"]');
      var price = priceEl ? (parseFloat(priceEl.value) || 0) : null;
      var mrp   = mrpEl   ? (parseFloat(mrpEl.value)   || 0) : null;
      if (byId('pfPvPrice')) { byId('pfPvPrice').textContent = price !== null ? '₹' + price.toLocaleString('en-IN') : '—'; }
      if (byId('pfPvMrp'))   { byId('pfPvMrp').textContent   = (mrp !== null && price !== null && mrp > price) ? '₹' + mrp.toLocaleString('en-IN') : ''; }
    }

    // completeness meter
    function completeness() {
      // Price/SKU/barcode/stock live on the Variants page — the add page just
      // needs identity: shop, name, category, tax, unit.
      var req = [
        ['Product name', '[name="title"]'], ['Shop', '[name="shop_id"]'], ['Category', '[name="category_id"]'],
        ['Tax class', '[name="tax_class_id"]'], ['Unit', '[name="unit_id"]']
      ];
      var done = 0, missing = [];
      req.forEach(function (r) {
        var el = form.querySelector(r[1]);
        var ok = el && String(el.value || '').trim() !== '';
        if (ok) { done++; } else { missing.push(r[0]); }
      });
      var pct = Math.round((done / req.length) * 100);
      if (byId('pfMeter')) { byId('pfMeter').style.width = pct + '%'; }
      if (byId('pfPct')) { byId('pfPct').textContent = pct + '%'; }
      if (byId('pfChecklist')) {
        byId('pfChecklist').innerHTML = missing.length
          ? missing.map(function (m) { return '<li>• ' + m + ' missing</li>'; }).join('')
          : '<li class="text-success">• All set — ready to submit</li>';
      }
    }

    // category -> tax/HSN/GST defaults (RB2 lookup). Bound via jQuery because the
    // category select is a Select2, which fires `change` through jQuery (a native
    // addEventListener handler would never run).
    function rebuildBrandSelect(brands, currentVal) {
      var brandEl = byId('fBrand');
      if (!brandEl) { return; }
      var cur = currentVal !== undefined ? currentVal : brandEl.value;
      brandEl.innerHTML = '<option value="">—</option>';
      brands.forEach(function (b) {
        var o = document.createElement('option');
        o.value = b.id; o.textContent = b.name;
        if (String(b.id) === String(cur)) { o.selected = true; }
        brandEl.appendChild(o);
      });
      if (window.jQuery) { window.jQuery(brandEl).trigger('change.select2'); }
    }

    var catEl = byId('fCategory');
    if (catEl && window.jQuery) {
      window.jQuery(catEl).on('change', function () {
        var id = catEl.value;
        if (!id) {
          if (window.__pfAllBrands) { rebuildBrandSelect(window.__pfAllBrands); }
          return;
        }
        fetch(lookupBase + 'categories/' + id + '/defaults', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); }).then(function (res) {
            var d = (res && res.data) || {};
            if (d.tax_class_id && byId('fTax')) { byId('fTax').value = d.tax_class_id; if (window.jQuery) { window.jQuery(byId('fTax')).trigger('change.select2'); } }
            if (d.gst_pct != null && byId('pfCtxTax')) { byId('pfCtxTax').textContent = 'GST ' + d.gst_pct + '%'; }
          }).catch(function () {});
        fetch(lookupBase + 'categories/' + id + '/brands', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); })
          .then(function (res2) { rebuildBrandSelect((res2 && res2.data) || []); })
          .catch(function () {});
      });
    }

    // edit-mode autosave per accordion section (debounced)
    var timers = {};
    function autosave(section, item) {
      if (pid <= 0 || !section) { return; }
      var body = new FormData();
      item.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (!el.name) { return; }
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { return; }
        body.append(el.name, el.value);
      });
      var ci = csrfInput(); if (ci) { body.append(ci.name, ci.value); }
      var tEl = form.querySelector('[name="title"]'); if (tEl) { body.append('title', tEl.value); }
      fetch(autosaveBase + pid + '/autosave/' + section, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); }).then(function (res) {
          if (res && res.csrf) { setToken(res.csrf); }
          if (res && res.saved_at && byId('pfSaved')) { byId('pfSaved').textContent = 'Saved ' + res.saved_at; }
        }).catch(function () {});
    }

    form.addEventListener('input', function (e) {
      mirror(); completeness();
      var item = e.target.closest('[data-section]');
      if (item) {
        var section = item.getAttribute('data-section');
        clearTimeout(timers[section]);
        timers[section] = setTimeout(function () { autosave(section, item); }, 800);
      }
    });
    form.addEventListener('change', function () { mirror(); completeness(); });

    // keep the primary-barcode radio index in sync with row order at submit
    form.addEventListener('submit', function () {
      var rows = form.querySelectorAll('#barcodeRows .js-bc-row');
      rows.forEach(function (row, idx) {
        var radio = row.querySelector('input[name="barcode_primary"]');
        if (radio) { radio.value = idx; }
      });
    });

    // ---- Media (RB7): gallery drag-sort + set-primary / remove / documents ----
    var mediaBase = form.getAttribute('data-media-base') || '';
    function postMedia(action, fd) {
      var ci = csrfInput(); if (ci) { fd.append(ci.name, ci.value); }
      return fetch(mediaBase + action, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.csrf) { setToken(res.csrf); } return res; })
        .catch(function () { return { ok: false }; });
    }
    var gallery = byId('pfGallery');
    if (gallery && mediaBase) {
      var dragEl = null;
      gallery.addEventListener('dragstart', function (e) { dragEl = e.target.closest('.pf-tile'); });
      gallery.addEventListener('dragover', function (e) {
        e.preventDefault();
        var t = e.target.closest('.pf-tile');
        if (t && dragEl && t !== dragEl) {
          var rect = t.getBoundingClientRect();
          gallery.insertBefore(dragEl, (e.clientX - rect.left) > rect.width / 2 ? t.nextSibling : t);
        }
      });
      gallery.addEventListener('drop', function (e) {
        e.preventDefault();
        var fd = new FormData();
        gallery.querySelectorAll('.pf-tile').forEach(function (t) { fd.append('order[]', t.getAttribute('data-media-id')); });
        postMedia('reorder', fd);
      });
    }
    document.addEventListener('click', function (e) {
      if (!mediaBase) { return; }
      var p = e.target.closest('.js-media-primary');
      if (p) { var f1 = new FormData(); f1.append('media_id', p.getAttribute('data-id')); postMedia('primary', f1).then(function (r) { if (r.ok) { location.reload(); } }); return; }
      var rm = e.target.closest('.js-media-remove');
      if (rm) {
        var rmId = rm.getAttribute('data-id');
        var doRm = function () { var f2 = new FormData(); f2.append('media_id', rmId); postMedia('remove', f2).then(function (r) { if (r.ok) { location.reload(); } }); };
        if (window.App && App.confirm) { App.confirm('Remove this image?').then(function (ok) { if (ok) { doRm(); } }); }
        else if (confirm('Remove this image?')) { doRm(); }
        return;
      }
      var du = e.target.closest('.js-doc-upload');
      if (du) {
        var file = byId('docFile');
        if (!file || !file.files.length) { return; }
        var f3 = new FormData(); f3.append('document', file.files[0]);
        f3.append('doc_type', (byId('docType') || {}).value || 'manual');
        f3.append('doc_title', (byId('docTitle') || {}).value || '');
        postMedia('doc-upload', f3).then(function (r) { r.ok ? location.reload() : appToast('error', 'Upload failed — check the file type/size.'); });
        return;
      }
      var dd = e.target.closest('.js-doc-delete');
      if (dd) { postMedia('doc-delete/' + dd.getAttribute('data-id'), new FormData()).then(function (r) { if (r.ok) { location.reload(); } }); }
    });

    // ---- Media library picker: reuse existing images (multi-select) or upload new ----
    var libOpen = byId('pfLibOpen'), libModalEl = byId('pfLibModal');
    if (libOpen && libModalEl && mediaBase && window.bootstrap) {
        var libModal = new bootstrap.Modal(libModalEl);
        var libGrid = byId('pfLibGrid'), libAdd = byId('pfLibAdd'), libCount = byId('pfLibCount');
        var selected = {};
        function updateCount() {
            var n = Object.keys(selected).length;
            libCount.textContent = n ? (n + ' selected') : '';
            libAdd.disabled = n === 0;
        }
        function loadLibrary() {
            selected = {}; updateCount();
            libGrid.innerHTML = '<div class="col-12 text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>';
            fetch(mediaBase + 'library', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) { libGrid.innerHTML = '<div class="col-12 text-danger small">Could not load your library.</div>'; return; }
                    var attached = res.attached || [];
                    if (!res.images || !res.images.length) { libGrid.innerHTML = '<div class="col-12 text-secondary small py-3 text-center">No images in your library yet — use the “Upload new” tab.</div>'; return; }
                    libGrid.innerHTML = '';
                    res.images.forEach(function (im) {
                        var isOn = attached.indexOf(im.id) >= 0;
                        var col = document.createElement('div');
                        col.className = 'col-3 col-md-2';
                        col.innerHTML = '<div class="pf-lib-tile position-relative" style="cursor:' + (isOn ? 'default' : 'pointer') + ';border:2px solid transparent;border-radius:.45rem;overflow:hidden;' + (isOn ? 'opacity:.45' : '') + '">'
                            + '<img src="' + im.url + '" style="width:100%;height:72px;object-fit:cover;display:block">'
                            + (isOn ? '<span class="badge text-bg-success position-absolute top-0 end-0 m-1">Added</span>'
                                    : '<span class="pf-lib-check position-absolute top-0 end-0 m-1 d-none"><i class="bi bi-check-circle-fill text-primary fs-5"></i></span>')
                            + '</div>';
                        if (!isOn) {
                            var tile = col.querySelector('.pf-lib-tile');
                            tile.addEventListener('click', function () {
                                if (selected[im.id]) { delete selected[im.id]; tile.style.borderColor = 'transparent'; tile.querySelector('.pf-lib-check').classList.add('d-none'); }
                                else { selected[im.id] = true; tile.style.borderColor = '#5b21b6'; tile.querySelector('.pf-lib-check').classList.remove('d-none'); }
                                updateCount();
                            });
                        }
                        libGrid.appendChild(col);
                    });
                })
                .catch(function () { libGrid.innerHTML = '<div class="col-12 text-danger small">Could not load your library.</div>'; });
        }
        libOpen.addEventListener('click', function () { loadLibrary(); libModal.show(); });
        libAdd.addEventListener('click', function () {
            var ids = Object.keys(selected);
            if (!ids.length) { return; }
            libAdd.disabled = true;
            var fd = new FormData();
            ids.forEach(function (id) { fd.append('media_ids[]', id); });
            postMedia('attach', fd).then(function (res) {
                if (res && res.ok) { location.reload(); }
                else { libAdd.disabled = false; appToast('error', 'Could not add the selected images.'); }
            });
        });
        var libDrop = byId('pfLibDrop'), libUpInput = byId('pfLibUpInput'), upMsg = byId('pfUpMsg');
        function uploadFiles(files) {
            if (!files || !files.length) { return; }
            var fd = new FormData(), any = false;
            for (var i = 0; i < files.length; i++) { if (/^image\//.test(files[i].type)) { fd.append('image[]', files[i]); any = true; } }
            if (!any) { upMsg.innerHTML = '<span class="text-danger">Only image files are allowed.</span>'; return; }
            upMsg.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
            postMedia('upload-image', fd).then(function (res) {
                if (res && res.ok) { location.reload(); }
                else { upMsg.innerHTML = '<span class="text-danger">' + ((res && res.errors && res.errors.join('; ')) || 'Upload failed.') + '</span>'; }
            });
        }
        if (libDrop && libUpInput) {
            libDrop.addEventListener('click', function () { libUpInput.click(); });
            libDrop.addEventListener('dragover', function (e) { e.preventDefault(); libDrop.classList.add('drag'); });
            libDrop.addEventListener('dragleave', function () { libDrop.classList.remove('drag'); });
            libDrop.addEventListener('drop', function (e) { e.preventDefault(); libDrop.classList.remove('drag'); uploadFiles(e.dataTransfer && e.dataTransfer.files); });
            libUpInput.addEventListener('change', function () { uploadFiles(libUpInput.files); });
        }
    }

    // preview toggle: show/hide the live preview card
    var pv = byId('pfPreviewToggle');
    function syncPreviewBtn(hidden) {
      if (!pv) { return; }
      pv.innerHTML = hidden ? '<i class="bi bi-eye me-1"></i>Preview' : '<i class="bi bi-eye-slash me-1"></i>Hide';
    }
    syncPreviewBtn(false); // card starts visible
    if (pv) {
      pv.addEventListener('click', function () {
        var card = byId('pfPreview');
        if (!card) { return; }
        var willHide = card.style.display !== 'none';
        card.style.display = willHide ? 'none' : '';
        syncPreviewBtn(!willHide);
      });
    }

    // ---- S2: simple-vs-variant reshape, vendor cascade, image dropzone ----
    // Variant products price/barcode per variant, so hide those product-level
    // blocks (.js-simple-only) and reveal the "set it on the Variants page"
    // notes (.js-variant-only). Driven by the product type + run once on load.
    var typeEl = form.querySelector('[name="product_type"]');
    function syncTypeCards() {
      var val = typeEl ? typeEl.value : '';
      form.querySelectorAll('.js-type-card').forEach(function (c) {
        var on = c.getAttribute('data-type') === val;
        c.classList.toggle('btn-primary', on);
        c.classList.toggle('btn-outline-primary', !on);
      });
    }
    function reshapeForm() {
      var isVariant = typeEl && typeEl.value === 'variant';
      form.querySelectorAll('.js-simple-only').forEach(function (el) { el.classList.toggle('d-none', isVariant); });
      form.querySelectorAll('.js-variant-only').forEach(function (el) { el.classList.toggle('d-none', !isVariant); });
      syncTypeCards();
    }
    form.querySelectorAll('.js-type-card').forEach(function (c) {
      c.addEventListener('click', function () {
        if (typeEl) { typeEl.value = c.getAttribute('data-type'); typeEl.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
    if (typeEl) { typeEl.addEventListener('change', reshapeForm); }
    reshapeForm();

    // Item 4: on an existing variant product, warn before changing the type away
    // from "variant" OR changing the category — both permanently delete the
    // generated variants (the category defines which attributes a variant uses).
    var originalType = typeEl ? typeEl.value : '';
    var catEl = form.querySelector('[name="category_id"]');
    var originalCat = catEl ? catEl.value : '';
    if (pid > 0 && originalType === 'variant') {
      form.addEventListener('submit', function (e) {
        if (e.defaultPrevented) { return; }
        var typeChanged = typeEl && typeEl.value !== 'variant';
        var catChanged = catEl && catEl.value !== originalCat;
        if ((typeChanged || catChanged) &&
            !window.confirm('This product has variants. Changing its ' + (typeChanged ? 'type' : 'category') + ' will permanently delete the generated variants and their options. This cannot be undone. Continue?')) {
          e.preventDefault();
        }
      });
    }

    // Admin: picking a vendor reloads its allowed categories + its shops. Bound
    // via jQuery — the vendor select is a Select2, which fires `change` through
    // jQuery, so a native addEventListener handler would never run (THE bug that
    // made the add page look dead).
    function refreshSelect(el) { if (el && window.jQuery && window.jQuery.fn.select2) { window.jQuery(el).val(null).trigger('change.select2'); } }
    var vendorEl = byId('fVendor');
    if (vendorEl && window.jQuery) {
      window.jQuery(vendorEl).on('change', function () {
        var vid = vendorEl.value, cat = byId('fCategory'), shop = byId('fShop');
        if (!vid) {
          if (cat) { cat.innerHTML = '<option value="">Choose…</option>'; refreshSelect(cat); }
          if (shop) { shop.innerHTML = '<option value="">Choose a shop…</option>'; refreshSelect(shop); }
          completeness(); return;
        }
        fetch(lookupBase + 'vendors/' + vid + '/categories', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); }).then(function (res) {
            if (!cat) { return; }
            var opts = '<option value="">Choose…</option>';
            ((res && res.data) || []).forEach(function (c) { opts += '<option value="' + c.id + '">' + c.name + '</option>'; });
            cat.innerHTML = opts; refreshSelect(cat); completeness();
          }).catch(function () {});
        fetch(lookupBase + 'vendors/' + vid + '/shops', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); }).then(function (res) {
            if (!shop) { return; }
            var rows = (res && res.data) || [];
            var opts = '<option value="">' + (rows.length ? 'Choose a shop…' : 'This vendor has no shops yet') + '</option>';
            rows.forEach(function (s) { opts += '<option value="' + s.id + '">' + (s.name || '') + '</option>'; });
            shop.innerHTML = opts; refreshSelect(shop); completeness();
          }).catch(function () {});
      });
    }

    // Image dropzone: click-to-browse, drag-drop, instant preview with remove.
    var dz = byId('pfDropzone'), imgInput = byId('pfImageInput'), strip = byId('pfPreviewStrip');
    if (dz && imgInput) {
      dz.addEventListener('click', function () { imgInput.click(); });
      dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('drag'); });
      dz.addEventListener('dragleave', function () { dz.classList.remove('drag'); });
      dz.addEventListener('drop', function (e) {
        e.preventDefault(); dz.classList.remove('drag');
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) { return; }
        // input.files isn't directly writable cross-browser — copy via DataTransfer.
        try { var dt = new DataTransfer(); for (var i = 0; i < files.length; i++) { dt.items.add(files[i]); } imgInput.files = dt.files; }
        catch (err) { try { imgInput.files = files; } catch (e2) { return; } }
        imgInput.dispatchEvent(new Event('change'));
      });
      imgInput.addEventListener('change', function () {
        if (!strip) { return; }
        strip.innerHTML = '';
        Array.prototype.slice.call(imgInput.files).forEach(function (f) {
          if (!/^image\//.test(f.type)) { return; }
          var reader = new FileReader();
          reader.onload = function (ev) {
            var wrap = document.createElement('div');
            wrap.className = 'position-relative';
            wrap.style.cssText = 'width:76px;height:76px';
            wrap.innerHTML = '<img src="' + ev.target.result + '" style="width:76px;height:76px;object-fit:cover;border-radius:.4rem;border:1px solid #e7eaf3">'
              + '<button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 p-0 lh-1" style="width:18px;height:18px;font-size:11px" title="Remove">&times;</button>';
            wrap.querySelector('button').addEventListener('click', function () {
              imgInput.value = ''; strip.innerHTML = '';
              var pvImg = byId('pfPvImg'), pvNo = byId('pfPvNoImg');
              if (pvImg) { pvImg.src = ''; pvImg.style.display = 'none'; }
              if (pvNo)  { pvNo.style.display = ''; }
            });
            strip.appendChild(wrap);
            // mirror first selected image into the preview card
            if (strip.querySelectorAll('.position-relative').length === 1) {
              var pvImg = byId('pfPvImg'), pvNo = byId('pfPvNoImg');
              if (pvImg) { pvImg.src = ev.target.result; pvImg.style.display = ''; }
              if (pvNo)  { pvNo.style.display = 'none'; }
            }
          };
          reader.readAsDataURL(f);
        });
      });
    }

    mirror(); completeness();
  });
})();
