(function () {
    'use strict';

    var mapNode = document.getElementById('identity-map');

    if (!mapNode) {
        return;
    }

    var statusNode = document.getElementById('map-status');
    var rows = [];
    var strings = {};

    try {
        rows = JSON.parse(document.getElementById('map-data').textContent);
        strings = JSON.parse(
            document.getElementById('map-strings').textContent
        );
    } catch (error) {
        mapNode.hidden = true;

        return;
    }

    if (!window.L) {
        statusNode.textContent = strings.unavailable;
        mapNode.hidden = true;

        return;
    }

    var map = window.L.map(mapNode, {
        scrollWheelZoom: false,
        preferCanvas: true
    }).setView([18.95, -72.68], 7);

    window.L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap contributors'
        }
    ).addTo(map);

    var bounds = [];

    rows.forEach(function (row) {
        if (row.total < 1) {
            return;
        }

        var point = [row.lat, row.lng];
        var popup = document.createElement('div');
        var title = document.createElement('strong');
        var details = document.createElement('p');

        title.textContent = row.name;
        details.textContent = strings.files + ': ' + row.total
            + ' | ' + strings.pending + ': ' + row.pending
            + ' | ' + strings.verified + ': ' + row.verified
            + ' | ' + strings.rejected + ': ' + row.rejected;
        popup.appendChild(title);
        popup.appendChild(details);

        window.L.circleMarker(point, {
            radius: Math.min(22, 7 + Math.sqrt(row.total) * 2),
            color: '#15398C',
            weight: 2,
            fillColor: '#7BA1EE',
            fillOpacity: 0.72
        }).addTo(map).bindPopup(popup);

        bounds.push(point);
    });

    if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [28, 28], maxZoom: 8 });
    }

    map.whenReady(function () {
        window.requestAnimationFrame(function () {
            map.invalidateSize({ pan: false });
        });
    });

    if ('ResizeObserver' in window) {
        new ResizeObserver(function () {
            map.invalidateSize({ pan: false });
        }).observe(mapNode);
    }

    statusNode.hidden = true;
}());
