/**
 * asset/sw.js — Minimal Service Worker
 *
 * Purpose:
 *  1) Make /asset/ installable as a PWA (browser requires fetch handler).
 *  2) Cache static shell (CSS/JS/icons) for fast repeat loads.
 *  3) NEVER cache HTML/AJAX responses — always go to network for fresh data.
 *
 * No offline mode for dynamic data — that would require background sync
 * + IndexedDB which is out of scope (Phase C).
 */

const VERSION = 'v1.0.0';
const SHELL_CACHE = `asset-shell-${VERSION}`;

// Static assets to pre-cache (URLs are relative to scope /asset/)
const SHELL_URLS = [
    'assets/css/asset.css',
    'assets/js/asset.js',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/icon-maskable.png',
    'assets/icons/favicon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) =>
            // Best-effort: don't fail install if some asset 404s
            Promise.all(SHELL_URLS.map((u) =>
                cache.add(u).catch(() => null)
            ))
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

    // Same-origin only for cache strategy
    if (url.origin !== self.location.origin) return;

    // Never cache PHP / AJAX / API
    if (url.pathname.endsWith('.php') ||
        url.pathname.includes('/ajax/') ||
        url.pathname.includes('/api/')) {
        return; // let browser do default network fetch
    }

    // Static assets: cache-first with network fallback
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

// Allow skipWaiting from page when "update available" prompt is accepted
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});
