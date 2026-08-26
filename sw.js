// Define a cache name
const CACHE_NAME = 'anipaca-cache-v2';

// ShareThis domains to bypass
const BYPASS_DOMAINS = [
    'platform-api.sharethis.com',
    'l.sharethis.com',
    'buttons-config.sharethis.com'
];

// List of URLs to cache (you can add more as needed)
const urlsToCache = [
    '/index.php',
    '/src/assets/css/styles.min.css',
    '/src/assets/css/bootstrap.min.css',
    '/src/assets/css/search.css',
    '/src/assets/js/app.min.js',
    '/src/assets/js/search.js',
    '/src/assets/js/function.js',
    '/public/logo/favicon.png',
    '/public/logo/logo.png',
    '/public/logo/favicon.ico',
    '/offline.html' // ✅ Make sure you create this file!
];

// Install event – cache all important files
self.addEventListener('install', event => {
    console.log('✅ Service Worker: Installed');
    self.skipWaiting(); // Activate immediately

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('✅ Caching assets...');
                return Promise.all(
                    urlsToCache.map(url =>
                        cache.add(url).catch(err => {
                            console.error('❌ Failed to cache:', url, err);
                            return Promise.resolve(); // Keep going on failure
                        })
                    )
                );
            })
    );
});

// Activate event – remove old caches
self.addEventListener('activate', event => {
    console.log('✅ Service Worker: Activated');
    const cacheWhitelist = [CACHE_NAME];
    event.waitUntil(
        caches.keys().then(cacheNames =>
            Promise.all(
                cacheNames.map(name => {
                    if (!cacheWhitelist.includes(name)) {
                        console.log('🗑 Deleting old cache:', name);
                        return caches.delete(name);
                    }
                })
            )
        )
    );
});

// Fetch event – serve cached or network fallback
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Bypass ShareThis scripts
    if (BYPASS_DOMAINS.some(domain => url.hostname.includes(domain))) {
        return;
    }

    event.respondWith(
        caches.match(event.request, { ignoreSearch: true }) // match even with ?v= params
            .then(response => {
                if (response) {
                    console.log('✅ Cache hit:', event.request.url);
                    return response;
                }

                // Clone the request to use it twice
                const fetchRequest = event.request.clone();

                return fetch(fetchRequest).then(networkResponse => {
                    if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
                        return networkResponse;
                    }

                    const responseToCache = networkResponse.clone();

                    event.waitUntil(
                        caches.open(CACHE_NAME).then(cache => {
                            if (event.request.method === 'GET') {
                                cache.put(event.request, responseToCache);
                            }
                        })
                    );

                    return networkResponse;
                }).catch(() => {
                    // Fallback: show offline page if available
                    if (event.request.headers.get('accept').includes('text/html')) {
                        return caches.match('/offline.html');
                    }
                    return new Response('⚠️ You are offline and this resource is unavailable.');
                });
            })
    );
});
