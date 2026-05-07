window.initCustomerMap = function (orderId) {
    const img = document.getElementById('customer-map-' + orderId);
    const loader = document.getElementById('customer-map-loader-' + orderId);

    if (!img) return;

    const token = img.dataset.token;
    const address = img.dataset.address;
    if (!token || !address) return;

    if (img.getAttribute('src') && img.complete) {
        if (loader) loader.style.display = 'none';
        return;
    }

    if (loader) {
        loader.textContent = 'Carregando mapa...';
        loader.style.display = '';
    }

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
            const pin = 'pin-s+f59e0b(' + lng + ',' + lat + ')';
            const src =
                'https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/' +
                pin + '/' + lng + ',' + lat + ',15/800x208@2x' +
                '?access_token=' + token;

            const el = document.getElementById('customer-map-' + orderId);
            if (!el) return;
            el.onload = function () {
                if (loader) loader.style.display = 'none';
            };
            el.onerror = function () {
                if (loader) loader.textContent = 'Erro ao carregar o mapa.';
            };
            el.src = src;
        })
        .catch(function () {
            if (loader) loader.textContent = 'Erro ao buscar endereço.';
        });
};
