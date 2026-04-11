const CACHE_NAME = 'stock-system-v1';
const RUNTIME_CACHE = 'stock-system-runtime-v1';
const OFFLINE_URL = '/stock/offline_stock.html';
const APP_SHELL = [
  OFFLINE_URL,
  '/stock/stock_manifest.json',
  '/stock/stock-app-icon.svg',
  '/stock/stock-app-icon-192.png',
  '/stock/stock-app-icon-512.png',
  '/stock/install_stock_app.php',
  '/stock/stock_tracking.php',
  '/stock/stock_update_fixed.php',
  '/stock/stocks_erp_pro.php',
  '/stock/products_erp_pro.php',
  '/stock/appro_requests.php'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(APP_SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(key => ![CACHE_NAME, RUNTIME_CACHE].includes(key)).map(key => caches.delete(key)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const { request } = event;
  if (request.method !== 'GET') return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => {
          caches.open(RUNTIME_CACHE).then(cache => cache.put(request, response.clone()));
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          if (cached) return cached;
          return caches.match(OFFLINE_URL);
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(async cached => {
      if (cached) return cached;
      try {
        const response = await fetch(request);
        caches.open(RUNTIME_CACHE).then(cache => cache.put(request, response.clone()));
        return response;
      } catch (e) {
        return caches.match('/stock/stock-app-icon.svg');
      }
    })
  );
});
