const CACHE_NAME = 'messaging-pwa-v1';
const RUNTIME_CACHE = 'messaging-runtime-v1';
const SCOPE_URL = new URL('./', self.location);
const APP_URL = new URL('./messagerie.php', self.location);
const OFFLINE_URL = new URL('./offline.html', self.location);
const ICON_192_URL = new URL('../hr/employee-app-icon-192.png', self.location);
const ICON_512_URL = new URL('../hr/employee-app-icon-512.png', self.location);
const APP_SHELL = [
  OFFLINE_URL.href,
  APP_URL.href,
  new URL('./manifest.json', self.location).href,
  ICON_192_URL.href,
  ICON_512_URL.href
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
          const cached = await caches.match(request);
          if (cached) return cached;
          const runtime = await caches.open(RUNTIME_CACHE);
          const latest = await runtime.match(APP_URL.href);
          return latest || caches.match(OFFLINE_URL.href);
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(async cached => {
      if (cached) return cached;
      try {
        const response = await fetch(request);
        if (response && response.ok) {
          const clone = response.clone();
          caches.open(RUNTIME_CACHE).then(cache => cache.put(request, clone));
        }
        return response;
      } catch (e) {
        return caches.match(ICON_192_URL.href);
      }
    })
  );
});

self.addEventListener('message', event => {
  const data = event.data || {};
  if (data.type === 'SHOW_NOTIFICATION') {
    const title = data.title || 'Messagerie';
    const body = data.body || 'Nouveau message';
    const unread = Number(data.unread || 0);
    event.waitUntil((async () => {
      if ('setAppBadge' in self.navigator && unread > 0) {
        try { await self.navigator.setAppBadge(unread); } catch (e) {}
      } else if ('clearAppBadge' in self.navigator && unread <= 0) {
        try { await self.navigator.clearAppBadge(); } catch (e) {}
      }
      await self.registration.showNotification(title, {
        body,
        tag: data.tag || 'messaging-unread',
        renotify: true,
        silent: false,
        requireInteraction: true,
        vibrate: [300, 150, 300, 150, 500],
        badge: ICON_192_URL.href,
        icon: ICON_192_URL.href,
        timestamp: Date.now(),
        data: {
          url: data.url || APP_URL.href
        }
      });
    })());
  }

  if (data.type === 'SET_BADGE') {
    const unread = Number(data.unread || 0);
    event.waitUntil((async () => {
      if (unread > 0 && 'setAppBadge' in self.navigator) {
        try { await self.navigator.setAppBadge(unread); } catch (e) {}
      }
      if (unread <= 0 && 'clearAppBadge' in self.navigator) {
        try { await self.navigator.clearAppBadge(); } catch (e) {}
      }
    })());
  }
});

self.addEventListener('push', event => {
  event.waitUntil((async () => {
    let data = {};
    try {
      data = event.data ? event.data.json() : {};
    } catch (e) {
      try {
        data = { body: event.data ? event.data.text() : 'Nouveau message' };
      } catch (err) {
        data = { body: 'Nouveau message' };
      }
    }
    const title = data.title || 'Messagerie';
    const body = data.body || 'Nouveau message';
    const unread = Number(data.unread || 1);
    if ('setAppBadge' in self.navigator && unread > 0) {
      try { await self.navigator.setAppBadge(unread); } catch (e) {}
    }
    await self.registration.showNotification(title, {
      body,
      tag: data.tag || 'push-chat',
      renotify: true,
      silent: false,
      requireInteraction: true,
      vibrate: [300, 150, 300, 150, 500],
      badge: ICON_192_URL.href,
      icon: ICON_192_URL.href,
      timestamp: Date.now(),
      data: {
        url: data.url || APP_URL.href,
        conversation: data.conversation || null
      }
    });
  })());
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || APP_URL.href;
  event.waitUntil((async () => {
    const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of allClients) {
      if ('focus' in client) {
        client.navigate(targetUrl);
        return client.focus();
      }
    }
    if (clients.openWindow) return clients.openWindow(targetUrl);
  })());
});
