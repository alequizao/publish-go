/* ───────────────────────────────────────────────
   Publish Go — Service Worker (PWA)
   Precache do app shell + runtime cache stale-while-revalidate,
   fallback offline, push notifications e background sync.
   ─────────────────────────────────────────────── */
const VERSION = 'pg-v1.2.0';
const SHELL_CACHE = 'pg-shell-' + VERSION;
const RUNTIME_CACHE = 'pg-runtime-' + VERSION;

const SHELL = [
    '/publishgo/app/index.html',
    '/publishgo/app/dashboard.html',
    '/publishgo/app/orders.html',
    '/publishgo/app/deliveries.html',
    '/publishgo/app/couriers.html',
    '/publishgo/app/settings.html',
    '/publishgo/app/admin.html',
    '/publishgo/app/courier.html',
    '/publishgo/app/courier-register.html',
    '/publishgo/assets/css/app.css',
    '/publishgo/assets/js/app.js',
    '/publishgo/assets/js/realtime.js',
    '/publishgo/assets/js/map.js',
    '/publishgo/manifest.json',
    '/publishgo/offline.html',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll(SHELL).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== SHELL_CACHE && k !== RUNTIME_CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);

    // Não cacheia chamadas de API nem o WebSocket — sempre rede.
    if (url.pathname.includes('/api/') || url.pathname.endsWith('/api')) {
        event.respondWith(fetch(req).catch(() => new Response(JSON.stringify({ ok: false, error: { message: 'offline' } }), { headers: { 'Content-Type': 'application/json' }, status: 503 })));
        return;
    }

    // Navegação → network-first com fallback offline.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).then((res) => {
                const copy = res.clone();
                caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
                return res;
            }).catch(() => caches.match(req).then((r) => r || caches.match('/publishgo/offline.html')))
        );
        return;
    }

    // Demais GET → stale-while-revalidate.
    event.respondWith(
        caches.match(req).then((cached) => {
            const network = fetch(req).then((res) => {
                if (res && res.status === 200 && (url.origin === location.origin || url.protocol === 'https:')) {
                    const copy = res.clone();
                    caches.open(RUNTIME_CACHE).then((c) => c.put(req, copy));
                }
                return res;
            }).catch(() => cached);
            return cached || network;
        })
    );
});

/* ── Push notifications ── */
self.addEventListener('push', (event) => {
    let data = { title: 'Publish Go', body: 'Você tem uma nova atualização.' };
    try { if (event.data) data = event.data.json(); } catch (e) {}
    event.waitUntil(
        self.registration.showNotification(data.title || 'Publish Go', {
            body: data.body || '',
            icon: '/publishgo/assets/icons/icon-192.png',
            badge: '/publishgo/assets/icons/icon-192.png',
            vibrate: [80, 40, 80],
            data: { url: data.url || '/publishgo/app/dashboard.html' },
            tag: data.tag || 'pg-notify',
            renotify: true,
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data?.url || '/publishgo/app/dashboard.html';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const c of list) { if (c.url.includes(target) && 'focus' in c) return c.focus(); }
            return self.clients.openWindow(target);
        })
    );
});

/* ── Background sync (reenvio de ações offline) ── */
self.addEventListener('sync', (event) => {
    if (event.tag === 'pg-sync-orders') {
        event.waitUntil(replayQueue());
    }
});

async function replayQueue() {
    // Estratégia: o app guarda ações pendentes em IndexedDB sob 'pg-queue'.
    // Aqui apenas notificamos os clientes para sincronizarem ao voltar a conexão.
    const clientsList = await self.clients.matchAll();
    clientsList.forEach((c) => c.postMessage({ type: 'pg-sync' }));
}
