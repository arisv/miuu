/*
 * MIU service worker. Kept deliberately small: it makes the site installable and shows a
 * friendly page when a navigation fails offline. Uploads, API calls and files are never cached.
 */
const VERSION = 'miu-sw-v1';

const OFFLINE_HTML = `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>MIU · offline</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#131715;color:#e6e6e6;font:16px/1.5 system-ui,sans-serif;text-align:center}
main{padding:32px}h1{font-size:1.4rem;margin:0 0 8px}p{color:#8f948f;margin:0 0 20px}
a{display:inline-block;padding:10px 20px;border-radius:999px;background:#34e6a1;color:#0f1d17;text-decoration:none;font-weight:600}</style></head>
<body><main><h1>You are offline</h1><p>MIU needs a connection to upload or browse files.</p><a href="/">Try again</a></main></body></html>`;

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET' || request.mode !== 'navigate') {
        return; // everything except page navigations goes straight to the network
    }
    event.respondWith((async () => {
        try {
            return await fetch(request);
        } catch (e) {
            return new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
        }
    })());
});
