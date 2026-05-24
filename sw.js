const CACHE_NAME = 'list-pwa-v5';

const urlsToCache = [
    '/',
    '/index.php',
    '/profile/profile.php',
    '/profile/dialogs.php',
    '/profile/chat.php',
    '/profile/group_chat.php',
    '/profile/channel.php',
    '/profile/bot.php',
    '/profile/support_chat.php',
    '/manifest.json',
    '/pwa_icon/apple-touch-icon.png',
    '/pwa_icon/favicon-96x96.png',
    '/pwa_icon/favicon.svg',
    '/pwa_icon/favicon.ico',
    '/pwa_icon/icon-72.png',
    '/pwa_icon/icon-96.png',
    '/pwa_icon/icon-144.png',
    '/pwa_icon/icon-192.png',
    '/pwa_icon/icon-512.png'
];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
        ))
    );
    return self.clients.claim();
});

self.addEventListener('fetch', event => {
    // Не кэшируем AJAX, API и polling запросы
    if (event.request.url.includes('/api/') || 
        event.request.url.includes('ajax=1') ||
        event.request.url.includes('poll=1')) {
        event.respondWith(fetch(event.request));
        return;
    }
    
    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response && response.status === 200 && !event.request.url.includes('/api/')) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request).then(cached => {
                    if (cached) return cached;
                    if (event.request.mode === 'navigate') return caches.match('/index.php');
                    return new Response('Нет соединения', { status: 503 });
                });
            })
    );
});