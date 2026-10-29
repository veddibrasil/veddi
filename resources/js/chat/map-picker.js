var _mapStates = {};

function _mapEl(prefix, id) {
    return document.getElementById('map-' + prefix + '-' + id);
}

function _mapShow(el, visible) {
    if (el) el.style.display = visible ? '' : 'none';
}

function _mapSetLoading(prefix, v) {
    var s = _mapStates[prefix];
    if (!s) return;
    s.loading = v;
    var btn = _mapEl(prefix, 'loc-btn');
    if (btn) btn.disabled = v;
    _mapShow(_mapEl(prefix, 'span-default'), !v);
    _mapShow(_mapEl(prefix, 'span-loading'), v);
}

function _mapSetError(prefix, msg) {
    var el = _mapEl(prefix, 'error');
    if (!el) return;
    if (msg) { el.textContent = '⚠ ' + msg; _mapShow(el, true); }
    else _mapShow(el, false);
}

function _mapSetSearchError(prefix, msg) {
    var el = _mapEl(prefix, 'search-error');
    if (!el) return;
    if (msg) { el.textContent = '⚠ ' + msg; _mapShow(el, true); }
    else _mapShow(el, false);
}

function _mapSetGeocoding(prefix, v) {
    var s = _mapStates[prefix];
    if (!s) return;
    s.reverseGeocoding = v;
    var btn = _mapEl(prefix, 'confirm-btn');
    if (btn) btn.disabled = v;
    _mapShow(_mapEl(prefix, 'span-confirm'), !v);
    _mapShow(_mapEl(prefix, 'span-geocoding'), v);
}

function _mapDestroy(prefix) {
    var s = _mapStates[prefix];
    if (s && s.map) { s.map.remove(); s.map = null; s.marker = null; }
}

function _mapBuild(prefix, lat, lng) {
    _mapDestroy(prefix);
    var container = _mapEl(prefix, 'el');
    if (!container) return;
    var s = _mapStates[prefix];
    s.map = L.map(container, { zoomControl: true }).setView([lat, lng], 17);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(s.map);
    s.marker = L.marker([lat, lng], { draggable: true }).addTo(s.map);
    s.marker.on('dragend', function () {
        var pos = s.marker.getLatLng();
        s.lat = pos.lat;
        s.lng = pos.lng;
    });
    // Double rAF ensures browser has painted the container before Leaflet recalculates
    var mapRef = s.map;
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            if (mapRef) mapRef.invalidateSize();
        });
    });
}

function _mapOpenAt(prefix, lat, lng) {
    if (!_mapStates[prefix]) _mapStates[prefix] = { map: null, marker: null, lat: null, lng: null, loading: false, reverseGeocoding: false, searching: false };
    var s = _mapStates[prefix];
    s.lat = lat;
    s.lng = lng;
    s.autoContinue = false;
    var container = _mapEl(prefix, 'container');
    _mapShow(container, true);
    window.dispatchEvent(new CustomEvent('map-picker-toggle', { detail: { prefix: prefix, open: true } }));
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            _mapBuild(prefix, lat, lng);
            // The map opens below the address fields; bring it into view on small screens.
            if (container) container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
}

// Opened by the server after geocoding the typed address: confirming the pin finishes the
// address step, so the customer does not have to press "Confirmar endereço" a second time.
window.mapPickerOpenAt = function (prefix, lat, lng) {
    _mapOpenAt(prefix, lat, lng);
    _mapStates[prefix].autoContinue = true;
};

window.mapPickerSearch = async function (prefix) {
    if (!_mapStates[prefix]) _mapStates[prefix] = { map: null, marker: null, lat: null, lng: null, loading: false, reverseGeocoding: false, searching: false };
    var s = _mapStates[prefix];
    var input = _mapEl(prefix, 'search-input');
    var query = input ? input.value.trim() : '';
    if (!query) {
        _mapSetSearchError(prefix, 'Digite um endereço para buscar.');
        return;
    }
    if (s.searching) return;

    s.searching = true;
    _mapSetSearchError(prefix, null);
    var btn = _mapEl(prefix, 'search-btn');
    if (btn) btn.disabled = true;
    _mapShow(_mapEl(prefix, 'search-span-default'), false);
    _mapShow(_mapEl(prefix, 'search-span-loading'), true);

    try {
        var res = await fetch(
            'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(query) + '&format=json&limit=1&accept-language=pt-BR',
            { headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' } }
        );
        var results = await res.json();
        if (!results || results.length === 0) {
            _mapSetSearchError(prefix, 'Endereço não encontrado. Tente ser mais específico.');
        } else {
            var r = results[0];
            _mapOpenAt(prefix, parseFloat(r.lat), parseFloat(r.lon));
            _mapSetSearchError(prefix, null);
        }
    } catch (e) {
        _mapSetSearchError(prefix, 'Erro ao buscar endereço. Verifique sua conexão.');
    }

    s.searching = false;
    if (btn) btn.disabled = false;
    _mapShow(_mapEl(prefix, 'search-span-default'), true);
    _mapShow(_mapEl(prefix, 'search-span-loading'), false);
};

window.mapPickerUseLocation = function (prefix) {
    if (!_mapStates[prefix]) _mapStates[prefix] = { map: null, marker: null, lat: null, lng: null, loading: false, reverseGeocoding: false, searching: false };
    var s = _mapStates[prefix];
    if (!navigator.geolocation) {
        _mapSetError(prefix, 'Geolocalização não suportada pelo seu navegador.');
        return;
    }
    _mapSetLoading(prefix, true);
    _mapSetError(prefix, null);
    navigator.geolocation.getCurrentPosition(
        function (pos) {
            s.lat = pos.coords.latitude;
            s.lng = pos.coords.longitude;
            _mapSetLoading(prefix, false);
            _mapShow(_mapEl(prefix, 'container'), true);
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    _mapBuild(prefix, s.lat, s.lng);
                });
            });
        },
        function () {
            _mapSetLoading(prefix, false);
            _mapSetError(prefix, 'Não foi possível obter sua localização. Verifique as permissões do navegador.');
        },
        { enableHighAccuracy: true, timeout: 12000 }
    );
};

window.mapPickerCloseMap = function (prefix) {
    _mapShow(_mapEl(prefix, 'container'), false);
    _mapDestroy(prefix);
    window.dispatchEvent(new CustomEvent('map-picker-toggle', { detail: { prefix: prefix, open: false } }));
};

window.mapPickerConfirmLocation = async function (prefix, wire) {
    if (!_mapStates[prefix]) return;
    var s = _mapStates[prefix];
    if (!s.lat || !s.lng) return;
    if (s.marker) {
        var pos = s.marker.getLatLng();
        s.lat = pos.lat;
        s.lng = pos.lng;
    }
    _mapSetGeocoding(prefix, true);
    _mapSetError(prefix, null);
    try {
        wire.set('customer_latitude', s.lat);
        wire.set('customer_longitude', s.lng);

        var res = await fetch(
            'https://nominatim.openstreetmap.org/reverse?lat=' + s.lat + '&lon=' + s.lng + '&format=json&accept-language=pt-BR',
            { headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' } }
        );
        var data = await res.json();
        var a = data.address || {};

        // Only fill what the customer left blank: reverse geocoding is approximate and used to
        // replace a typed house number, CEP or neighbourhood with the nearest building's.
        var fill = function (prop, value) {
            if (value && !String(wire.get(prop) || '').trim()) wire.set(prop, value);
        };

        fill('address', a.road || a.pedestrian || a.footway || '');
        fill('number', a.house_number || '');
        fill('neighborhood', a.suburb || a.neighbourhood || a.city_district || a.quarter || '');
        fill('city', a.city || a.town || a.village || a.municipality || '');

        if (a.postcode) {
            var digits = a.postcode.replace(/\D/g, '').slice(0, 8);
            fill('cep', digits.length === 8 ? digits.slice(0,5) + '-' + digits.slice(5) : digits);
        }
    } catch (e) {
        _mapSetError(prefix, 'Erro ao buscar o endereço. Arraste o pino e tente novamente.');
        _mapSetGeocoding(prefix, false);
        return;
    }
    _mapSetGeocoding(prefix, false);
    var autoContinue = s.autoContinue;
    window.mapPickerCloseMap(prefix);
    if (autoContinue) {
        s.autoContinue = false;
        wire.call('confirmDeliveryAddress');
    }
};
