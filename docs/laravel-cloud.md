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
| Deploy commands | `php artisan migrate --force` |

Resources to attach:

- **Laravel Serverless Postgres 18**, 0.25–1 CU, sleep allowed. Point the app at the **pooler** host (the host name with `-pooler`) if Cloud injects the direct host.
- **Laravel Valkey**, Flex 250 MB.
- **Managed queue** (Flex). Cloud sets `QUEUE_CONNECTION=cloud`; the app already requires `aws/aws-sdk-php`. Don't use queue clusters — they shut down on 2026-09-30.

Environment variables to set yourself (Cloud injects the database, Valkey and queue credentials):

```dotenv
APP_ENV=staging
APP_DEBUG=false
CACHE_STORE=redis
SESSION_DRIVER=redis
```

`APP_ENV=staging` matters: every non-production response is sent with `X-Robots-Tag: noindex, nofollow`, because staging pages carry the production canonical URLs.

## Preview environments

Enable preview environments for pull requests into `laravel-migration`. Give them the same variables as staging, and **turn off the WebSocket cluster Cloud creates by default** — the site doesn't use WebSockets.

## Checks after the first deploy

```sh
curl -sI https://<staging-host>/ | grep -iE 'x-robots-tag|strict-transport|x-frame'
curl -sI https://<staging-host>/our-story/ | grep -iE '^(HTTP|location)'
curl -s https://<staging-host>/up
```

Expect `noindex, nofollow`, the security headers, a `301` to `/our-story`, and a healthy `/up`.
