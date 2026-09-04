(function () {
    'use strict';

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/service-worker.js').catch(function () {
                return null;
            });
        });
    }
}());
