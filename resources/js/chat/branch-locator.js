function _haversineKm(lat1, lng1, lat2, lng2) {
    var R = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLng = (lng2 - lng1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
          + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
          * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

window.branchLocate = function () {
    var errEl = document.getElementById('branch-locate-error');

    if (!navigator.geolocation) {
        if (errEl) { errEl.textContent = '⚠ Geolocalização não suportada pelo navegador.'; errEl.classList.remove('hidden'); }
        return;
    }

    if (errEl) errEl.classList.add('hidden');

    navigator.geolocation.getCurrentPosition(
        function (pos) {
            var userLat = pos.coords.latitude;
            var userLng = pos.coords.longitude;

            var list = document.getElementById('branch-list');
            if (!list) return;

            var buttons = Array.from(list.querySelectorAll('button[data-branch-id]'));
            var withCoords = [];
            var withoutCoords = [];

            buttons.forEach(function (b) {
                var lat = parseFloat(b.dataset.branchLat);
                var lng = parseFloat(b.dataset.branchLng);
                if (lat && lng) {
                    var km = _haversineKm(userLat, userLng, lat, lng);
                    b._distKm = km;
                    withCoords.push(b);
                } else {
                    withoutCoords.push(b);
                }
            });

            // Sort: open first by distance, then closed by distance
            withCoords.sort(function (a, b) {
                var aOpen = a.dataset.branchOpen === '1';
                var bOpen = b.dataset.branchOpen === '1';
                if (aOpen !== bOpen) return aOpen ? -1 : 1;
                return a._distKm - b._distKm;
            });

            var sorted = withCoords.concat(withoutCoords);
            sorted.forEach(function (b) { list.appendChild(b); });

            var nearestSet = false;
            withCoords.forEach(function (b) {
                var distEl = b.querySelector('.branch-distance');
                if (distEl) {
                    var km = b._distKm;
                    distEl.textContent = '📍 ' + (km < 1 ? Math.round(km * 1000) + ' m de distância' : km.toFixed(1) + ' km de distância');
                    distEl.classList.remove('hidden');
                }
                if (!nearestSet && b.dataset.branchOpen === '1') {
                    var badge = b.querySelector('.branch-nearest-badge');
                    if (badge) badge.classList.remove('hidden');
                    nearestSet = true;
                }
            });
        },
        function () {
            if (errEl) { errEl.textContent = '⚠ Não foi possível obter sua localização. Verifique as permissões.'; errEl.classList.remove('hidden'); }
        },
        { enableHighAccuracy: true, timeout: 12000 }
    );
};
