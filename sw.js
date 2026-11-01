/**
 * ESBAS POS - Progressive Web App Service Worker
 * Menyediakan kapabilitas offline-first caching, launch standalone, dan background sync.
 */

const CACHE_NAME = 'esbas-pos-v1.3.4';
const STATIC_ASSETS = [
    './',
    './index.php',
    './manifest.json',
    './assets/css/tailwind.min.css',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/icon-maskable.png'
];

// 1. Install Event: Cache Core App Shell
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        }).then(() => self.skipWaiting())
    );
});

// 2. Activate Event: Clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// 3. Fetch Event: Network-First with Offline Cache Fallback for Navigation & Static Assets
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    // Jangan intercept request non-GET
    if (request.method !== 'GET') {
        return;
    }

    // Untuk halaman utama / navigasi: Network-First, fallback ke Cache jika offline
    if (request.mode === 'navigate' || url.pathname.endsWith('index.php') || url.pathname === '/' || url.pathname.endsWith('/')) {
        event.respondWith(
            fetch(request)
                .then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    const cachedResponse = await caches.match(request);
                    if (cachedResponse) return cachedResponse;
                    const indexFallback = await caches.match('./index.php');
                    if (indexFallback) return indexFallback;
                    return caches.match('./');
                })
        );
        return;
    }

    // Untuk asset statis lokal (CSS, Icons, Fonts)
    if (url.origin === self.location.origin && (url.pathname.includes('/assets/') || url.pathname.includes('manifest.json'))) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                const fetchPromise = fetch(request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        const clone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return networkResponse;
                }).catch(() => cachedResponse);

                return cachedResponse || fetchPromise;
            })
        );
        return;
    }

    // Untuk CDN eksternal (Lucide Icons, fonts)
    if (url.hostname.includes('unpkg.com') || url.hostname.includes('cdnjs.cloudflare.com')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                return cached || fetch(request).then((netRes) => {
                    if (netRes && netRes.status === 200) {
                        const clone = netRes.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return netRes;
                }).catch(() => cached);
            })
        );
        return;
    }

    // Untuk API GET: Coba jaringan terlebih dahulu, jika gagal offline coba cache
    if (url.pathname.includes('api.php')) {
        event.respondWith(
            fetch(request).catch(async () => {
                const cached = await caches.match(request);
                if (cached) return cached;
                return new Response(JSON.stringify({ 
                    success: false, 
                    is_offline: true, 
                    message: 'Koneksi jaringan terputus. Menggunakan data cache lokal.' 
                }), {
                    headers: { 'Content-Type': 'application/json' }
                });
            })
        );
    }
});

// 4. Background Sync Event (Triggered saat koneksi internet pulih)
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-offline-sales') {
        event.waitUntil(
            self.clients.matchAll().then((clients) => {
                clients.forEach((client) => {
                    client.postMessage({ type: 'SYNC_OFFLINE_SALES_REQUESTED' });
                });
            })
        );
    }
});
