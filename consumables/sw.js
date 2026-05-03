/**
 * consumables/sw.js — Minimal Service Worker for Consumables PWA
 *
 * Mirrors /asset/sw.js: caches static shell, never caches PHP/AJAX.
 */

const VERSION = 'v1.0.0';
const SHELL_CACHE = `consumables-shell-${VERSION}`;

// Reuses asset module's CSS/icons
const SHELL_URLS = [
    '../asset/assets/css/asset.css',
    '../asset/assets/icons/icon-192.png',
    '../asset/assets/icons/icon-512.png',
    '../asset/assets/icons/icon-maskable.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) =>
            Promise.all(SHELL_URLS.map((u) => cache.add(u).catch(() => null)))
        )
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    if (url.pathname.endsWith('.php') ||
        url.pathname.includes('/ajax/') ||
        url.pathname.includes('/api/')) {
        return;
    }

    if (url.pathname.match(/\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ttf|ico)$/i)) {
        event.respondWith(
            caches.match(req).then((cached) =>
                cached || fetch(req).then((res) => {
                    if (res.ok && res.type === 'basic') {
                        const copy = res.clone();
                        caches.open(SHELL_CACHE).then((c) => c.put(req, copy));
                    }
                    return res;
                }).catch(() => cached)
            )
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});
