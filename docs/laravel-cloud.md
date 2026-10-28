# Laravel Cloud setup

How the Laravel app is hosted. `docs/switch-over.md` records how the `production` environment was set up and the site moved onto it.

## Application

- Create one Laravel Cloud application from `DanielFerguson/ferguson-livestock`, in **Asia Pacific (Sydney)**. Keep every resource in the same region.
- Plan: **Growth** (Starter only allows one replica).

## `staging` environment

Tracks `main` and uses Stripe **test** keys.

| Setting | Value |
| --- | --- |
| Branch | `main` |
| PHP | 8.5 |
| Compute | Flex 1 GiB, 1 replica, sleep allowed |
| Scheduler | On |
| Build commands | `composer install --no-dev --optimize-autoloader`<br>`npm ci`<br>`npm run build`<br>`php artisan optimize` |
| Deploy commands | `php artisan migrate --force`<br>`php artisan responsecache:clear` |

Resources to attach:

- **Laravel Serverless Postgres 18**, 0.25–1 CU, sleep allowed. Point the app at the **pooler** host (the host name with `-pooler`) if Cloud injects the direct host.
- **Laravel Valkey**, Flex 250 MB.
- **Managed queue** (Flex). Cloud sets `QUEUE_CONNECTION=cloud`; the app already requires `aws/aws-sdk-php`. Don't use queue clusters — they shut down on 2026-09-30.

Environment variables to set yourself (Cloud injects the database, Valkey and queue credentials):

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<staging-host>
CACHE_STORE=redis
SESSION_DRIVER=redis
SHOP_ADMIN_EMAIL=<the admin's email>
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
MAIL_MAILER=resend
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=orders@fergusonlivestock.com.au
SHOP_ADMIN_PHONE=+614...
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM_NUMBER=+614...
TURNSTILE_SITE_KEY=0x...
TURNSTILE_SECRET_KEY=0x...
```

The Turnstile keys turn on Cloudflare's bot check on the order and wait-list forms. Without both, the check quietly lets everyone through, and each drop's pre-flight check reports it. Create the widget in Cloudflare's Turnstile settings and add every hostname that serves the forms (www, the apex, and the staging host).

The response cache uses `CACHE_STORE`, so every replica shares the cached marketing pages. The deploy command clears it, so a deploy never serves stale pages.

`APP_URL` must be the environment's own address: the drop pre-flight check looks for a Stripe webhook endpoint at `APP_URL/api/webhooks/stripe`. `SHOP_URL` stays the main address, `https://fergusonlivestock.com.au` (no www), everywhere. Laravel Cloud's domain settings redirect www to it.

Resend sends from the verified `fergusonlivestock.com.au` domain, and the API key is restricted to it, so `MAIL_FROM_ADDRESS` must be an address on that domain. The `send` record Resend asks for is where bounces go, not an address to send from: Resend refuses mail from `send.fergusonlivestock.com.au`. Until the domain is verified, pre-flight emails fail and the result is only shown in the admin.

`APP_ENV=staging` matters: every non-production response is sent with `X-Robots-Tag: noindex, nofollow`, because staging pages carry the production canonical URLs.

## First deploy of each environment

Run these once from the environment's **Commands** tab:

```sh
php artisan db:seed --class=ProductSeeder --force
php artisan make:filament-user --name="Daniel Ferguson" --email=<SHOP_ADMIN_EMAIL> --password=<generated password>
```

The command runner isn’t interactive, so the password goes on the command line: generate it in your password manager.

The seeder adds the boxes, extras and delivery fee without prices; each drop sets its own. Re-running it doesn't overwrite edits made in the admin.

Then sign in at `/admin`. Filament asks you to set up an authenticator app on first sign-in; keep the recovery codes somewhere safe. Only the `SHOP_ADMIN_EMAIL` account can sign in, even if other users exist.

The scheduler and the managed queue must both stay on. Delayed jobs on the queue do the exact timing:

- 10 minutes before a published drop opens, its pre-flight check runs, then emails the admin and adds a notification in the admin.
- When a checkout's payment page closes, the order is settled, so a lost webhook never leaves stock held.
- At a broadcast's scheduled time, it starts.

The scheduler runs `drops:preflight`, `sms:send-scheduled` and `orders:sweep` once an hour. They time the jobs for anything due within the hour, and catch up on any job that went missing. They don't run every minute, because Laravel Cloud wakes the app, database and cache for every scheduled run, and they would never get to sleep.

## Stripe

In the Stripe dashboard (test mode for staging, live mode for production):

1. **Webhook endpoint** at `https://<host>/api/webhooks/stripe`, on API version `2026-08-26.dahlia`, sending these events:
   - `checkout.session.completed`
   - `checkout.session.expired`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
   - `charge.refunded`

   Put its signing secret in `STRIPE_WEBHOOK_SECRET`. Each drop's pre-flight check confirms the endpoint exists and sends all five.
2. **Prices:** each drop item needs a one-time AUD price. Paste its ID into the drop form, which checks it against Stripe.
3. **Payment methods:** cards work with nothing else set up. If a bank debit such as BECS is turned on, those orders show as "Payment clearing" and keep their stock held until Stripe says the payment cleared or failed.

Checkout sends customers to Stripe's payment page for 31 minutes (Stripe's minimum is 30). If they leave, the stock goes back on sale when Stripe closes the page, or straight away if they press Stripe's back link. Refunds are recorded on the order but never restock: adjust the drop's stock by hand if you want to sell the items again.

Emails go through Resend when an order is paid: a confirmation to the customer (replies go to the farm's address) and a new-order email to the farm.

Outside production, every email goes to the farm's address (`email` in `config/shop.php`) instead of whoever it was written to. Staging takes free test-mode orders, so without this anyone who found it could send "your order is paid" emails from the shop's domain to any address.

## Drop day

Every page polls `/api/drop` for live stock: every couple of seconds while a drop is live or about to open, every 30 seconds while one is scheduled, and not at all otherwise or in background tabs. The feed is built at most once a second and rebuilt straight after any stock change, and it may be cached by Cloudflare for a second too. `SHOP_DROP_POLL_MS` changes the polling interval (default 2000).

Before each drop:

1. Raise the minimum replicas (Growth plan autoscaling isn't scheduled). The pre-flight email reminds you.
2. Watch the dashboard's current-drop table, which refreshes every five seconds.

**Load test (staging):** `k6 run -e BASE_URL=https://<staging-host> scripts/load/drop-day.js` runs 500 visitors polling the feed for five minutes, with some loading the order page. It fails if more than 1% of requests fail or the feed's 95th percentile goes over 300 ms. It doesn't start checkouts (see the script's notes).

**Cloudflare rate limit:** the `fergusonlivestock.com.au` zone in Cloudflare (Free plan) proxies the site, and has one rate limiting rule, "Wait-list and order form submissions per visitor". An IP address that sends more than 60 requests in 10 seconds to `/waitlist` or `/livewire-*/update` (the order form and the admin) is blocked from those paths for 10 seconds. Page loads, the stock feed and the Stripe and Twilio webhooks aren't counted. If customers say the order form stops responding during a drop, look for blocks under Security → Analytics in Cloudflare before raising the limit.

**Visitors' IP addresses:** checkout limits tries per IP address, so the app must see each visitor's own address, not a proxy's, and not one the visitor made up. `trustProxies(at: '*')` in `bootstrap/app.php` is right on Laravel Cloud, because its edge replaces any `X-Forwarded-For` a visitor sends. Checked on production on 2026-10-25, through the proxied Cloudflare zone: a wait-list try sent with `X-Forwarded-For: 198.51.100.23` counted against the sender's real address, and a made-up `X-Forwarded-Host` was ignored.

Check again on a new environment, or if the domain's Cloudflare proxying changes:

1. Join the wait list from your phone, then check the subscriber's IP address in the admin matches your phone's. If it shows the same address for everyone, the app trusts too few proxies.
2. With `curl`, load the home page (keeping its cookies) and post its wait-list form empty, with just the `_token`, twice: once plainly, then with the header `X-Forwarded-For: 198.51.100.23` (a documentation address nobody uses). Nothing is saved, because the form fails validation. The second response's `X-RateLimit-Remaining` should be one lower than the first's, not back at 4. If it starts again, the app trusts addresses a visitor can invent, and every per-address limit can be dodged.

## Twilio (wait-list texts)

Texts go out from one Australian 04xx number, so people can reply. The number is **+61 485 048 980**, bought on 28 October 2026 on the live Twilio account (not the test credentials), under the approved Australia Mobile Business regulatory bundle "FLC Compliance Registration". It costs about US$8.25 a month, plus about US$0.05 per text segment sent and US$0.0075 per reply received.

To set up a number the same way:

1. Under the number's **Messaging configuration**, set **A message comes in** to a webhook, `https://<host>/api/webhooks/twilio/inbound`, method **POST**. Delivery reports need no setup: each text asks Twilio to report to `/api/webhooks/twilio/status`.
2. Under Messaging → Settings → **Geo permissions**, allow Australia.
3. Leave the number out of any Messaging Service: the app sends from the number directly (`TWILIO_FROM_NUMBER`), and Twilio's Advanced Opt-Out is a Messaging Service setting, so it doesn't apply. The app keeps its own opt-out list, which stops texts to anyone who replied STOP, and also opts people out when Twilio reports error 21610.
4. Set `TWILIO_ACCOUNT_SID` (the live one), `TWILIO_AUTH_TOKEN` and `TWILIO_FROM_NUMBER` in the environment, and redeploy.

Both webhooks check Twilio's signature against `TWILIO_AUTH_TOKEN` and the address Twilio called, so the console must use the environment's own address (the same as `APP_URL`). Unsigned requests get a 403.

Twilio's test credentials don't send texts or call webhooks, so any other environment would have to use the real account too. Use **Send a test to me** (which texts `SHOP_ADMIN_PHONE`) before sending a broadcast.

There's one number, so replies go to whichever environment its incoming-message webhook points at. It points at production, which records customers' STOP and START replies. Don't point it anywhere else.

Checked on 28 October 2026: a test text sent from production was delivered, and a reply from the admin phone was stored as an inbound message (and opted nobody out).

`TWILIO_AUTH_TOKEN` is also the key the app uses to check Twilio's signatures, so anyone who reads it can forge replies to production. Keep it out of anywhere less trusted than production, such as preview environments.

The app sends a broadcast one text at a time from the queue, and Twilio queues them and sends them at the number's rate. A big list can take several minutes to go out, so schedule a drop announcement a few minutes before the drop opens.

**Klaviyo import (once, at the switch-over):** in Klaviyo, export the SMS list with the phone number, first name, postcode, SMS consent and SMS consent timestamp. Then use **Subscribers → Import from Klaviyo**. Run it while the environment has one replica: the uploaded file is stored on the replica that received it.

## Preview environments

The repository is public, so anyone can open a pull request, and a preview environment builds and runs that pull request's code with whatever variables it's given. Don't give previews staging's live credentials. A pull request's code could read them and send texts or email from the shop's accounts, or forge Twilio replies to production.

If you enable previews for pull requests into `main`, give them their own variables:

```dotenv
APP_ENV=staging
APP_DEBUG=false
CACHE_STORE=redis
SESSION_DRIVER=redis
SHOP_ADMIN_EMAIL=<the admin's email>
STRIPE_SECRET=sk_test_...
MAIL_MAILER=log
```

That's no `RESEND_API_KEY`, no `TWILIO_*` and no live keys of any kind. Mail is written to the log, texts can't be sent, and the Twilio webhooks refuse every request. If Cloud lets you, only create previews for branches pushed to this repository, not forks. **Turn off the WebSocket cluster Cloud creates by default**: the site doesn't use WebSockets.

## Checks after the first deploy

`php artisan site:check https://<host> --staging` checks every page, redirect, header, the sitemap and the live stock feed (drop `--staging` for production). For a quick look by hand:

```sh
curl -sI https://<staging-host>/ | grep -iE 'x-robots-tag|strict-transport|x-frame'
curl -sI https://<staging-host>/our-story/ | grep -iE '^(HTTP|location)'
curl -s https://<staging-host>/up
curl -sI https://<staging-host>/admin/login | grep -i x-robots-tag
```

Expect `noindex, nofollow`, the security headers, a `301` to `/our-story`, a healthy `/up`, and `noindex` on the admin.
