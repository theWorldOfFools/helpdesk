/* Service worker minimal: cache-first khusus aset statis same-origin.
   Halaman dinamis (PHP) TIDAK di-cache agar data sesi tidak basibasi/bocor. */
const CACHE = 'helpdesk-v4';
const PRECACHE = [
  './assets/css/style.css',
  './assets/js/script.js',
  './assets/js/helpdesk-markdown-editor.js',
  './assets/vendor/toastui/toastui-editor-all.min.js',
  './assets/vendor/toastui/toastui-editor.min.css',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  const sameOrigin = url.origin === self.location.origin;
  const isAsset = sameOrigin && (url.pathname.includes('/assets/') || url.pathname.endsWith('manifest.json'));
  if (e.request.method !== 'GET' || !isAsset) return; // dinamis: langsung network
  e.respondWith(
    caches.match(e.request).then((hit) => {
      const net = fetch(e.request).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(e.request, copy));
        }
        return res;
      }).catch(() => hit);
      return hit || net;
    })
  );
});
