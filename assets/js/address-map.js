/* address-map.js — shared Google Maps shop-location picker used by vendor
 * self-registration (/register) and admin vendor creation (admin/vendors/new).
 * A Places search box + draggable marker fill these fields by id:
 *   mapSearch, map, regAddress, regArea, regCity, regState (GST-code dropdown),
 *   regPincode, regLat, regLng.
 * State name -> GST code uses window.__stateCodes (set inline by the page).
 * Loaded with &callback=initRegMap by the Maps script tag. */
(function () {
    'use strict';

    function gstCode(name) {
        var codes = window.__stateCodes || {};
        var n = (name || '').toLowerCase().replace(/&/g, ' and ').replace(/[^a-z ]+/g, ' ').replace(/\s+/g, ' ').trim();
        return codes[n] || '';
    }

    window.initRegMap = function () {
        var mapEl = document.getElementById('map');
        if (!mapEl || typeof google === 'undefined') { return; }
        var initLat = parseFloat(window.__mapInitLat) || 19.0760;
        var initLng = parseFloat(window.__mapInitLng) || 72.8777;
        var initZoom = (window.__mapInitLat && window.__mapInitLng) ? 16 : 11;
        var center = { lat: initLat, lng: initLng };
        var map = new google.maps.Map(mapEl, { center: center, zoom: initZoom, mapTypeControl: false, streetViewControl: false });
        var marker = new google.maps.Marker({ position: center, map: map, draggable: true });
        var geocoder = new google.maps.Geocoder();

        function setVal(id, v) { var e = document.getElementById(id); if (e && v != null && v !== '') { e.value = v; } }
        function setLatLng(lat, lng) { setVal('regLat', lat.toFixed(7)); setVal('regLng', lng.toFixed(7)); }
        function fillFrom(components, formatted) {
            components = components || [];
            var get = function (t) { for (var i = 0; i < components.length; i++) { if (components[i].types.indexOf(t) >= 0) { return components[i]; } } return null; };
            var city = get('locality') || get('postal_town') || get('administrative_area_level_2');
            var area = get('sublocality_level_1') || get('sublocality') || get('neighborhood');
            var pin = get('postal_code');
            var state = get('administrative_area_level_1');
            if (city) { setVal('regCity', city.long_name); }
            if (area) { setVal('regArea', area.long_name); }
            if (pin) { setVal('regPincode', pin.long_name); }
            if (state) { var code = gstCode(state.long_name); if (code) { setVal('regState', code); } }
            var addr = document.getElementById('regAddress');
            if (addr && !addr.value && formatted) { addr.value = formatted; }
        }
        function place(loc) { map.setCenter(loc); map.setZoom(16); marker.setPosition(loc); setLatLng(loc.lat(), loc.lng()); }

        var search = document.getElementById('mapSearch');
        if (search && google.maps.places) {
            var ac = new google.maps.places.Autocomplete(search, { fields: ['address_components', 'geometry', 'formatted_address'], componentRestrictions: { country: 'in' } });
            ac.addListener('place_changed', function () {
                var p = ac.getPlace();
                if (p && p.geometry && p.geometry.location) { place(p.geometry.location); }
                if (p) { fillFrom(p.address_components, p.formatted_address); }
            });
        }
        marker.addListener('dragend', function () {
            var pos = marker.getPosition();
            setLatLng(pos.lat(), pos.lng());
            geocoder.geocode({ location: pos }, function (res, status) { if (status === 'OK' && res[0]) { fillFrom(res[0].address_components, res[0].formatted_address); } });
        });
    };
})();
