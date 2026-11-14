/**
 * The admin app's service worker. It shows the pushes the site sends for new texts and opens the conversation when
 * one is tapped. It's registered with the scope /admin, so the shop's pages never use it.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let push = {};

    try {
        push = event.data?.json() ?? {};
    } catch {
        // Not JSON: still shown, under the default title.
    }

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

/**
 * Where a tapped push goes: its link when that's in the admin on this site, and Conversations otherwise.
 */
function adminUrl(link) {
    const conversations = new URL('/admin/conversations', self.location.origin).href;

    try {
        const url = new URL(link ?? conversations, self.location.origin);
        const inAdmin = url.pathname === '/admin' || url.pathname.startsWith('/admin/');

        return url.origin === self.location.origin && inAdmin ? url.href : conversations;
    } catch {
        return conversations;
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = adminUrl(event.notification.data?.url);

    event.waitUntil((async () => {
        const [open] = await self.clients.matchAll({ type: 'window' });

        // Navigated before it's focused: navigate() doesn't need the tap's allowance to bring a window forward, and
        // focus() may use it up.
        if (open) {
            try {
                const client = (await open.navigate(url)) ?? open;
                await client.focus().catch(() => {});

                return;
            } catch {
                // navigate() is missing or refused: open a window instead.
            }
        }

        await self.clients.openWindow(url);
    })());
});
