/**
 * The admin app's service worker. It shows the pushes the site sends for new texts and opens the conversation when
 * one is tapped. It's registered with the scope /admin, so the shop's pages never use it.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    const push = event.data?.json() ?? {};
    const badge = Number(push.data?.badge ?? 0);
    const setBadge = 'setAppBadge' in self.navigator
        ? (badge > 0 ? self.navigator.setAppBadge(badge) : self.navigator.clearAppBadge()).catch(() => {})
        : Promise.resolve();

    // iOS stops sending pushes to a site that receives one without showing it, so every push shows.
    event.waitUntil(Promise.all([
        self.registration.showNotification(push.title ?? 'Ferguson Livestock', { body: push.body, data: push.data }),
        setBadge,
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = new URL(event.notification.data?.url ?? '/admin/conversations', self.location.origin).href;

    event.waitUntil((async () => {
        const [open] = await self.clients.matchAll({ type: 'window' });

        if (open) {
            try {
                await open.focus();
                await open.navigate(url);

                return;
            } catch {
                // Fall through and open a fresh window.
            }
        }

        await self.clients.openWindow(url);
    })());
});
