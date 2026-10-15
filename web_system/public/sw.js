const CACHE_NAME = 'bontoc-rescue-pwa-v6';
const OFFLINE_URL = '/offline.html';
const APP_SHELL_URLS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/icons/icon-64.png',
    '/icons/icon-180.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];
const NEVER_CACHE_PATHS = new Set([
    '/system/version',
    '/sw.js',
    '/auth-password.js',
    '/pwa-helper.js',
    '/manifest.webmanifest',
]);

const isBuildAsset = (url) =>
    url.pathname.startsWith('/build/')
    || url.pathname.startsWith('/icons/');

const networkFresh = (request) => fetch(request, { cache: 'no-store' });

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(APP_SHELL_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        ).then(async () => {
            await self.clients.claim();
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            clients.forEach((client) => {
                client.postMessage({ type: 'APP_UPDATED_ACTIVE' });
            });
        })
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (request.mode === 'navigate') {
        event.respondWith(
            networkFresh(request)
                .catch(async () => {
                    return caches.match(OFFLINE_URL);
                })
        );
        return;
    }

    if (url.origin !== self.location.origin) {
        return;
    }

    if (NEVER_CACHE_PATHS.has(url.pathname)) {
        event.respondWith(networkFresh(request));
        return;
    }

    if (!isBuildAsset(url)) {
        event.respondWith(
            networkFresh(request)
                .catch(async () => caches.match(request))
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((cachedResponse) => {
            const networkFetch = fetch(request)
                .then((response) => {
                    if (response.ok) {
                        const responseClone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
                    }
                    return response;
                })
                .catch(() => cachedResponse);

            return cachedResponse || networkFetch;
        })
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
