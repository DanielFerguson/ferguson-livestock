/**
 * The admin as an app on the owner's phone. Registers the service worker that shows pushes, keeps the app icon's
 * badge in step with the conversations that need a look, and runs the Notifications control on Conversations.
 */

const worker = 'serviceWorker' in navigator
    ? navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin' }).then(() => navigator.serviceWorker.ready)
    : Promise.reject(new Error('This browser has no service workers.'));

// The control reports a browser without service workers itself, so the rejection needs no other handling.
worker.catch(() => {});

function setBadge(count) {
    if (!('setAppBadge' in navigator)) {
        return;
    }

    (count > 0 ? navigator.setAppBadge(count) : navigator.clearAppBadge()).catch(() => {});
}

const attentionCount = document.querySelector('meta[name="admin-attention-count"]');

if (attentionCount !== null) {
    setBadge(Number(attentionCount.content));
}

// Sent by the Conversations page whenever reading, flagging or finishing a conversation changes the count.
window.addEventListener('attention-count', (event) => setBadge(Number(event.detail.count)));

function supportsPush() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * The public VAPID key as the bytes the Push API wants: it arrives as base64url text.
 */
function keyBytes(base64url) {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}

/**
 * What the site needs to send this phone a push: where to, the keys to encrypt with, and how.
 */
function describe(subscription) {
    const encodings = PushManager.supportedContentEncodings ?? ['aes128gcm'];

    return { ...subscription.toJSON(), contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : encodings[0] };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pushNotifications', (publicKey) => ({
        // checking, busy, unsupported, denied, off, on or failed
        state: 'checking',

        async init() {
            if (!supportsPush()) {
                this.state = 'unsupported';

                return;
            }

            if (Notification.permission === 'denied') {
                this.state = 'denied';

                return;
            }

            try {
                const subscription = await (await worker).pushManager.getSubscription();

                if (subscription === null) {
                    this.state = 'off';

                    return;
                }

                // Saved again on every visit, so the site always has this phone's latest address.
                this.state = (await this.$wire.savePushSubscription(describe(subscription))) === true ? 'on' : 'failed';
            } catch {
                this.state = 'unsupported';
            }
        },

        async turnOn() {
            this.state = 'busy';

            // Asked first, while the tap still counts: iOS only shows the prompt in direct answer to one.
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                this.state = permission === 'denied' ? 'denied' : 'off';

                return;
            }

            try {
                const subscription = await (await worker).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });

                this.state = (await this.$wire.savePushSubscription(describe(subscription))) === true ? 'on' : 'failed';
            } catch {
                this.state = 'failed';
            }
        },

        async turnOff() {
            this.state = 'busy';

            try {
                const subscription = await (await worker).pushManager.getSubscription();

                if (subscription !== null) {
                    await this.$wire.removePushSubscription(subscription.endpoint);
                    await subscription.unsubscribe();
                }

                this.state = 'off';
            } catch {
                this.state = 'failed';
            }
        },

        sendTest() {
            this.$wire.sendTestPush();
        },
    }));
});
