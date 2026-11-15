# Push notifications for incoming texts

Texts to the shop's number only show up in the admin's Conversations page, so the owner has to keep checking it.
They want the admin on their iPhone's home screen and a push notification the moment someone texts.

## Approach

Make the admin an installable web app and send a Web Push for each incoming text. iOS has supported this since
16.4 for web apps added to the Home Screen. Rejected: a native iOS app (Apple developer fee, Xcode builds, and
TestFlight builds that expire every 90 days, all for one phone; its only real gain is replying from the lock
screen) and forwarding each text by SMS or email (duplicate texts or buried emails, and no home screen app).

## Rules

- Every new incoming text pushes, once. Twilio's resends of the same text (same `MessageSid`) don't push again.
- STOP and START texts (`OptOutKeywords`, `OptInKeywords`) don't push. They need no reply and still show in
  Conversations.
- Pushes go to the admin account (`shop.admin_email`), to every device that has turned notifications on under it.
  No admin account: no push.
- The title is the subscriber's name, or the number when the sender isn't a subscriber. The body is the text.
  Tapping it opens `/admin/conversations?phone=<number>`.
- The icon badge shows `ConversationList::attentionCount()`, the same count as the sidebar badge. It's set when a
  push arrives and refreshed when an admin page loads or a conversation is opened.
- No quiet hours here: the phone's Focus and Do Not Disturb settings decide.
- The push is queued, so Twilio still gets its answer straight away. The push package swallows failed pushes, so
  `WarnWhenPushesStop` reports any failure other than a lapsed phone as an error (it shows up in Nightwatch). The
  body is cut to 250 characters so a long text still fits in a push.

## When pushes stop

A phone's sign-up can lapse without the owner noticing: the app comes off the home screen, or there's a new phone.
The package deletes a sign-up when Apple reports it gone, so without a warning, texts would just stop arriving.

- Every admin page shows a banner while no phone is signed up (once the push keys are set).
- When Apple reports the last sign-up gone, the admin is emailed once (`PushesStopped`). Turning notifications off on
  purpose doesn't send it.

## Installing

- `public/admin.webmanifest`: name and short name "Ferguson Livestock", `id` and `scope` `/admin` (no trailing slash, so the dashboard at `/admin` stays in the app), `start_url`
  `/admin/conversations`, standalone, the existing icons and colours.
- Linked from the admin's `<head>` through the `HEAD_END` render hook it already uses, with `apple-touch-icon` and
  `apple-mobile-web-app-title`. The shop's `site.webmanifest` and pages don't change.

## Data

The package's `push_subscriptions` table, morphed to `User`, which uses `HasPushSubscriptions`. The package removes
a subscription when the push service reports it gone. VAPID keys come from `VAPID_PUBLIC_KEY` and
`VAPID_PRIVATE_KEY`, with the site URL as the subject.

## Pieces

- `laravel-notification-channels/webpush` ^13 (supports Laravel 13).
- `public/admin-sw.js`, a service worker scoped to `/admin`. On `push` it shows the notification and sets the
  badge. On `notificationclick` it focuses an open admin window and takes it to the conversation, or opens one.
- `App\Notifications\AdminPush`: queued, sent through `WebPushChannel`. `AdminPush::forText()` builds one for a
  new text and `AdminPush::test()` the test push. The link is relative, so a tap stays inside the installed app.
- `TwilioInboundController`: after storing a text, if it was just created and isn't STOP or START, notify the admin.
  The admin lookup is shared with `CheckDropBeforeItOpens`.
- Conversations page "Notifications" control: Livewire methods to save the phone's subscription, remove it, and
  send a test push to the admin. The test reports what Apple said (sent, refused with its reason, or no phone
  signed up), because it's the owner's check that pushes work. A small script asks permission and subscribes with the public VAPID key. Outside
  the installed app it says to add it to the Home Screen first. When permission was denied it explains how to turn
  it on in Settings.
- Badge sync: the admin passes the attention count to the page on load, and again after a conversation is opened
  or the open one refreshes. The script calls `navigator.setAppBadge()`, or `clearAppBadge()` at zero.

## Rollout

1. Generate the VAPID keys once and set them on Laravel Cloud before deploying.
2. Deploy. The migration adds `push_subscriptions`.
3. On the phone: open the admin in Safari, then Share → Add to Home Screen. Open the icon, log in with "remember
   me" ticked, turn notifications on and send a test.

## Testing

Feature tests:
- A new text notifies the admin once, with the name, the text and the conversation link.
- A sender who isn't a subscriber is titled by their number.
- A resent text doesn't notify again.
- STOP and START texts don't notify.
- No admin account means no push.
- The Conversations page saves and removes a subscription for the logged-in admin and sends a test push.

A browser test checks the page script loads, the service worker registers for `/admin` and the control settles
without JavaScript errors. Headless Chromium denies notifications, so turning them on, the push itself and the tap
are checked by hand on the owner's phone.

## Found in review

- The push library caches its signed VAPID token, which lasts 12 hours, and only clears the cache once a batch's
  results have all been handled. If handling throws part way, the next push can reuse the old token, and more than
  12 hours later Apple refuses it. `AppServiceProvider` turns that reuse off, so each push is signed fresh.
- "Send a test" sends through the channel directly and says whether Apple took it.
- The lapse email goes at most once a day, and a mail failure can't fail the push.
