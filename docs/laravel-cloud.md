# Laravel Cloud setup

How the Laravel app is hosted. The `production` environment is only created at the switch-over (see the migration plan). Until then the live site is the Astro version's last Vercel deployment.

## Vercel during the migration

`main` holds the Laravel app. `vercel.json` only sets `git.deploymentEnabled: false`, so Vercel doesn't try to build it as the old Astro project, and www.fergusonlivestock.com.au keeps serving the last Astro deployment (built from `0dce200`). To change the live site before the switch-over, branch from `0dce200` and deploy that branch with `vercel --prod`. Delete `vercel.json` once the Vercel project is decommissioned.

## Application

- Create one Laravel Cloud application from `DanielFerguson/ferguson-livestock`, in **Asia Pacific (Sydney)**. Keep every resource in the same region.
- Plan: **Growth** (Starter only allows one replica).

## `staging` environment

Tracks `main` and uses Stripe **test** keys once checkout exists.

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

The scheduler must stay on: every minute it runs `drops:preflight`, which checks each published drop 10 minutes before it opens, then emails the admin and adds a notification in the admin.

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

```sh
curl -sI https://<staging-host>/ | grep -iE 'x-robots-tag|strict-transport|x-frame'
curl -sI https://<staging-host>/our-story/ | grep -iE '^(HTTP|location)'
curl -s https://<staging-host>/up
curl -sI https://<staging-host>/admin/login | grep -i x-robots-tag
```

Expect `noindex, nofollow`, the security headers, a `301` to `/our-story`, a healthy `/up`, and `noindex` on the admin.
