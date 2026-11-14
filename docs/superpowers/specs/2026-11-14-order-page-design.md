# Order page

A private page for each order: a thank-you note, pickup or delivery details (with the delivery night changeable),
a receipt, a button to email the order again, and feedback at the bottom. Its link goes in the order text, on a
short domain so the text stays at two parts.

## Approach

Each order gets a random 16-character `access_token` (about 95 bits) that the page is looked up by. Rejected: a
UUID (36 characters undoes the short domain's saving), Laravel signed URLs (a 64-character signature) and the
existing `public_id` (Meta sees it as the event ID, Stripe has it in metadata, and its last six characters are the
public `FL-` reference).

The page is plain Blade. Each action is a small invokable POST controller that redirects back with a flash message,
so it works on any phone without JavaScript. Rejected: a Livewire component; the page holds no live data.

## Data

- `orders.access_token`: string(16), unique. Filled when an order is created (`creating` hook, `Str::random(16)`);
  the migration backfills existing orders.
- `order_feedback`: `order_id` (unique, cascade on delete), `rating` (1–5), `comment` (nullable text, up to 2,000
  characters), timestamps. `OrderFeedback` model with a factory; `Order::feedback()` is a `hasOne`.

## Links

- `GET /orders/{access_token}`, named `orders.show`. Uses `PreventIndexing` and a per-IP throttle (`order-page`).
  Not response-cached; sends `Cache-Control: private, no-store`. No Meta pixel, not in the sitemap. The site's
  `strict-origin-when-cross-origin` referrer policy keeps the token off other sites. An unknown token, or an Expired
  or Failed order, is a 404.
- `shop.short_domain` (`SHOP_SHORT_DOMAIN`, e.g. `fergs.au`). When set, a `Route::domain()` group sends
  `fergs.au/{token}` to the order page with a 302 and `fergs.au/` to the homepage. When empty, links use the main
  domain.
- `Order::pageUrl()` is the full link; `Order::shortLink()` is the one texts use, without the scheme
  (`fergs.au/7Kp2Qx9mZr4TbW3n`, or `fergusonlivestock.com.au/orders/…` with no short domain).
- Setup outside the code: register `fergs.au` (needs the ABN), add it as a custom domain on the production
  environment in Laravel Cloud, and set `SHOP_SHORT_DOMAIN`.

## Text and email

- Pickup texts: `Map: fergusonlivestock.com.au/farm` becomes `Your order & map: fergs.au/…`.
- Delivery texts: `Your order: fergs.au/…` goes before "Questions?".
- A text covering several orders links the first; that order's page lists the others under "Also in this text"
  (orders sharing its `confirmation_sms_message_id`), each linked.
- A typical order's text stays at two parts (about 282 characters for a pickup).
- The confirmation email gets a "View your order" button.

## The page, top to bottom

1. Thank-you note in the `x-confirmation-letter` style, signed by Daniel & Tahlia, with the status: getting it
   ready (Paid, Cash owed), payment clearing (Processing), collected or delivered (Fulfilled).
2. Pickup or delivery.
   - Pickup: address, the drop's days and hours, and an "Open in Google Maps" button to `/farm`. No embedded map.
   - Delivery: the night and the address. When the drop has more than one delivery night, a picker offers its
     nights and "either night". A change is allowed until 12pm (Melbourne) the day before the earlier of the
     current night and the new one; an "either night" order counts as the drop's first night. Fulfilled orders
     can't change. Each change emails the owner, so a printed run sheet doesn't go stale silently.
   - Both: "Need to switch or change your address? Text us" opens an SMS to the shop with the reference filled in.
     Customers can't switch between pickup and delivery themselves, since that moves money.
3. Receipt: items with line totals, the delivery fee, free items marked "Free", total, refunds, paid date and how it
   was paid. A "Print / save as PDF" button with print styles. Labelled "Receipt", not "Tax invoice".
4. Email me my order: sends `OrderConfirmation` again to the email on file, shown masked (`s•••@gmail.com`). Never
   to a new address. Once per 10 minutes per order. Hidden when the order has no email.
5. Questions: text or call the shop.
6. Feedback: 1–5 stars (radio buttons) and an optional comment. One per order and editable; the form shows what
   they left. Each submission or edit emails the owner, with the customer's words escaped.

## Admin and MCP

- The admin order view shows the feedback, with "Open customer page" and "Copy link" actions.
- A Feedback list in the admin: rating, comment, order reference, date.
- `get-order-tool` includes the page link and the feedback.

## Not included

Regenerating a token, a "how was it?" text after pickup, and self-serve switching between pickup and delivery.

## Testing

Feature tests: the page for a valid token, 404s for an unknown token and Expired or Failed orders, the noindex
header; the short-domain redirect; changing the night (allowed, a night not on the drop, after the cutoff, pickup
and fulfilled orders refused, owner emailed); emailing the order (sent, throttled, hidden without an email);
feedback (created, edited, validated, owner emailed); the order texts' new bodies and their part counts.
