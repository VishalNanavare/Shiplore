/* checkout-address.js — inline Google map + reverse-geocode for the Blinkit-style
 * address form (partials/_store_address_form). Works on the standalone add-address
 * page AND when the form is fetched into a modal (account + checkout). Exposes
 * window.initCheckoutAddress(scope) and a delegated [data-addr-modal] opener.
 * Reuses the GST-state mapping from store-location.js (window.__stGeo); degrades to
 * a manual area field + "Go to current location" when no Maps key is present. */
(function () {
    'use strict';

    function initCheckoutAddress(scope) {
        scope = scope || document;
        var mapEl = scope.querySelector ? scope.querySelector('#caMap') : null;
        if (!mapEl || mapEl.dataset.caInit === '1') { return; }
        mapEl.dataset.caInit = '1';

        var q = function (id) { return scope.querySelector('#' + id); };
        var els = {
            search: q('caSearch'), lat: q('caLat'), lng: q('caLng'), city: q('caCity'),
            state: q('caState'), pin: q('caPin'), formatted: q('caFormatted'),
            area: q('caArea'), areaName: q('caAreaName'), current: q('caCurrent')
        };
        var hasKey = mapEl.getAttribute('data-maps') === '1';
        var geo = window.__stGeo || { gstCode: function () { return ''; }, pick: function () { return null; } };
        var map, marker, geocoder;

        function setLatLng(lat, lng) { els.lat.value = (+lat).toFixed(7); els.lng.value = (+lng).toFixed(7); }

        function fill(components, formatted) {
            var city = geo.pick(components, 'locality') || geo.pick(components, 'administrative_area_level_2') || geo.pick(components, 'sublocality_level_1');
            var pin = geo.pick(components, 'postal_code');
            var stt = geo.pick(components, 'administrative_area_level_1');
            if (city) { els.city.value = city.long_name; }
            if (pin) { els.pin.value = pin.long_name; }
            if (stt) { var code = geo.gstCode(stt.long_name, stt.short_name); if (code) { els.state.value = code; } }
            if (formatted) { els.formatted.value = formatted; }
            var areaTxt = formatted || [city && city.long_name, stt && stt.long_name].filter(Boolean).join(', ');
            if (els.area) { els.area.value = areaTxt; }
            if (els.areaName) { els.areaName.textContent = (city && city.long_name) || areaTxt || 'Selected location'; }
        }
        function reverse(lat, lng) {
            if (!geocoder) { return; }
            geocoder.geocode({ location: { lat: +lat, lng: +lng } }, function (res, status) {
                if (status === 'OK' && res[0]) { fill(res[0].address_components, res[0].formatted_address); }
            });
        }
        function moveTo(lat, lng, zoom) {
            if (map && marker) { var p = { lat: +lat, lng: +lng }; map.setCenter(p); map.setZoom(zoom || 16); marker.setPosition(p); }
            setLatLng(lat, lng);
        }
        function buildMap() {
            var startLat = parseFloat(mapEl.getAttribute('data-lat')) || 19.0760;
            var startLng = parseFloat(mapEl.getAttribute('data-lng')) || 72.8777;
            var hasStart = !!parseFloat(mapEl.getAttribute('data-lat'));
            map = new google.maps.Map(mapEl, { center: { lat: startLat, lng: startLng }, zoom: hasStart ? 16 : 11, mapTypeControl: false, streetViewControl: false, fullscreenControl: false });
            marker = new google.maps.Marker({ position: { lat: startLat, lng: startLng }, map: map, draggable: true });
            geocoder = new google.maps.Geocoder();
            marker.addListener('dragend', function () { var p = marker.getPosition(); setLatLng(p.lat(), p.lng()); reverse(p.lat(), p.lng()); });
            map.addListener('click', function (e) { moveTo(e.latLng.lat(), e.latLng.lng(), map.getZoom()); reverse(e.latLng.lat(), e.latLng.lng()); });
            if (els.search && google.maps.places) {
                var ac = new google.maps.places.Autocomplete(els.search, { fields: ['address_components', 'geometry', 'formatted_address'], componentRestrictions: { country: 'in' } });
                ac.addListener('place_changed', function () {
                    var p = ac.getPlace();
                    if (p && p.geometry && p.geometry.location) { moveTo(p.geometry.location.lat(), p.geometry.location.lng(), 16); }
                    if (p) { fill(p.address_components, p.formatted_address); }
                });
            }
        }
        function loadMaps() {
            if (window.google && window.google.maps) { buildMap(); return; }
            window.__caCbs = window.__caCbs || [];
            window.__caCbs.push(buildMap);
            if (window.__caMapsLoading) { return; }
            window.__caMapsLoading = true;
            window.__caMapsReady = function () { (window.__caCbs || []).forEach(function (f) { try { f(); } catch (e) {} }); };
            var s = document.createElement('script');
            s.async = true;
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.__caMapsKey || window.__stMapsKey || '') + '&libraries=places&callback=__caMapsReady';
            document.head.appendChild(s);
        }
        function degradeNoKey() {
            mapEl.classList.add('d-none');
            if (els.search) { els.search.parentElement.classList.add('d-none'); }
            if (els.area) { els.area.removeAttribute('readonly'); els.area.classList.remove('bg-light'); els.area.placeholder = 'Area / locality'; }
        }
        function start() { if (hasKey) { loadMaps(); } else { degradeNoKey(); } }

        // Build now if visible (page or already-open modal); else defer to the
        // enclosing modal becoming visible.
        var modalParent = mapEl.closest ? mapEl.closest('.modal') : null;
        if (mapEl.offsetParent !== null || !modalParent || !window.bootstrap) {
            start();
        } else {
            modalParent.addEventListener('shown.bs.modal', function once() {
                modalParent.removeEventListener('shown.bs.modal', once);
                start();
            });
        }

        var saveAs = q('caSaveAs');
        if (saveAs) {
            saveAs.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.st-saveas-chip') : null; if (!b) { return; }
                saveAs.querySelectorAll('.st-saveas-chip').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                var lbl = q('caLabel'); if (lbl) { lbl.value = b.getAttribute('data-label'); }
            });
        }

        if (els.current) {
            els.current.addEventListener('click', function () {
                if (!('geolocation' in navigator) || window.isSecureContext === false) {
                    if (window.App && App.toast) { App.toast('error', 'Location needs a secure (https) connection — search for your area instead.'); }
                    return;
                }
                els.current.disabled = true;
                els.current.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Locating…';
                navigator.geolocation.getCurrentPosition(function (pos) {
                    moveTo(pos.coords.latitude, pos.coords.longitude, 16); reverse(pos.coords.latitude, pos.coords.longitude);
                    els.current.disabled = false; els.current.innerHTML = '<i class="bi bi-crosshair me-1"></i>Go to current location';
                }, function () {
                    els.current.disabled = false; els.current.innerHTML = '<i class="bi bi-crosshair me-1"></i>Go to current location';
                    if (window.App && App.toast) { App.toast('error', 'Could not get your location. Search for your area or drag the pin.'); }
                }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
            });
        }

        var form = q('caForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!els.lat.value || !els.lng.value) {
                    e.preventDefault();
                    var msg = 'Set your delivery location — tap “Go to current location”' + (hasKey ? ' or pick it on the map.' : '.');
                    if (window.App && App.toast) { App.toast('error', msg); } else { alert(msg); }
                }
            });
        }
    }
    window.initCheckoutAddress = initCheckoutAddress;

    // Standalone add-address page: bind the server-rendered form on load.
    function autorun() { if (document.getElementById('caMap')) { initCheckoutAddress(document); } }
    if (document.readyState !== 'loading') { autorun(); } else { document.addEventListener('DOMContentLoaded', autorun); }

    // Generic opener: [data-addr-modal]="fragmentUrl" [data-addr-target]="#modal"
    // fetches the address form into the modal and initializes the map.
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-addr-modal]') : null;
        if (!t) { return; }
        e.preventDefault();
        var modal = document.querySelector(t.getAttribute('data-addr-target') || '#addrModal');
        if (!modal || !window.bootstrap) { window.location = t.getAttribute('href') || t.getAttribute('data-addr-modal'); return; }
        var body = modal.querySelector('[data-addr-body]'); if (!body) { return; }
        body.innerHTML = '<div class="text-center text-secondary py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        bootstrap.Modal.getOrCreateInstance(modal).show();
        fetch(t.getAttribute('data-addr-modal'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
            .then(function (html) { body.innerHTML = html; window.initCheckoutAddress(body); })
            .catch(function () { body.innerHTML = '<div class="text-danger small p-3 text-center">Could not load the form. <a href="' + (t.getAttribute('href') || '#') + '">Open the full page</a>.</div>'; });
    });
})();
