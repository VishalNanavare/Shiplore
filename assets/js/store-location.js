/* store-location.js — customer "deliver to" picker (Blinkit/Zomato style).
 * - Loads Google Maps (Places) on demand the first time the modal opens.
 * - "Use my current location" → browser geolocation → reverse geocode.
 * - Places search + draggable pin keep lat/lng/label/pincode in sync.
 * - Degrades gracefully when no Maps key: geolocation + manual label still work.
 * The modal markup lives in partials/_store_location_modal.php. */
(function () {
    'use strict';

    var modalEl = document.getElementById('stLocModal');
    if (!modalEl) { return; }

    var els = {
        map: document.getElementById('stLocMap'),
        search: document.getElementById('stLocSearch'),
        lat: document.getElementById('stLat'),
        lng: document.getElementById('stLng'),
        label: document.getElementById('stLabel'),
        pin: document.getElementById('stPin'),
        ret: document.getElementById('stReturn'),
        confirm: document.getElementById('stLocConfirm'),
        current: document.getElementById('stUseCurrent'),
        hint: document.getElementById('stLocHint'),
        city: document.getElementById('stCity'),
        state: document.getElementById('stState'),
        line1: document.getElementById('stLine1'),
        formatted: document.getElementById('stFormatted')
    };

    // Map Google state names → Indian GST state codes (used to auto-fill checkout).
    var STATE_GST = {
        'jammu and kashmir': '01', 'himachal pradesh': '02', 'punjab': '03', 'chandigarh': '04',
        'uttarakhand': '05', 'haryana': '06', 'delhi': '07', 'national capital territory of delhi': '07',
        'rajasthan': '08', 'uttar pradesh': '09', 'bihar': '10', 'sikkim': '11', 'arunachal pradesh': '12',
        'nagaland': '13', 'manipur': '14', 'mizoram': '15', 'tripura': '16', 'meghalaya': '17', 'assam': '18',
        'west bengal': '19', 'jharkhand': '20', 'odisha': '21', 'orissa': '21', 'chhattisgarh': '22',
        'madhya pradesh': '23', 'gujarat': '24', 'dadra and nagar haveli and daman and diu': '26',
        'daman and diu': '26', 'dadra and nagar haveli': '26', 'maharashtra': '27', 'karnataka': '29',
        'goa': '30', 'lakshadweep': '31', 'kerala': '32', 'tamil nadu': '33', 'puducherry': '34',
        'pondicherry': '34', 'andaman and nicobar islands': '35', 'telangana': '36', 'andhra pradesh': '37',
        'ladakh': '38'
    };
    var STATE_SHORT = {
        'mh': '27', 'dl': '07', 'ka': '29', 'tn': '33', 'ts': '36', 'ap': '37', 'gj': '24', 'up': '09',
        'wb': '19', 'rj': '08', 'mp': '23', 'br': '10', 'hr': '06', 'pb': '03', 'kl': '32', 'jk': '01',
        'hp': '02', 'uk': '05', 'ga': '30', 'as': '18', 'od': '21', 'cg': '22', 'jh': '20', 'ch': '04'
    };
    function gstCode(longName, shortName) {
        var l = (longName || '').toLowerCase().trim();
        if (STATE_GST[l]) { return STATE_GST[l]; }
        var s = (shortName || '').toLowerCase().replace(/[^a-z]/g, '');
        return STATE_SHORT[s] || '';
    }
    var hasMapsKey = modalEl.getAttribute('data-maps') === '1';
    var map, marker, geocoder, mapsLoaded = false, mapBuilt = false;

    function setLatLng(lat, lng) {
        els.lat.value = (+lat).toFixed(7);
        els.lng.value = (+lng).toFixed(7);
        // Always leave a usable label so "Deliver here" is never blocked; a real
        // area name from reverse-geocoding overwrites this a moment later.
        if (!els.label.value.trim()) { els.label.value = 'Selected location'; }
        els.confirm.removeAttribute('disabled');
    }

    function pickComponent(components, type) {
        components = components || [];
        for (var i = 0; i < components.length; i++) {
            if (components[i].types.indexOf(type) >= 0) { return components[i]; }
        }
        return null;
    }

    function fillAddress(components, formatted) {
        var area = pickComponent(components, 'sublocality_level_1') || pickComponent(components, 'sublocality') ||
                   pickComponent(components, 'neighborhood') || pickComponent(components, 'locality');
        var pin = pickComponent(components, 'postal_code');
        if (area && (!els.label.value || els.label.dataset.auto !== '0')) {
            els.label.value = area.long_name + (pickComponent(components, 'locality') && pickComponent(components, 'locality') !== area ? ', ' + pickComponent(components, 'locality').long_name : '');
            els.label.dataset.auto = '1';
        } else if (!els.label.value && formatted) {
            els.label.value = formatted.split(',').slice(0, 2).join(',');
        }
        if (pin) { els.pin.value = pin.long_name; }

        // Auto-fill the checkout delivery fields from the geocode result.
        var city = pickComponent(components, 'locality') || pickComponent(components, 'administrative_area_level_2') || pickComponent(components, 'sublocality_level_1');
        if (city && els.city) { els.city.value = city.long_name; }
        var stt = pickComponent(components, 'administrative_area_level_1');
        if (stt && els.state) { var code = gstCode(stt.long_name, stt.short_name); if (code) { els.state.value = code; } }
        var num = pickComponent(components, 'street_number');
        var route = pickComponent(components, 'route');
        var premise = pickComponent(components, 'premise') || pickComponent(components, 'sublocality_level_2');
        var l1 = [num ? num.long_name : '', route ? route.long_name : ''].filter(Boolean).join(' ') || (premise ? premise.long_name : '');
        if (l1 && els.line1) { els.line1.value = l1; }
        if (els.formatted && formatted) { els.formatted.value = formatted; }
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
            if (status === 'OK' && res[0]) { fillAddress(res[0].address_components, res[0].formatted_address); }
        });
    }

    function buildMap() {
        if (mapBuilt || !mapsLoaded || !els.map) { return; }
        mapBuilt = true;
        els.hint.classList.remove('d-none');
        var startLat = parseFloat(modalEl.getAttribute('data-lat')) || 19.0760;
        var startLng = parseFloat(modalEl.getAttribute('data-lng')) || 72.8777;
        map = new google.maps.Map(els.map, { center: { lat: startLat, lng: startLng }, zoom: parseFloat(modalEl.getAttribute('data-lat')) ? 16 : 11, mapTypeControl: false, streetViewControl: false, fullscreenControl: false });
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
                if (p && p.geometry && p.geometry.location) { moveTo(p.geometry.location.lat(), p.geometry.location.lng(), 16); }
                if (p) { fillAddress(p.address_components, p.formatted_address); }
            });
        }
    }

    // Google Maps async loader (loaded once, on first modal open).
    window.__stMapsCb = function () { mapsLoaded = true; buildMap(); };
    function loadMaps() {
        if (!hasMapsKey || mapsLoaded || window.__stMapsLoading) { return; }
        window.__stMapsLoading = true;
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.__stMapsKey || '') + '&libraries=places&callback=__stMapsCb';
        document.head.appendChild(s);
    }

    modalEl.addEventListener('shown.bs.modal', function () {
        els.ret.value = window.location.pathname + window.location.search;
        if (hasMapsKey) { loadMaps(); buildMap(); if (map) { google.maps.event.trigger(map, 'resize'); } }
        else { els.map.classList.add('d-none'); els.search.parentElement.classList.add('d-none'); }
    });

    // Current-location button — works with or without Maps (reverse-geocode if available).
    if (els.current) {
        var resetCurrent = function () {
            els.current.disabled = false;
            els.current.innerHTML = '<i class="bi bi-crosshair me-1"></i>Use my current location';
        };
        var notify = function (msg) { window.App && App.toast ? App.toast('error', msg) : alert(msg); };
        els.current.addEventListener('click', function () {
            // Geolocation needs a secure context (https) AND browser permission.
            if (!('geolocation' in navigator)) { notify('Your browser does not support location. Please search for your area instead.'); return; }
            if (window.isSecureContext === false) {
                notify('Location only works on a secure (https) connection. Please search for your area or drag the pin instead.');
                return;
            }
            els.current.disabled = true;
            els.current.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Locating…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                var la = pos.coords.latitude, ln = pos.coords.longitude;
                if (map) { moveTo(la, ln, 16); reverseGeocode(la, ln); }
                else { setLatLng(la, ln); if (!els.label.value) { els.label.value = 'My current location'; } }
                resetCurrent();
            }, function (err) {
                resetCurrent();
                var msg;
                switch (err && err.code) {
                    case 1: msg = 'Location access is blocked. Allow it for this site in your browser (and check macOS/Windows Location Services), or just search for your area below.'; break;
                    case 2: msg = 'Your position is unavailable right now. Please search for your area or drag the pin.'; break;
                    case 3: msg = 'Getting your location timed out. Try again, or search for your area.'; break;
                    default: msg = 'Could not get your location. Please search for your area or drag the pin instead.';
                }
                notify(msg);
                if (els.search) { try { els.search.focus(); } catch (e) {} }
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
        });
    }

    // Single modal instance with focus-trap DISABLED — Bootstrap's focus trap
    // otherwise steals clicks on the Google Places suggestion list (which renders
    // in <body>, outside the modal), so suggestions appear unclickable.
    function modalInstance() {
        return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl, { focus: false }) : null;
    }

    // First-visit nudge: auto-open once when no location is set yet.
    if (document.body.getAttribute('data-need-location') === '1' && !sessionStorage.getItem('stLocPrompted')) {
        sessionStorage.setItem('stLocPrompted', '1');
        var mi = modalInstance();
        if (mi) { mi.show(); }
    }

    // Expose an opener for header/buttons.
    window.openLocationPicker = function () {
        var m = modalInstance();
        if (m) { m.show(); }
    };

    // Expose geocode helpers so the dedicated checkout add-address page (inline map)
    // can reuse the same GST-state mapping + component picker.
    window.__stGeo = { gstCode: gstCode, pick: pickComponent };

    // Safety net: guard the final submit so a location is always chosen.
    var locForm = document.getElementById('stLocForm');
    if (locForm) {
        locForm.addEventListener('submit', function (e) {
            if (!els.lat.value || !els.lng.value) {
                e.preventDefault();
                e.stopImmediatePropagation();
                window.App && App.toast ? App.toast('error', 'Pick a place, drag the pin, or use your current location.') : alert('Choose a location first.');
                return;
            }
            // If the cart has items, changing the delivery location may drop items
            // that can't be delivered to the new spot. Warn first — handled by the
            // shared data-confirm flow in ajax-forms.js (read at submit time), using
            // the live cart-count badge so AJAX cart changes are reflected.
            var badge = document.getElementById('cartCountBadge');
            var cartCount = badge ? (parseInt((badge.textContent || '').trim(), 10) || 0) : 0;
            if (cartCount > 0) {
                locForm.setAttribute('data-confirm', 'Changing your delivery location may remove items that can’t be delivered there. Continue?');
            } else {
                locForm.removeAttribute('data-confirm');
            }
        }, true);
    }
})();
