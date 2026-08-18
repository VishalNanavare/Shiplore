/* ==========================================================================
   ajax-forms.js — system-wide progressive AJAX form submission
   --------------------------------------------------------------------------
   What it does (no per-form / per-controller changes needed):
     1. Intercepts every POST <form> submit and sends it via fetch (FormData).
     2. Shows a full-screen "block UI" overlay for the duration of the request.
     3. Renders the result through SweetAlert2 instead of server-rendered
        .alert panels (success/warning -> toast, error -> modal).
     4. Rotates the CodeIgniter 4 CSRF token (regenerate=true) from the JSON
        response so subsequent submits keep working.
     5. On first paint, upgrades any server-rendered flash .alert into a toast
        (covers the JS-disabled fallback path) and shows messages relayed from
        a prior AJAX redirect.

   Pairs with App\Filters\AjaxRedirectFilter, which turns the controller's
   redirect()->with(...) into JSON {ok, redirect, message, type, csrf} for AJAX.

   EXCLUDED from interception (left to their own handling):
     - GET forms (search / filters / switchers)
     - forms with a target (e.g. target="_blank" barcode print)
     - anything inside #pos or #cnApp (POS billing / credit-note custom JS)
     - any form marked [data-no-ajax]
   NOTE: #productForm (the product create/edit form) IS handled here, so a
   validation error keeps the page and ALL field input instead of a full reload.
   ========================================================================== */
(function () {
    'use strict';

    /* ---------- overlay styles (injected so it works on every layout/CSS) ---------- */
    var style = document.createElement('style');
    style.textContent =
        '#appBlockUI{position:fixed;inset:0;z-index:20000;display:none;align-items:center;'
        + 'justify-content:center;background:rgba(20,24,38,.45);backdrop-filter:blur(1px)}'
        + '#appBlockUI.show{display:flex}'
        + '#appBlockUI .au-box{display:flex;flex-direction:column;align-items:center;gap:.65rem;'
        + 'color:#fff;font:500 .9rem/1.2 system-ui,Segoe UI,Roboto,sans-serif}'
        + '#appBlockUI .spinner-border{width:2.6rem;height:2.6rem;border-width:.28em}'
        + 'form.ajax-busy{opacity:.65;pointer-events:none}';
    (document.head || document.documentElement).appendChild(style);

    /* ---------- block UI ---------- */
    var overlay = null;
    function ensureOverlay() {
        if (overlay) { return overlay; }
        overlay = document.createElement('div');
        overlay.id = 'appBlockUI';
        overlay.setAttribute('aria-busy', 'true');
        overlay.setAttribute('role', 'status');
        overlay.innerHTML = '<div class="au-box"><div class="spinner-border text-light"></div>'
            + '<span>Please wait…</span></div>';
        document.body.appendChild(overlay);
        return overlay;
    }
    function block()   { ensureOverlay().classList.add('show'); }
    function unblock()  { if (overlay) { overlay.classList.remove('show'); } }

    /* ---------- SweetAlert2 helpers (graceful fallback if Swal is missing) ---------- */
    function hasSwal() { return typeof window.Swal !== 'undefined'; }

    var toastInstance = null;
    function toast() {
        if (!toastInstance && hasSwal()) {
            toastInstance = window.Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false,
                timer: 2800, timerProgressBar: true,
                didOpen: function (el) {
                    el.addEventListener('mouseenter', window.Swal.stopTimer);
                    el.addEventListener('mouseleave', window.Swal.resumeTimer);
                }
            });
        }
        return toastInstance;
    }

    function notify(type, message) {
        if (!message) { return; }
        type = type || 'success';
        if (!hasSwal()) {                       // last-resort fallback
            if (type === 'error') { window.alert(message); }
            return;
        }
        if (type === 'error') {
            window.Swal.fire({ icon: 'error', title: 'Error', text: message });
        } else {
            var icon = (type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success');
            // `title` is an HTML sink in SweetAlert2 (it goes through the HTML parser);
            // `titleText` sets innerText and renders in the same .swal2-title element.
            // Flash text arrives here ALREADY DECODED - upgradeServerAlerts() reads
            // el.textContent, which undoes the esc() the layout applied - so it must
            // not be re-parsed as markup.
            toast().fire({ icon: icon, titleText: message });
        }
    }

    function confirmDialog(message) {
        if (!hasSwal()) { return Promise.resolve(window.confirm(message || 'Are you sure?')); }
        return window.Swal.fire({
            title: 'Are you sure?', text: message || '', icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Yes, continue',
            cancelButtonText: 'Cancel', confirmButtonColor: '#dc3545', reverseButtons: true
        }).then(function (r) { return r.isConfirmed === true; });
    }

    /* ---------- CSRF rotation ---------- */
    function metaCsrfName() {
        var m = document.querySelector('meta[name="csrf-name"]');
        return m ? m.getAttribute('content') : null;
    }
    function rotateCsrf(res, headerHash) {
        var hash = (res && res.csrf) || headerHash || null;
        if (!hash) { return; }
        var name = (res && res.csrf_name) || metaCsrfName();
        if (name) {
            document.querySelectorAll('input[name="' + name + '"]').forEach(function (i) { i.value = hash; });
        }
        var meta = document.querySelector('meta[name="csrf-hash"]');
        if (meta) { meta.setAttribute('content', hash); }
    }

    // Does a redirect target point at the page we're already on? Used to tell a
    // same-page validation redirect (stay + show error) apart from a real
    // cross-page redirect on failure (e.g. session expired -> /login).
    function samePath(url) {
        try {
            var a = document.createElement('a');
            a.href = url;
            return a.pathname === window.location.pathname;
        } catch (e) {
            return true;
        }
    }

    /* ---------- flash on first paint ---------- */
    function showRelayedFlash() {
        try {
            var raw = window.sessionStorage ? sessionStorage.getItem('app_flash') : null;
            if (raw) {
                sessionStorage.removeItem('app_flash');
                var f = JSON.parse(raw);
                notify(f.type, f.message);
            }
        } catch (e) { /* ignore */ }
    }
    function upgradeServerAlerts() {
        // Only success/danger are flash colours in this app; warning/info/secondary
        // alerts are persistent page UI and are left alone. Skip any alert that
        // carries interactive content or is explicitly marked [data-keep].
        var nodes = document.querySelectorAll('.alert-success:not([data-keep]), .alert-danger:not([data-keep])');
        nodes.forEach(function (el) {
            if (el.querySelector('a,button,form,input,select,textarea')) { return; }
            var msg = (el.textContent || '').trim();
            if (!msg) { return; }
            var type = el.classList.contains('alert-danger') ? 'error' : 'success';
            if (el.parentNode) { el.parentNode.removeChild(el); }
            notify(type, msg);
        });
    }

    /* ---------- form interception ---------- */

    // A form whose action leaves this page's ORIGIN cannot be replayed through fetch()
    // here, and must be left to the browser.
    //
    // Two independent reasons, either one fatal: the X-Requested-With header below is not
    // CORS-safelisted, so the request needs a preflight that nothing on this platform
    // answers; and credentials:'same-origin' would drop the .<domain> session cookie
    // even if it were answered. The native submit the interceptor would have cancelled
    // works fine — panel subdomains are the same SITE, which is what SameSite=Lax governs.
    //
    // Note "same site" is NOT "same origin": manufacturer.<domain> -> admin.<domain>
    // is same-site (so the cookie rides along on a real form POST) but cross-origin (so
    // fetch refuses it). Without this guard the failure is SILENT on the server — nothing
    // reaches PHP, so there is no 404, no log line and no audit row to find. That is
    // exactly how the impersonation banner's "Return to Admin" button sat broken.
    function isCrossOrigin(form) {
        var action = form.getAttribute('action');
        if (!action) { return false; }                                     // posts to self
        try {
            return new URL(action, window.location.href).origin !== window.location.origin;
        } catch (err) {
            return false;                                                  // unparseable: let the browser decide
        }
    }

    function isExcluded(form) {
        if (form.hasAttribute('data-no-ajax')) { return true; }
        if (form.getAttribute('target')) { return true; }                  // _blank print etc.
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return true; }
        if (form.closest('#pos, #cnApp')) { return true; }                 // POS / credit-note custom JS
        if (isCrossOrigin(form)) { return true; }                          // see above — must submit natively
        return false;
    }

    document.addEventListener('submit', function (e) {
        // Respect anything that already handled/cancelled the submit — crucially
        // this preserves the existing inline `onsubmit="return confirm(...)"`
        // guards: if the user cancels, defaultPrevented is true and we bail out.
        if (e.defaultPrevented) { return; }

        var form = e.target;
        if (!(form instanceof HTMLFormElement) || isExcluded(form)) { return; }
        if (form.classList.contains('ajax-busy')) { e.preventDefault(); return; }

        // Let the browser surface native HTML5 validation rather than posting.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }

        e.preventDefault();

        var submitter = e.submitter || form.querySelector('[type="submit"]');
        var confirmMsg = (submitter && submitter.getAttribute('data-confirm'))
            || form.getAttribute('data-confirm');

        var go = function () { submitForm(form, submitter); };
        if (confirmMsg) {
            confirmDialog(confirmMsg).then(function (ok) { if (ok) { go(); } });
        } else {
            go();
        }
    }, false);

    function submitForm(form, submitter) {
        form.classList.add('ajax-busy');
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        buttons.forEach(function (b) { b.disabled = true; });

        var fd = new FormData(form);
        // FormData(form) omits the clicked submit button's name/value — add it so
        // handlers that branch on the button (e.g. name="action") still work.
        if (submitter && submitter.name) { fd.append(submitter.name, submitter.value || ''); }

        block();

        var action = form.getAttribute('action') || window.location.href;
        var method = (form.getAttribute('method') || 'post');

        fetch(action, {
            method: method,
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (r) {
            var headerHash = r.headers.get('X-CSRF-TOKEN');
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return r.json().then(function (json) { return { json: json, hash: headerHash }; });
            }
            return r.text().then(function (html) { return { html: html, hash: headerHash }; });
        }).then(function (out) {
            if (out.json) {
                var res = out.json;
                rotateCsrf(res, out.hash);

                if (res.ok) {
                    // "Stay" actions (wishlist / add-to-cart / quick toggles) opt out of
                    // the full-page navigation: the controller still does its usual
                    // redirect()->back(), but instead of reloading we just toast and let
                    // the page update itself (see the `app:success` event below).
                    var stay = form.hasAttribute('data-ajax-stay')
                        || (submitter && submitter.hasAttribute('data-ajax-stay'));

                    // Partial refresh: re-render just a region (e.g. a datatable) in place
                    // instead of a full page reload. data-ajax-refresh="#selector".
                    var wantRefresh = form.hasAttribute('data-ajax-refresh')
                        || (submitter && submitter.hasAttribute('data-ajax-refresh'));
                    if (wantRefresh) {
                        var sel = (form.getAttribute('data-ajax-refresh')
                            || (submitter && submitter.getAttribute('data-ajax-refresh')) || '').trim();
                        finish(form, buttons);
                        notify(res.type || 'success', res.message);
                        softRefresh(sel);
                        form.dispatchEvent(new CustomEvent('app:success', {
                            bubbles: true, detail: { response: res, form: form, submitter: submitter }
                        }));
                        return;
                    }

                    if (res.redirect && !stay) {
                        // Relay the success message to the destination page, keep the
                        // overlay up through the navigation for a smooth transition.
                        if (res.message) {
                            try {
                                sessionStorage.setItem('app_flash',
                                    JSON.stringify({ type: res.type || 'success', message: res.message }));
                            } catch (e) { /* ignore */ }
                        }
                        window.location.assign(res.redirect);
                        return;                       // stay blocked until unload
                    }
                    finish(form, buttons);
                    notify(res.type || 'success', res.message);
                    // Let the page react to a stay-action without a reload (fill the
                    // wishlist heart, bump the cart badge, remove a row, …).
                    form.dispatchEvent(new CustomEvent('app:success', {
                        bubbles: true,
                        detail: { response: res, form: form, submitter: submitter }
                    }));
                } else {
                    finish(form, buttons);
                    notify('error', res.message || 'Something went wrong. Please try again.');
                    // The server sent us to a DIFFERENT page on failure (e.g. the
                    // session expired and webAuth redirected to /login). Honour it
                    // after the message; same-page validation errors stay put so the
                    // user's input is preserved.
                    if (res.redirect && !samePath(res.redirect)) {
                        window.setTimeout(function () { window.location.assign(res.redirect); }, 1500);
                    }
                }
            } else {
                // Non-JSON response: the action already executed server-side, so we
                // must NOT re-submit. Render the returned HTML in place instead.
                rotateCsrf(null, out.hash);
                if (out.html && out.html.length) {
                    document.open();
                    document.write(out.html);
                    document.close();
                } else {
                    finish(form, buttons);
                    notify('error', 'Unexpected server response.');
                }
            }
        }).catch(function () {
            finish(form, buttons);
            notify('error', 'Network error. Please check your connection and try again.');
        });
    }

    function finish(form, buttons) {
        unblock();
        form.classList.remove('ajax-busy');
        buttons.forEach(function (b) { b.disabled = false; });
    }

    /**
     * Re-fetch the current page and swap ONE region in place — "refresh the data,
     * not the whole page". sel is a CSS selector (falls back to [data-ajax-region]).
     * If the region can't be resolved, falls back to a normal reload so the user
     * still sees fresh state.
     */
    function softRefresh(sel) {
        var find = function (root) { return sel ? root.querySelector(sel) : root.querySelector('[data-ajax-region]'); };
        var target = find(document);
        if (!target) { window.location.reload(); return; }
        block();
        fetch(window.location.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (html) {
            var doc   = new DOMParser().parseFromString(html, 'text/html');
            var fresh = find(doc);
            unblock();
            if (fresh && target.parentNode) {
                target.replaceWith(fresh);
            } else {
                window.location.reload();
            }
        }).catch(function () { unblock(); /* keep current view; toast already shown */ });
    }

    /* ---------- public API + init ---------- */
    window.App = window.App || {};
    window.App.block = block;
    window.App.unblock = unblock;
    window.App.toast = function (type, msg) { notify(type, msg); };
    window.App.confirm = confirmDialog;

    document.addEventListener('DOMContentLoaded', function () {
        showRelayedFlash();
        upgradeServerAlerts();
    });
})();
