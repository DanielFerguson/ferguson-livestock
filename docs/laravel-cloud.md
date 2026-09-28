# Laravel Cloud setup

How the Laravel app is hosted. The `production` environment is only created at the switch-over (see the migration plan); until then `main` stays live on Vercel.

## Vercel during the migration

`vercel.json` on this branch only sets `git.deploymentEnabled: false`, so Vercel doesn't try to build the Laravel app as the old Astro project. `main` keeps deploying from Vercel until the switch-over merge. After that merge Vercel stops deploying, which is intended. Delete `vercel.json` once the Vercel project is decommissioned.

## Application

- Create one Laravel Cloud application from `DanielFerguson/ferguson-livestock`, in **Asia Pacific (Sydney)**. Keep every resource in the same region.
- Plan: **Growth** (Starter only allows one replica).

## `staging` environment

Tracks the `laravel-migration` branch and uses Stripe **test** keys once checkout exists.

| Setting | Value |
| --- | --- |
| Branch | `laravel-migration` |
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

## Preview environments

Enable preview environments for pull requests into `laravel-migration`. Give them the same variables as staging, and **turn off the WebSocket cluster Cloud creates by default** — the site doesn't use WebSockets.

## Checks after the first deploy

```sh
curl -sI https://<staging-host>/ | grep -iE 'x-robots-tag|strict-transport|x-frame'
curl -sI https://<staging-host>/our-story/ | grep -iE '^(HTTP|location)'
curl -s https://<staging-host>/up
curl -sI https://<staging-host>/admin/login | grep -i x-robots-tag
```

Expect `noindex, nofollow`, the security headers, a `301` to `/our-story`, a healthy `/up`, and `noindex` on the admin.
