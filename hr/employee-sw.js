const CACHE_NAME = 'employee-portal-v2';
const RUNTIME_CACHE = 'employee-portal-runtime-v2';
const OFFLINE_URL = '/hr/offline.html';
const APP_SHELL = [
  OFFLINE_URL,
  '/hr/employee-app-icon.svg',
  '/hr/employee_manifest.json',
  '/hr/employee-app-icon-192.png',
  '/hr/employee-app-icon-512.png',
  '/hr/employee-startup-1284x2778.png',
  '/hr/install_app.php',
  '/auth/login_unified.php'
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
          const clone = response.clone();
          caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
          return response;
        })
        .catch(async () => {
          const cachedPage = await caches.match(request);
          if (cachedPage) return cachedPage;
          const runtime = await caches.open(RUNTIME_CACHE);
          const latest = await runtime.match('/hr/employee_portal.php');
          return latest || caches.match(OFFLINE_URL);
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(async cached => {
      if (cached) return cached;
      try {
        const response = await fetch(request);
        const clone = response.clone();
        const runtime = await caches.open(RUNTIME_CACHE);
        runtime.put(request, clone);
        return response;
      } catch (e) {
        return caches.match('/hr/employee-app-icon.svg');
      }
    })
  );
});
