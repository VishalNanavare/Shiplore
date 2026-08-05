/* monline-location.js — B2B proximity-sort location override.
 * Trimmed sibling of store-location.js: keeps the Maps loader, draggable pin,
 * Places autocomplete and "use my current location" — drops everything that exists
 * only to auto-fill a checkout delivery address (GST-state mapping, full address
 * component extraction) and the "may remove undeliverable items" cart warning,
 * since monline sorts by distance and never filters or touches the cart.
 * The modal markup lives in monline/_location_modal.php. */
(function () {
    'use strict';

    var modalEl = document.getElementById('moLocModal');
    if (!modalEl) { return; }

    var els = {
        map: document.getElementById('moLocMap'),
        search: document.getElementById('moLocSearch'),
        lat: document.getElementById('moLat'),
        lng: document.getElementById('moLng'),
        label: document.getElementById('moLabel'),
        ret: document.getElementById('moReturn'),
        confirm: document.getElementById('moLocConfirm'),
        current: document.getElementById('moUseCurrent'),
        hint: document.getElementById('moLocHint')
    };

    var hasMapsKey = modalEl.getAttribute('data-maps') === '1';
    var map, marker, geocoder, mapsLoaded = false, mapBuilt = false;

    function setLatLng(lat, lng) {
        els.lat.value = (+lat).toFixed(7);
        els.lng.value = (+lng).toFixed(7);
        if (!els.label.value.trim()) { els.label.value = 'Selected location'; }
        els.confirm.removeAttribute('disabled');
    }

    function fillLabel(components, formatted) {
        if (els.label.dataset.auto === '0') { return; }
        var area = null;
        for (var i = 0; i < (components || []).length; i++) {
            var types = components[i].types || [];
            if (types.indexOf('locality') >= 0 || types.indexOf('sublocality_level_1') >= 0 || types.indexOf('sublocality') >= 0) {
                area = components[i];
                break;
            }
        }
        if (area) { els.label.value = area.long_name; }
        else if (formatted) { els.label.value = formatted.split(',').slice(0, 2).join(','); }
    }

    function moveTo(lat, lng, zoom) {
        if (map && marker) {
            var p = { lat: +lat, lng: +lng };
            map.setCenter(p); map.setZoom(zoom || 16); marker.setPosition(p);
        }
        setLatLng(lat, lng);
    }

    function reverseGeocode(lat, lng) {
        if (!geocoder) { return; }
        geocoder.geocode({ location: { lat: +lat, lng: +lng } }, function (res, status) {
            if (status === 'OK' && res[0]) { fillLabel(res[0].address_components, res[0].formatted_address); }
        });
    }

    function buildMap() {
        if (mapBuilt || !mapsLoaded || !els.map) { return; }
        mapBuilt = true;
        els.hint.classList.remove('d-none');
        var startLat = parseFloat(modalEl.getAttribute('data-lat')) || 19.0760;
        var startLng = parseFloat(modalEl.getAttribute('data-lng')) || 72.8777;
        map = new google.maps.Map(els.map, { center: { lat: startLat, lng: startLng }, zoom: parseFloat(modalEl.getAttribute('data-lat')) ? 12 : 5, mapTypeControl: false, streetViewControl: false, fullscreenControl: false });
        marker = new google.maps.Marker({ position: { lat: startLat, lng: startLng }, map: map, draggable: true });
        geocoder = new google.maps.Geocoder();
        marker.addListener('dragend', function () {
            var pos = marker.getPosition();
            setLatLng(pos.lat(), pos.lng());
            reverseGeocode(pos.lat(), pos.lng());
        });
        if (els.search && google.maps.places) {
            var ac = new google.maps.places.Autocomplete(els.search, { fields: ['address_components', 'geometry', 'formatted_address'], componentRestrictions: { country: 'in' } });
            ac.addListener('place_changed', function () {
                var p = ac.getPlace();
                if (p && p.geometry && p.geometry.location) { moveTo(p.geometry.location.lat(), p.geometry.location.lng(), 12); }
                if (p) { fillLabel(p.address_components, p.formatted_address); }
            });
        }
    }

    window.__moMapsCb = function () { mapsLoaded = true; buildMap(); };
    function loadMaps() {
        if (!hasMapsKey || mapsLoaded || window.__moMapsLoading) { return; }
        window.__moMapsLoading = true;
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.__moMapsKey || '') + '&libraries=places&callback=__moMapsCb';
        document.head.appendChild(s);
    }

    modalEl.addEventListener('shown.bs.modal', function () {
        els.ret.value = window.location.pathname + window.location.search;
        if (hasMapsKey) { loadMaps(); buildMap(); if (map) { google.maps.event.trigger(map, 'resize'); } }
        else { els.map.classList.add('d-none'); els.search.parentElement.classList.add('d-none'); }
    });

    if (els.current) {
        var resetCurrent = function () {
            els.current.disabled = false;
            els.current.innerHTML = '<i class="bi bi-crosshair me-1"></i>Use my current location';
        };
        var notify = function (msg) { window.App && App.toast ? App.toast('error', msg) : alert(msg); };
        els.current.addEventListener('click', function () {
            if (!('geolocation' in navigator)) { notify('Your browser does not support location. Please search instead.'); return; }
            if (window.isSecureContext === false) {
                notify('Location only works on a secure (https) connection. Please search instead.');
                return;
            }
            els.current.disabled = true;
            els.current.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Locating…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                var la = pos.coords.latitude, ln = pos.coords.longitude;
                if (map) { moveTo(la, ln, 12); reverseGeocode(la, ln); }
                else { setLatLng(la, ln); if (!els.label.value) { els.label.value = 'My current location'; } }
                resetCurrent();
            }, function (err) {
                resetCurrent();
                var msg;
                switch (err && err.code) {
                    case 1: msg = 'Location access is blocked. Allow it for this site, or search instead.'; break;
                    case 2: msg = 'Your position is unavailable right now. Please search instead.'; break;
                    case 3: msg = 'Getting your location timed out. Try again, or search instead.'; break;
                    default: msg = 'Could not get your location. Please search instead.';
                }
                notify(msg);
                if (els.search) { try { els.search.focus(); } catch (e) {} }
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
        });
    }

    function modalInstance() {
        return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl, { focus: false }) : null;
    }

    // No first-visit auto-open (unlike the storefront's picker) — a buyer already
    // gets a correct default (their own shop), this is an enhancement, not a gate.
    window.openMonlineLocationPicker = function () {
        var m = modalInstance();
        if (m) { m.show(); }
    };

    var locForm = document.getElementById('moLocForm');
    if (locForm) {
        locForm.addEventListener('submit', function (e) {
            if (!els.lat.value || !els.lng.value) {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.App && App.toast ? App.toast('error', 'Pick a place, drag the pin, or use your current location.') : alert('Choose a location first.');
            }
        }, true);
    }
})();
