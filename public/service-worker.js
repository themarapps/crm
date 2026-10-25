const CACHE_NAME = 'crm-pwa-shell-v2';
const APP_SHELL = [
  '/',
  '/manifest.json',
  '/icons/crm-icon.png',
  '/icons/crm-icon-512.png',
  '/assets/app.css?v=crm-customer-profile-v3-20261026',
  '/assets/app.js?v=crm-source-separation-20261026',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('crm-pwa-') && key !== CACHE_NAME).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/')));
    return;
  }
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/') || url.pathname === '/manifest.json') {
    event.respondWith(caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(request);
      const refresh = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone());
        return response;
      });
      return cached || refresh;
    }));
  }
});

self.addEventListener('push', (event) => {
  let payload = {};
  try { payload = event.data ? event.data.json() : {}; }
  catch { payload = { body: event.data?.text() || '' }; }
  const notification = {
    body: payload.body || 'لديك متابعة مجدولة في CRM.',
    icon: '/icons/crm-icon.png',
    badge: '/icons/crm-icon.png',
    tag: payload.tag || 'crm-notification',
    data: { url: payload.url || '/#/tasks' },
    dir: 'rtl',
    lang: 'ar',
  };
  event.waitUntil(self.registration.showNotification(payload.title || 'إدارة العملاء', notification));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || '/#/tasks', self.location.origin);
  if (target.origin !== self.location.origin) return;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
    const existing = clients.find((client) => new URL(client.url).origin === self.location.origin);
    if (existing) {
      await existing.navigate(target.href);
      return existing.focus();
    }
    return self.clients.openWindow(target.href);
  }));
});
