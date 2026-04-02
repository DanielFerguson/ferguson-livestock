# Beef Box Stock-Tracked Checkout

## Context

Ferguson Livestock sells pasture-raised beef boxes in periodic drops (~every few months). Previous drops used simple Stripe payment links with no inventory tracking, requiring manual monitoring and code changes to mark items as sold out. This led to a risk of overselling and a poor operational workflow.

This design adds real-time stock tracking and a proper checkout flow so that:
- Stock is accurately tracked and customers can't oversell
- Optional extras (mince, bones) are available as add-ons with their own stock
- The site automatically transitions between "ordering" and "waitlist" modes
- Delivery details are collected at checkout

## Products & Pricing

| Product | Price | Stock Unit | Notes |
|---------|-------|-----------|-------|
| 5kg Beef Box | $150 | 1 unit of `beef-box-5kg` | Base product |
| 10kg Beef Box | $240 | 2 units of `beef-box-5kg` | Discounted bundle, consumes 2x 5kg stock |
| 500g Beef Mince | $12 | 1 unit of `beef-mince-500g` | Add-on only (requires a box) |
| 2kg Beef Bones | $10 | 1 unit of `beef-bones-2kg` | Add-on only (requires a box) |

- **Extras are add-ons only** — customers must select a box to unlock extras
- **No per-order limit on extras** — quantity limited only by available stock
- **Stock counts are shown** on the ordering page (e.g. "5 left")

## Architecture

### Tech Stack Additions

- **Stripe Checkout Sessions** — payment processing with shipping address collection
- **Stripe Webhooks** — payment confirmation and session expiry handling
- **Upstash Redis (via `@upstash/redis`)** — stock counts, reservations, drop state. Vercel KV is built on Upstash; using the Upstash SDK directly is more future-proof and gives access to Lua scripting for atomic operations. Free tier (10k commands/day) is more than sufficient.

### Stock Configuration

Stock is defined in `src/config/products.ts` — edited before each drop and deployed:

```ts
export const products = {
  "beef-box-5kg": {
    name: "5kg Beef Box",
    price: 15000,
    stripePriceId: "price_xxx",
    initialStock: 15,
    type: "box" as const,
  },
  "beef-mince-500g": {
    name: "500g Beef Mince",
    price: 1200,
    stripePriceId: "price_yyy",
    initialStock: 10,
    type: "extra" as const,
  },
  "beef-bones-2kg": {
    name: "2kg Beef Bones",
    price: 1000,
    stripePriceId: "price_zzz",
    initialStock: 8,
    type: "extra" as const,
  },
} as const;

// 10kg box configuration — uses beef-box-5kg stock
export const bundles = {
  "beef-box-10kg": {
    name: "10kg Beef Box",
    stripePriceId: "price_aaa",
    stockProduct: "beef-box-5kg",
    stockQuantity: 2, // consumes 2 units
  },
} as const;
```

### Vercel KV Schema

| Key | Type | Purpose |
|-----|------|---------|
| `stock:{productId}` | number | Current available stock |
| `reservation:{stripeSessionId}` | JSON `{ items: [{productId, qty}], createdAt }` | What a checkout session reserved |
| `drop:active` | boolean | Whether ordering is enabled |

Stock operations use atomic `DECRBY`/`INCRBY` to prevent race conditions.

### API Routes

All routes are in `src/pages/api/`:

#### `GET /api/stock`
Returns current stock counts for all products and the drop active state. Called on page load.

Response:
```json
{
  "active": true,
  "stock": {
    "beef-box-5kg": 12,
    "beef-mince-500g": 8,
    "beef-bones-2kg": 5
  }
}
```

#### `POST /api/checkout`
Validates the order, reserves stock, creates a Stripe Checkout Session.

Request:
```json
{
  "box": "5kg" | "10kg",
  "extras": [
    { "productId": "beef-mince-500g", "quantity": 2 },
    { "productId": "beef-bones-2kg", "quantity": 1 }
  ]
}
```

Flow:
1. Validate box selection and extras
2. Calculate stock requirements (10kg = 2x 5kg units)
3. Atomically check and reserve stock in KV (decrement counts)
4. Create Stripe Checkout Session (wrapped in try-catch):
   - Line items (box + extras with correct Stripe Price IDs)
   - Shipping address collection enabled
   - Phone number collection enabled
   - `metadata`: include reserved items as JSON (for refund lookups)
   - Success URL: `/order-confirmed?session_id={CHECKOUT_SESSION_ID}`
   - Cancel URL: `/api/cancel-checkout?session_id={CHECKOUT_SESSION_ID}` (releases stock immediately)
   - Session expiry: 30 minutes
5. Store reservation data in KV keyed by Stripe session ID
6. Return `{ url: checkoutSession.url }`

**Error handling:** If Stripe session creation fails (step 4), immediately release the reserved stock (INCRBY rollback) and return 500. This prevents permanent stock loss from transient Stripe errors.

If stock is insufficient at step 3, return 409 with current stock counts so the UI can update.

#### `POST /api/webhooks/stripe`
Handles Stripe webhook events. Verifies webhook signature.

- **`checkout.session.completed`**: Marks the reservation as confirmed (deletes reservation key since stock is already decremented).
- **`checkout.session.expired`**: Reads the reservation, increments stock back for each reserved item, deletes the reservation key. Must be **idempotent** — if reservation key is already gone (e.g. cancelled via `/api/cancel-checkout`), silently succeed.
- **`charge.refunded`**: Reads the reserved items from the Stripe session metadata, restores stock (INCRBY for each item). This handles refunds issued via Stripe Dashboard.

#### `GET /api/cancel-checkout?session_id={id}`
Handles customers clicking "Back" on Stripe Checkout. Immediately releases reserved stock instead of waiting for the 30-minute session expiry.

Flow:
1. Read reservation from KV using session ID
2. If no reservation found, redirect to `/order` (already cancelled or completed)
3. Verify with Stripe that the session was NOT paid (guard against race with payment completion)
4. If unpaid: INCRBY stock for each reserved item, delete reservation key
5. Redirect to `/order`

This is idempotent — calling it multiple times for the same session is safe.

#### `POST /api/seed-stock`
Seeds KV with initial stock from the config. Protected by a secret header (`x-seed-secret`). Only sets stock values if they don't already exist (to avoid resetting mid-drop). Also sets `drop:active` to `true`.

Called manually or via a deploy hook when starting a new drop.

## Frontend

### Page Structure

- **Homepage (`/`)** — Marketing page. Hero section shows "Order Now" CTA linking to `/order` when a drop is active, or the waitlist form when inactive.
- **`/order`** — New dedicated ordering page with product selection and checkout.
- **`/order-confirmed`** — New confirmation page shown after successful payment. Verifies the Stripe session is actually paid before rendering (prevents URL spoofing). Redirects to `/order` if session is invalid or unpaid.

### Ordering Page (`/order`)

**When drop is active:**
1. Fetch stock from `GET /api/stock` on page load
2. Display box selection (5kg / 10kg) with prices and stock remaining
3. Once a box is selected, show available extras with quantity selectors and stock remaining
4. Running order summary with line items and total
5. "Order Now" button submits to `POST /api/checkout` and redirects to Stripe
6. Items at 0 stock show "Sold Out" badge and are disabled
7. When all boxes are sold out, the page switches to show the waitlist form

**When drop is inactive:**
- Redirect to homepage or show waitlist form

**Tech:** Astro page with vanilla JS for interactivity (quantity selectors, running total, fetch calls). No framework needed — keeps the site lightweight.

### Homepage Hero Modification

The `Hero.astro` component needs to:
- Fetch drop status from `/api/stock` (just the `active` flag)
- When active: show an "Order Now" button linking to `/order` instead of pricing cards
- When inactive: show the existing waitlist form (current behavior)

### Design Language

All new UI follows the existing palette and typography:
- Colors: forest (`#2C4A2E`), sage, cream, warm tones
- Headings: Cormorant Garamond
- Body: Source Sans 3
- Accents: Caveat (handwritten feel)

## Order Flow (End-to-End)

```
Customer visits /order
        |
   GET /api/stock → renders products with stock counts
        |
   Selects box + extras
        |
   Clicks "Order Now"
        |
   POST /api/checkout
        |
   ┌─ Stock available? ─── No → 409, UI shows updated stock
   │
   Yes
   ├─ Reserve stock (atomic DECRBY in KV)
   ├─ Create Stripe Checkout Session (30 min expiry)
   │   └─ On Stripe API failure → INCRBY rollback, return 500
   ├─ Store reservation keyed by session ID
   └─ Return checkout URL → redirect customer
        |
   Customer on Stripe Checkout (enters payment + shipping)
        |
   ┌─ Payment succeeds ────────────────────────────────┐
   │  webhook: checkout.session.completed               │
   │  → Delete reservation (stock stays decremented)    │
   │  → Customer redirected to /order-confirmed         │
   │  → Page verifies session is paid before rendering  │
   │                                                    │
   ├─ Customer clicks "Back" ──────────────────────────┐
   │  → Redirected to GET /api/cancel-checkout          │
   │  → Verify session not paid                         │
   │  → INCRBY stock for each item                      │
   │  → Delete reservation                              │
   │  → Redirect to /order (stock immediately available)│
   │                                                    │
   └─ Session expires (no payment, no cancel) ─────────┐
      webhook: checkout.session.expired                 │
      → Read reservation (if still exists)              │
      → INCRBY stock for each item                      │
      → Delete reservation                              │
      → Stock released back to pool                     │

   ┌─ Refund issued (via Stripe Dashboard) ────────────┐
   │  webhook: charge.refunded                          │
   │  → Read reserved items from session metadata       │
   │  → INCRBY stock for each item                      │
   │  → Stock restored                                  │
```

## Environment Variables

| Variable | Purpose |
|----------|---------|
| `STRIPE_SECRET_KEY` | Stripe API key |
| `STRIPE_WEBHOOK_SECRET` | Webhook signature verification |
| `UPSTASH_REDIS_REST_URL` | Upstash Redis connection URL |
| `UPSTASH_REDIS_REST_TOKEN` | Upstash Redis auth token |
| `STOCK_SEED_SECRET` | Protects the seed endpoint |

## Files to Create/Modify

### New Files
- `src/config/products.ts` — Product definitions and stock config
- `src/pages/order.astro` — Ordering page
- `src/pages/order-confirmed.astro` — Post-purchase confirmation page (with Stripe session verification)
- `src/pages/api/stock.ts` — Stock query endpoint
- `src/pages/api/checkout.ts` — Checkout session creation with try-catch rollback
- `src/pages/api/cancel-checkout.ts` — Immediate stock release on checkout cancellation
- `src/pages/api/webhooks/stripe.ts` — Stripe webhook handler (completed, expired, refunded)
- `src/pages/api/seed-stock.ts` — Stock seeding endpoint
- `src/lib/kv.ts` — Upstash Redis client wrapper with stock operations

### Modified Files
- `src/components/Hero.astro` — Conditional rendering based on drop active state
- `package.json` — Add `stripe` and `@upstash/redis` dependencies
- `.env.example` — Add new environment variables

## Verification Plan

1. **Unit test stock operations** — Verify atomic decrement/increment, verify insufficient stock is rejected
2. **Stripe test mode** — Use Stripe test keys and test card numbers
3. **Local webhook testing** — `stripe listen --forward-to localhost:4321/api/webhooks/stripe`
4. **Stock reservation flow** — Set stock to 1, checkout, verify 0 remaining, verify second checkout rejected
5. **Reservation expiry** — Start checkout, let it expire, verify stock restored
6. **Cancel checkout** — Start checkout, click "Back", verify stock is immediately released (not waiting 30 min)
7. **Stripe API failure rollback** — Simulate Stripe error, verify stock is restored and user gets error message
8. **10kg box** — Order 10kg, verify 2 units of 5kg consumed
9. **Extras add-on only** — Verify extras can't be checked out without a box
10. **Sold out transition** — Deplete all box stock, verify page switches to waitlist
11. **Concurrent checkout** — Two simultaneous requests for last item, verify only one succeeds
12. **Order confirmation spoofing** — Visit `/order-confirmed?session_id=fake`, verify redirect to `/order`
13. **Refund flow** — Complete an order, issue refund in Stripe Dashboard, verify stock is restored
14. **Idempotent webhooks** — Fire same webhook event twice, verify stock isn't double-incremented
