var CACHE_NAME = 'civic-static-v1';

self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (key) {
                return key !== CACHE_NAME;
            }).map(function (key) {
                return caches.delete(key);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    var requestUrl = new URL(event.request.url);

    if (
        event.request.method !== 'GET'
        || requestUrl.origin !== self.location.origin
        || requestUrl.pathname.indexOf('/assets/') !== 0
    ) {
        return;
    }

    event.respondWith(
        caches.open(CACHE_NAME).then(function (cache) {
            return fetch(event.request).then(function (response) {
                if (response.ok) {
                    cache.put(event.request, response.clone());
                }

                return response;
            }).catch(function () {
                return cache.match(event.request);
            });
        })
    );
});
