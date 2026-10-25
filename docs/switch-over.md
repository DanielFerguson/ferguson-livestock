# Switch-over runbook

Moving fergusonlivestock.com.au from the old Astro site to the Laravel app on Laravel Cloud. The main address has no www, and www redirects to it. Do it between drops, when nobody is part-way through a checkout. `docs/laravel-cloud.md` has the detail behind each step.

The switch-over is done. The old site's hosting and stock database were deleted on 25 October 2026, so there's no rolling back to it. The steps stay here as a record, and for setting up another environment the same way.

## 1. In the weeks before

- **Twilio:** create the account and add the ABN so no GST is charged. Submit the Australian regulatory bundle (business name, address, ID) and buy one 04xx number. Point its incoming messages at `/api/webhooks/twilio/inbound` (see "Twilio" in `docs/laravel-cloud.md`).
- **Resend:** verify `fergusonlivestock.com.au` by adding the DKIM and SPF records Resend shows, plus a DMARC record starting at `p=none`. Resend's SPF records sit on `send` (where bounces go), but mail is sent from the domain itself. Create a sending-access API key restricted to the domain. Send a test email to the farm's Outlook address and check it doesn't land in junk.
- **Staging:** deploy `main`, run through section 4 below, and fix anything it finds.
- **Klaviyo:** export the SMS-consented list (phone number, first name, postcode, SMS consent and its timestamp). Keep the file safe: it holds customers' details.

## 2. Two days before

- Lower the DNS TTLs for `www` and the apex to 60 seconds, so the switch takes effect quickly.
- Check there's no drop open or scheduled to open during the switch window.

## 3. Create the production environment

In Laravel Cloud, add a `production` environment to the application, tracking `main`, in Sydney, with its own Serverless Postgres (pooler host), Valkey and managed queue. Use the settings from the staging table in `docs/laravel-cloud.md`, with these changes, and keep the scheduler on.

Confirm each setting by eye in the environment's settings (the values are secrets, so this list only names them):

| Setting | Confirm |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://fergusonlivestock.com.au` |
| `SHOP_URL` | the same address |
| `CACHE_STORE`, `SESSION_DRIVER` | `redis` |
| `QUEUE_CONNECTION` | `cloud` (set by Cloud) |
| `MAIL_MAILER` | `resend` |
| `RESEND_API_KEY` | present |
| `MAIL_FROM_ADDRESS` | an address on `fergusonlivestock.com.au` itself, not on `send.` |
| `STRIPE_SECRET` | the **live** secret key, copied from Stripe's live mode (the dashboard's test-mode toggle off) |
| `STRIPE_WEBHOOK_SECRET` | the signing secret of the **live** endpoint created in section 5 |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` | the live Twilio account's, not its test credentials |
| `TWILIO_FROM_NUMBER` | the 04xx number, written `+614…` |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | both present, from the Turnstile widget that lists the www and apex hostnames |
| `SHOP_ADMIN_EMAIL`, `SHOP_ADMIN_PHONE` | your email and mobile |

Deploy, then run once from the environment's Commands tab:

```sh
php artisan db:seed --class=ProductSeeder --force
php artisan make:filament-user --name="Daniel Ferguson" --email=<SHOP_ADMIN_EMAIL> --password=<generated password>
```

Sign in at `https://<production Cloud host>/admin`, set up the authenticator app and keep the recovery codes.

## 4. Smoke test on staging (Stripe test mode)

With staging's Stripe webhook endpoint pointed at the staging address:

1. `php artisan site:check https://<staging-host> --staging` passes.
2. Set up a drop that opens in a few minutes with test-mode prices, and confirm the pre-flight email arrives and says it's ready.
3. When it opens, place an order with one of Stripe's test cards. Check the confirmation page, both emails, and the order in the admin. On staging both emails arrive at the farm's address, whatever email you typed at Stripe.
4. Try a card Stripe's test docs list as declined, and confirm nothing is held afterwards.
5. Start a checkout and press Stripe's back link. The order page should say it was cancelled, with the stock back on sale.
6. Start a checkout and leave it. Force the payment page to close with the Stripe CLI (`stripe checkout sessions expire <session id>`) and confirm the stock comes back within a minute.
7. If a bank debit payment method is turned on, place an order with it and confirm the order shows "Payment clearing" until Stripe settles it.
8. Refund an order in Stripe and confirm the admin shows the refund (stock doesn't come back; that's intended).
9. Run the load test (`scripts/load/drop-day.js`, see `docs/laravel-cloud.md`) and a Lighthouse mobile check on `/`, `/beef-boxes` and `/order`.
10. Join the wait list from your phone and check the admin records your phone's IP address, and not an address sent in a made-up `X-Forwarded-For` header (both checks are under "Drop day" in `docs/laravel-cloud.md`).

## 5. Stripe live mode

In Stripe's live mode:

1. Create a webhook endpoint for `https://fergusonlivestock.com.au/api/webhooks/stripe`, on API version `2026-08-26.dahlia`, with the five events listed in `docs/laravel-cloud.md`. **Disable it** until the switch in section 7: until then the old site's endpoint handles live payments.
2. Copy its signing secret into production's `STRIPE_WEBHOOK_SECRET` and redeploy.
3. Create or copy the live one-time AUD prices for the first drop. You'll paste them into the drop after the switch.

## 6. Domains

In the production environment's Domains settings, add `fergusonlivestock.com.au` with **Redirect from www** turned on, so www redirects to it. Pre-verify it so the SSL certificates are issued before any traffic moves.

## 7. Switch (between drops)

1. Confirm there are no open checkouts on the old site: no drop live, and nothing in Stripe's live payments from the last half hour still in progress.
2. Deploy `main` to production and run `php artisan site:check https://<production Cloud host>`. Only the canonical and www checks may fail at this point, because the Cloud host isn't the main address yet.
3. Point DNS for `www` and the apex at Laravel Cloud, using the records its Domains page shows.
4. In Stripe's live mode, **enable** the new webhook endpoint and **disable** the old site's. Both use the same address, and Laravel only accepts events signed with the new endpoint's secret, so the old endpoint must not stay on.
5. In Twilio, change the number's **A message comes in** webhook from the staging address to `https://fergusonlivestock.com.au/api/webhooks/twilio/inbound`. Until you do, customers' STOP and START replies go to staging and production never records them.

## 8. Check it

1. `php artisan site:check https://fergusonlivestock.com.au` passes every check, including the www redirect. Run it again an hour later, once DNS has settled everywhere.
2. In Google Search Console, submit `https://fergusonlivestock.com.au/sitemap-index.xml` again.
3. Import the Klaviyo export in the admin (Subscribers → Import from Klaviyo) while production has one replica, and check the count matches Klaviyo's.
4. Create the first real drop with the live prices. Confirm its pre-flight check passes and the pre-flight email arrives.
5. Send yourself a test broadcast and reply to it from your phone. The reply should appear under the admin's replies, which shows the Twilio webhook reaches production. Then announce the drop.

## 9. After the first Laravel drop

- Cancel Klaviyo once the first broadcast from the admin has gone out.
- Raise the DNS TTLs back to their usual value.
