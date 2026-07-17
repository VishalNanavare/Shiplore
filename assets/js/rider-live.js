/* rider-live.js — live rider dashboard: polls for new order OFFERS, renders them
 * with an accept countdown, lets the rider Accept/Skip, and keeps today's stats +
 * active count fresh. Offers that lapse are dropped (the server re-offers them). */
(function () {
    'use strict';
    var offersEl = document.getElementById('rdOffers');
    if (!offersEl) { return; }
    var POLL = offersEl.getAttribute('data-poll');
    var BASE = offersEl.getAttribute('data-base');
    var wrap = document.getElementById('rdOffersWrap');
    var seconds = {}; // delivery_id -> remaining seconds (client-side countdown)
    var known = {};   // delivery_id -> true (to detect brand-new offers for the beep)

    function meta(n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; }
    function setCsrf(h) {
        if (!h) { return; }
        var name = meta('csrf-name');
        if (name) { document.querySelectorAll('input[name="' + name + '"]').forEach(function (i) { i.value = h; }); }
        var m = document.querySelector('meta[name="csrf-hash"]'); if (m) { m.setAttribute('content', h); }
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function setText(id, v) { var e = document.getElementById(id); if (e) { e.textContent = v; } }
    function beep() {
        try {
            if (navigator.vibrate) { navigator.vibrate([120, 60, 120]); }
            var Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) { return; }
            var c = new Ctx(), o = c.createOscillator(), g = c.createGain();
            o.connect(g); g.connect(c.destination); o.type = 'sine'; o.frequency.value = 880; g.gain.value = 0.08;
            o.start(); setTimeout(function () { o.stop(); c.close(); }, 260);
        } catch (e) {}
    }

    function render(offers) {
        var ids = offers.map(function (o) { return String(o.delivery_id); });
        Array.prototype.slice.call(offersEl.children).forEach(function (c) {
            if (ids.indexOf(c.getAttribute('data-id')) < 0) { c.remove(); }
        });
        offers.forEach(function (o) {
            seconds[o.delivery_id] = o.seconds;
            var card = offersEl.querySelector('[data-id="' + o.delivery_id + '"]');
            if (card) { return; }
            card = document.createElement('div');
            card.className = 'rd-offer';
            card.setAttribute('data-id', o.delivery_id);
            card.innerHTML =
                '<div class="rd-offer-top"><span class="rd-offer-badge">NEW ORDER</span><span class="rd-cd" data-cd>' + esc(o.seconds) + 's</span></div>'
                + '<div class="fw-semibold mt-1">' + esc(o.order_no) + '</div>'
                + '<div class="small text-secondary text-truncate"><i class="bi bi-shop me-1"></i>' + esc(o.pickup) + '</div>'
                + '<div class="small text-secondary text-truncate"><i class="bi bi-geo-alt me-1"></i>' + esc(o.drop) + '</div>'
                + '<div class="d-flex gap-2 my-2 flex-wrap"><span class="rd-chip">₹' + esc(o.fee) + ' fee</span>' + (o.cod ? '<span class="rd-chip rd-chip-cod">Collect ₹' + esc(o.cod) + '</span>' : '') + '</div>'
                + '<div class="d-flex gap-2"><button type="button" class="btn btn-rd flex-grow-1" data-acc>Accept</button><button type="button" class="btn btn-outline-secondary" data-dec>Skip</button></div>';
            offersEl.appendChild(card);
            card.querySelector('[data-acc]').addEventListener('click', function () { act(o.delivery_id, 'accept', this); });
            card.querySelector('[data-dec]').addEventListener('click', function () { act(o.delivery_id, 'decline', this); });
            if (!known[o.delivery_id]) { beep(); }
        });
        offers.forEach(function (o) { known[o.delivery_id] = true; });
        paint();
        if (wrap) { wrap.style.display = offers.length ? '' : 'none'; }
        var s = document.getElementById('rdOffersS'); if (s) { s.textContent = offers.length > 1 ? 's (' + offers.length + ')' : ''; }
    }
    function paint() {
        Array.prototype.slice.call(offersEl.children).forEach(function (c) {
            var id = c.getAttribute('data-id'), s = seconds[id] || 0;
            var el = c.querySelector('[data-cd]'); if (el) { el.textContent = s + 's'; if (s <= 10) { el.classList.add('rd-cd-urgent'); } }
            if (s <= 0) { c.remove(); }
        });
        if (wrap && offersEl.children.length === 0) { wrap.style.display = 'none'; }
    }
    function tick() { Object.keys(seconds).forEach(function (k) { seconds[k] = Math.max(0, (seconds[k] || 0) - 1); }); paint(); }

    function stats(j) {
        if (j.today) {
            setText('rdStatEarn', '₹' + j.today.earnings);
            setText('rdStatDel', j.today.delivered);
            setText('rdStatCash', '₹' + j.today.cash);
            setText('rdStatHours', j.today.hours + 'h');
        }
        var ab = document.getElementById('rdActiveBadge');
        if (ab) { ab.textContent = j.active_count; ab.style.display = j.active_count > 0 ? '' : 'none'; }
        var dot = document.getElementById('rdOnlineDot'), txt = document.getElementById('rdOnlineText');
        if (dot) { dot.className = 'rd-dot me-1 ' + (j.online ? 'on' : 'off'); }
        if (txt) { txt.textContent = j.online ? 'You are online' : 'You are offline'; }
        var noTrips = document.getElementById('rdNoTrips');
        if (noTrips) { noTrips.style.display = (j.active_count > 0 || (j.offers && j.offers.length)) ? 'none' : ''; }
    }
    function poll() {
        fetch(POLL, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (!j || !j.ok) { return; } setCsrf(j.csrf); stats(j); render(j.offers || []); })
            .catch(function () {});
    }
    function act(id, kind, btn) {
        if (btn) { btn.disabled = true; }
        var body = new URLSearchParams();
        body.append(meta('csrf-name'), meta('csrf-hash'));
        fetch(BASE + '/deliveries/' + id + '/' + kind, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (j && j.csrf) { setCsrf(j.csrf); }
                if (kind === 'accept' && j && (j.ok === undefined || j.ok)) {
                    window.location = (j && j.redirect) ? j.redirect : (BASE + '/deliveries/' + id);
                } else {
                    var c = offersEl.querySelector('[data-id="' + id + '"]'); if (c) { c.remove(); }
                    poll();
                }
            })
            .catch(function () { if (btn) { btn.disabled = false; } poll(); });
    }

    poll();
    setInterval(poll, 5000);
    setInterval(tick, 1000);
})();
