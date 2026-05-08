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
    _mapShow(_mapEl(prefix, 'container'), true);
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            _mapBuild(prefix, lat, lng);
        });
    });
}

window.mapPickerOpenAt = function (prefix, lat, lng) {
    _mapOpenAt(prefix, lat, lng);
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

        var street = a.road || a.pedestrian || a.footway || '';
        var num    = a.house_number || '';
        if (street) wire.set('address', street);
        if (num) wire.set('number', num);

        var bairro = a.suburb || a.neighbourhood || a.city_district || a.quarter || '';
        if (bairro) wire.set('neighborhood', bairro);

        var cidade = a.city || a.town || a.village || a.municipality || '';
        if (cidade) wire.set('city', cidade);

        if (a.postcode) {
            var digits = a.postcode.replace(/\D/g, '').slice(0, 8);
            wire.set('cep', digits.length === 8 ? digits.slice(0,5) + '-' + digits.slice(5) : digits);
        }
    } catch (e) {
        _mapSetError(prefix, 'Erro ao buscar o endereço. Arraste o pin e tente novamente.');
        _mapSetGeocoding(prefix, false);
        return;
    }
    _mapSetGeocoding(prefix, false);
    window.mapPickerCloseMap(prefix);
};
