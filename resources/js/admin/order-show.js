window._orderMaps = window._orderMaps || {};

window.initCustomerMap = function (orderId) {
    const container = document.getElementById('customer-map-' + orderId);
    const loader = document.getElementById('customer-map-loader-' + orderId);

    if (!container) return;

    const token = container.dataset.token;
    const address = container.dataset.address;
    if (!token || !address) return;

    const prev = window._orderMaps[orderId];
    if (prev && prev.address === address && prev.map) {
        if (loader) loader.style.display = 'none';
        return;
    }

    if (prev && prev.map) {
        prev.map.remove();
        delete window._orderMaps[orderId];
    }

    if (loader) {
        loader.textContent = 'Carregando mapa...';
        loader.style.display = '';
    }

    function doInit() {
        if (typeof mapboxgl === 'undefined') {
            setTimeout(doInit, 100);
            return;
        }

        mapboxgl.accessToken = token;

        fetch(
            'https://api.mapbox.com/geocoding/v5/mapbox.places/' +
            encodeURIComponent(address) +
            '.json?access_token=' + token + '&country=BR&limit=1'
        )
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const coords = data.features && data.features[0] && data.features[0].center;
                if (!coords) {
                    if (loader) loader.textContent = 'Endereço não encontrado.';
                    return;
                }
                const lng = coords[0];
                const lat = coords[1];

                const el = document.getElementById('customer-map-' + orderId);
                if (!el) return;

                const map = new mapboxgl.Map({
                    container: el,
                    style: 'mapbox://styles/mapbox/streets-v12',
                    center: [lng, lat],
                    zoom: 15,
                });

                map.addControl(new mapboxgl.NavigationControl(), 'top-right');

                new mapboxgl.Marker({ color: '#f59e0b' })
                    .setLngLat([lng, lat])
                    .addTo(map);

                map.on('load', function () {
                    if (loader) loader.style.display = 'none';
                });

                window._orderMaps[orderId] = { map: map, address: address };
            })
            .catch(function () {
                if (loader) loader.textContent = 'Erro ao buscar endereço.';
            });
    }

    doInit();
};
