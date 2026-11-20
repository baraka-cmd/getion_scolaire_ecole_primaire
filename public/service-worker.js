'use strict';

self.addEventListener('install', event => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', event => {
    let payload = {};
    if (event.data) {
        try {
            payload = event.data.json();
        } catch {
            payload = { body: event.data.text() };
        }
    }

    const title = typeof payload.title === 'string' && payload.title.trim()
        ? payload.title.trim().slice(0, 120)
        : 'Nouvelle notification';
    const body = typeof payload.body === 'string' ? payload.body.slice(0, 240) : '';
    const scopeUrl = new URL(self.registration.scope);
    let targetUrl = scopeUrl.href;

    if (typeof payload.url === 'string') {
        try {
            const candidate = new URL(payload.url, scopeUrl);
            if (candidate.origin === scopeUrl.origin && candidate.pathname.startsWith(scopeUrl.pathname)) {
                targetUrl = candidate.href;
            }
        } catch {
            targetUrl = scopeUrl.href;
        }
    }

    event.waitUntil(self.registration.showNotification(title, {
        body,
        data: { url: targetUrl },
        tag: typeof payload.id === 'string' ? `school-notification-${payload.id.slice(0, 80)}` : undefined,
        renotify: false
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const scopeUrl = new URL(self.registration.scope);
    let targetUrl = scopeUrl.href;
    const requestedUrl = event.notification.data && event.notification.data.url;

    if (typeof requestedUrl === 'string') {
        try {
            const candidate = new URL(requestedUrl);
            if (candidate.origin === scopeUrl.origin && candidate.pathname.startsWith(scopeUrl.pathname)) {
                targetUrl = candidate.href;
            }
        } catch {
            targetUrl = scopeUrl.href;
        }
    }

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
        for (const client of clients) {
            const clientUrl = new URL(client.url);
            if (
                clientUrl.origin === scopeUrl.origin
                && clientUrl.pathname.startsWith(scopeUrl.pathname)
                && 'focus' in client
            ) {
                return client.navigate(targetUrl).then(() => client.focus());
            }
        }
        return self.clients.openWindow(targetUrl);
    }));
});
