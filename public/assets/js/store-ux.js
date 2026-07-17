/* ==========================================================================
   store-ux.js — quick-commerce storefront behaviour on top of ajax-forms.js
   `app:success`:
     - Wishlist add        -> fill the heart
     - Quick-commerce stepper (ADD / − N +) -> live qty update, no reload
     - Add to cart         -> open + refresh the slide-over cart drawer
     - In-drawer +/-/remove -> refresh the drawer
   The steppers are plain forms (CSRF handled by ajax-forms.js); we only toggle
   their ADD/−N+ state and keep the − form's target qty in sync.
   ========================================================================== */
(function () {
    'use strict';

    // Stepper show/hide CSS (injected so it works on every page).
    var st = document.createElement('style');
    st.textContent =
        '.st-stepper .st-qty{display:none}'
        + '.st-stepper.in-cart .st-add{display:none}'
        + '.st-stepper.in-cart .st-qty{display:inline-flex}'
        + '.st-stepper .st-q{pointer-events:none;min-width:2.1rem;text-align:center}'
        + '.st-stepper form{display:inline}';
    document.head.appendChild(st);

    var drawerEl = document.getElementById('cartDrawer');
    var sheetEl  = document.getElementById('variantSheet');

    function badge() { return document.getElementById('cartCountBadge'); }
    function setBadge(n) {
        var b = badge(); if (!b) { return; }
        if (n > 0) { b.textContent = String(n); b.style.display = ''; } else { b.textContent = ''; b.style.display = 'none'; }
    }
    function syncBadgeFromMini() {
        var h = document.querySelector('#miniCartBody [data-cart-count]');
        if (h) { setBadge(parseInt(h.getAttribute('data-cart-count'), 10) || 0); }
    }
    function reloadMiniCart() {
        var body = document.getElementById('miniCartBody');
        if (!body || !drawerEl) { return; }
        fetch(drawerEl.getAttribute('data-mini-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) { body.innerHTML = html; syncBadgeFromMini(); })
            .catch(function () {});
    }
    function openDrawer() {
        if (drawerEl && window.bootstrap) { window.bootstrap.Offcanvas.getOrCreateInstance(drawerEl).show(); }
    }
    function syncStepper(stp, q) {
        stp.setAttribute('data-qty', String(q));
        stp.classList.toggle('in-cart', q > 0);
        var qEl = stp.querySelector('.st-q'); if (qEl) { qEl.textContent = String(q); }
        var dec = stp.querySelector('.st-dec input[name="qty"]'); if (dec) { dec.value = String(Math.max(0, q - 1)); }
    }

    if (drawerEl) { drawerEl.addEventListener('show.bs.offcanvas', reloadMiniCart); }

    document.addEventListener('app:success', function (e) {
        var form = e.detail && e.detail.form;
        if (!form) { return; }
        var action = form.getAttribute('action') || '';

        if (action.indexOf('wishlist/add') !== -1) {
            var icon = form.querySelector('i.bi');
            if (icon) { icon.classList.remove('bi-heart'); icon.classList.add('bi-heart-fill'); }
            var wb = form.querySelector('button'); if (wb) { wb.classList.add('text-danger'); wb.title = 'Saved to wishlist'; }
            return;
        }

        var isAdd = action.indexOf('cart/add') !== -1;
        var isUpd = action.indexOf('cart/update') !== -1;
        var isRem = action.indexOf('cart/remove') !== -1;
        if (!isAdd && !isUpd && !isRem) { return; }

        // Action inside the drawer mini-cart → just refresh the drawer.
        if (drawerEl && drawerEl.contains(form)) { reloadMiniCart(); return; }

        // Action inside the quick-add variant sheet → update the row's stepper in
        // place and refresh the cart count, but keep the sheet open (no drawer pop).
        if (sheetEl && sheetEl.contains(form)) {
            var sstp = form.closest ? form.closest('.st-stepper') : null;
            if (sstp) {
                var sq = parseInt(sstp.getAttribute('data-qty'), 10) || 0;
                if (isUpd) { sq = Math.max(0, sq - 1); } else if (isAdd) { sq = sq + 1; } else if (isRem) { sq = 0; }
                syncStepper(sstp, sq);
            }
            reloadMiniCart();
            return;
        }

        // On-page stepper → optimistic qty update.
        var stp = form.closest ? form.closest('.st-stepper') : null;
        if (stp) {
            var q = parseInt(stp.getAttribute('data-qty'), 10) || 0;
            if (isUpd) { q = Math.max(0, q - 1); } else if (isAdd) { q = q + 1; } else if (isRem) { q = 0; }
            syncStepper(stp, q);
        }
        reloadMiniCart();
        if (isAdd) { openDrawer(); }
    });

    // ---- Quick-add variant bottom sheet (multi-variant product cards) ----
    function openVariantSheet(url, title) {
        if (!sheetEl || !window.bootstrap) { window.location = url.replace(/\/variants$/, ''); return; }
        var body = document.getElementById('variantSheetBody');
        var ttl  = document.getElementById('variantSheetTitle');
        // Title comes from a vendor-controlled product name — insert as a TEXT node
        // (never innerHTML) so an HTML payload in the title can't execute.
        if (ttl) {
            ttl.innerHTML = '<i class="bi bi-sliders2 me-2"></i>';
            ttl.appendChild(document.createTextNode(title || 'Choose an option'));
        }
        if (body) { body.innerHTML = '<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>'; }
        window.bootstrap.Offcanvas.getOrCreateInstance(sheetEl).show();
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) { if (body) { body.innerHTML = html; } })
            .catch(function () { if (body) { body.innerHTML = '<div class="text-danger small py-3 text-center">Could not load options. Please try again.</div>'; } });
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-variant-sheet]') : null;
        if (!btn) { return; }
        e.preventDefault();
        openVariantSheet(btn.getAttribute('data-variant-sheet'), btn.getAttribute('data-title'));
    });
})();
