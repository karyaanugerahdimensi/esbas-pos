/**
 * KAD Tech - Progressive Web App Service Worker
 * Kompatibel dengan Android 8+ (Chromium 58+ & Modern Browser)
 * Menyediakan kapabilitas offline-first caching, launch standalone, dan background sync.
 */

const CACHE_NAME = 'kad-tech-v1.6.7';
const STATIC_ASSETS = [
    './',
    './index.php',
    './manifest.json',
    './assets/css/tailwind.min.css',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/icons/icon-maskable.png'
];

// 1. Install Event: Cache Core App Shell dengan Resilient Promise.all (Anti-Failure)
self.addEventListener('install', function(event) {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return Promise.all(
                STATIC_ASSETS.map(function(asset) {
                    return cache.add(asset).catch(function(err) {
                        console.warn('[SW] Cache add skipped for ' + asset + ':', err);
                    });
                })
            );
        })
    );
});

// 2. Activate Event: Clean old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.map(function(key) {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// 3. Fetch Event: Network-First with Offline Cache Fallback for Navigation & Static Assets
self.addEventListener('fetch', function(event) {
    var request = event.request;
    var url = new URL(request.url);

    // Jangan intercept request non-GET
    if (request.method !== 'GET') {
        return;
    }

    // Untuk halaman utama / navigasi: Network-First, fallback ke Cache jika offline
    if (request.mode === 'navigate' || url.pathname.endsWith('index.php') || url.pathname === '/' || url.pathname.endsWith('/')) {
        event.respondWith(
            fetch(request)
                .then(function(networkResponse) {
                    if (networkResponse && networkResponse.status === 200) {
                        var responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(request, responseClone);
                        });
                    }
                    return networkResponse;
                })
                .catch(function() {
                    return caches.match(request).then(function(cachedResponse) {
                        if (cachedResponse) return cachedResponse;
                        return caches.match('./index.php').then(function(indexFallback) {
                            if (indexFallback) return indexFallback;
                            return caches.match('./').then(function(rootFallback) {
                                if (rootFallback) return rootFallback;
                                return new Response("<!DOCTYPE html><html><head><meta charset='utf-8'><title>KAD Tech</title></head><body><div style='text-align:center;padding:40px;font-family:sans-serif;'><h2>Memuat KAD Tech...</h2><p>Menghubungkan ke sistem...</p></div><script>setTimeout(function(){ window.location.reload(); }, 1200);</script></body></html>", {
                                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                                });
                            });
                        });
                    });
                })
        );
        return;
    }

    // Untuk asset statis lokal (CSS, Icons, Fonts)
    if (url.origin === self.location.origin && (url.pathname.indexOf('/assets/') !== -1 || url.pathname.indexOf('manifest.json') !== -1)) {
        event.respondWith(
            caches.match(request).then(function(cachedResponse) {
                var fetchPromise = fetch(request).then(function(networkResponse) {
                    if (networkResponse && networkResponse.status === 200) {
                        var clone = networkResponse.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(request, clone);
                        });
                    }
                    return networkResponse;
                }).catch(function() {
                    return cachedResponse;
                });

                return cachedResponse || fetchPromise;
            })
        );
        return;
    }

    // Untuk CDN eksternal (Lucide Icons, fonts)
    if (url.hostname.indexOf('unpkg.com') !== -1 || url.hostname.indexOf('cdnjs.cloudflare.com') !== -1) {
        event.respondWith(
            caches.match(request).then(function(cached) {
                return cached || fetch(request).then(function(netRes) {
                    if (netRes && netRes.status === 200) {
                        var clone = netRes.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(request, clone);
                        });
                    }
                    return netRes;
                }).catch(function() {
                    return cached;
                });
            })
        );
        return;
    }

    // Untuk API GET: Coba jaringan terlebih dahulu, jika gagal offline coba cache
    if (url.pathname.indexOf('api.php') !== -1) {
        if (request.method === 'GET') {
            event.respondWith(
                fetch(request).then(function(networkResponse) {
                    if (networkResponse && networkResponse.status === 200 && url.search.indexOf('action=bootstrap') !== -1) {
                        var clone = networkResponse.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(request, clone);
                        });
                    }
                    return networkResponse;
                }).catch(function() {
                    return caches.match(request).then(function(cached) {
                        if (cached) return cached;
                        return new Response(JSON.stringify({ 
                            success: false, 
                            is_offline: true, 
                            message: 'Koneksi jaringan terputus. Menggunakan data cache lokal.' 
                        }), {
                            headers: { 'Content-Type': 'application/json' }
                        });
                    });
                })
            );
            return;
        }
    }
});

// 4. Background Sync Event (Triggered saat koneksi internet pulih)
self.addEventListener('sync', function(event) {
    if (event.tag === 'sync-offline-sales') {
        event.waitUntil(
            self.clients.matchAll().then(function(clients) {
                clients.forEach(function(client) {
                    client.postMessage({ type: 'SYNC_OFFLINE_SALES_REQUESTED' });
                });
            })
        );
    }
});
