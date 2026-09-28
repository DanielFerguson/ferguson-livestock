# Laravel Cloud setup

How the Laravel app is hosted. The `production` environment is only created at the switch-over: follow `docs/switch-over.md`. Until then the live site is the Astro version's last Vercel deployment.

## Vercel during the migration

`main` holds the Laravel app. `vercel.json` only sets `git.deploymentEnabled: false`, so Vercel doesn't try to build it as the old Astro project, and www.fergusonlivestock.com.au keeps serving the last Astro deployment (built from `0dce200`). To change the live site before the switch-over, branch from `0dce200` and deploy that branch with `vercel --prod`. Delete `vercel.json` once the Vercel project is decommissioned.

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
MAIL_FROM_ADDRESS=orders@send.fergusonlivestock.com.au
SHOP_ADMIN_PHONE=+614...
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM_NUMBER=+614...
```

The response cache uses `CACHE_STORE`, so every replica shares the cached marketing pages. The deploy command clears it, so a deploy never serves stale pages.

`APP_URL` must be the environment's own address: the drop pre-flight check looks for a Stripe webhook endpoint at `APP_URL/api/webhooks/stripe`. `SHOP_URL` stays the canonical www address everywhere.

Resend sends from the verified `send.fergusonlivestock.com.au` subdomain. Until the domain is verified, pre-flight emails fail and the result is only shown in the admin.

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

The scheduler must stay on. Every minute it runs:

- `drops:preflight`, which checks each published drop 10 minutes before it opens, then emails the admin and adds a notification in the admin
- `orders:sweep`, which settles checkouts whose payment page has closed, so a lost webhook never leaves stock held
- `sms:send-scheduled`, which starts scheduled broadcasts

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

## Drop day

Every page polls `/api/drop` for live stock: every couple of seconds while a drop is live or about to open, every 30 seconds while one is scheduled, and not at all otherwise or in background tabs. The feed is built at most once a second and rebuilt straight after any stock change, and it may be cached by Cloudflare for a second too. `SHOP_DROP_POLL_MS` changes the polling interval (default 2000).

Before each drop:

1. Raise the minimum replicas (Growth plan autoscaling isn't scheduled). The pre-flight email reminds you.
2. Watch the dashboard's current-drop table, which refreshes every five seconds.

**Load test (staging):** `k6 run -e BASE_URL=https://<staging-host> scripts/load/drop-day.js` runs 500 visitors polling the feed for five minutes, with some loading the order page. It fails if more than 1% of requests fail or the feed's 95th percentile goes over 300 ms. It doesn't start checkouts (see the script's notes).

**Check visitors' IP addresses once:** checkout limits tries per IP address, so the app must see each visitor's own address, not a proxy's. After deploying staging, join the wait list from your phone, then check the subscriber's IP address in the admin matches your phone's. If it shows the same address for everyone, tell Laravel which proxies to trust before the first drop.

## Twilio (wait-list texts)

Texts go out from one Australian 04xx number, so people can reply. In the Twilio console:

1. Under the number's **Messaging configuration**, set **A message comes in** to a webhook, `https://<host>/api/webhooks/twilio/inbound`, method **POST**. Delivery reports need no setup: each text asks Twilio to report to `/api/webhooks/twilio/status`.
2. Keep Twilio's **Advanced Opt-Out** on. It blocks anyone who replies STOP, as a safety net behind the app's own opt-outs.

Both webhooks check Twilio's signature against `TWILIO_AUTH_TOKEN` and the URL Twilio called, so `APP_URL` must be the address in the console. Unsigned requests get a 403.

Twilio's test credentials don't send texts or call webhooks, so staging uses the real account. Keep staging's subscriber list to your own number, and use **Send a test to me** (which texts `SHOP_ADMIN_PHONE`) before sending a broadcast.

The app sends a broadcast one text at a time from the queue, and Twilio queues them and sends them at the number's rate. A big list can take several minutes to go out, so schedule a drop announcement a few minutes before the drop opens.

**Klaviyo import (once, at the switch-over):** in Klaviyo, export the SMS list with the phone number, first name, postcode, SMS consent and SMS consent timestamp. Then use **Subscribers → Import from Klaviyo**. Run it while the environment has one replica: the uploaded file is stored on the replica that received it.

## Preview environments

Enable preview environments for pull requests into `main`. Give them the same variables as staging, and **turn off the WebSocket cluster Cloud creates by default** — the site doesn't use WebSockets.

## Checks after the first deploy

`php artisan site:check https://<host> --staging` checks every page, redirect, header, the sitemap and the live stock feed (drop `--staging` for production). For a quick look by hand:

```sh
curl -sI https://<staging-host>/ | grep -iE 'x-robots-tag|strict-transport|x-frame'
curl -sI https://<staging-host>/our-story/ | grep -iE '^(HTTP|location)'
curl -s https://<staging-host>/up
curl -sI https://<staging-host>/admin/login | grep -i x-robots-tag
```

Expect `noindex, nofollow`, the security headers, a `301` to `/our-story`, a healthy `/up`, and `noindex` on the admin.
