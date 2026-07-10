# Optional Extras Expansion & Extras-Only Ordering

**Date:** 2026-04-03

## Context

Ferguson Livestock sells beef boxes via a drop model (limited stock, released in batches). Currently, when beef boxes sell out, the entire order form disappears and customers see a waitlist. The business wants to:

1. Add 4 new optional extras (Sausage Packs, Rump Steak, Porterhouse, Diced Chuck)
2. Allow customers to purchase extras even after beef boxes sell out

This keeps revenue flowing between drops and gives customers more product options.

## New Products

| Product ID | Name | Price | Initial Stock | Type |
|---|---|---|---|---|
| `sausage-packs-6` | Sausage Packs (6) | $12 (1200c) | 20 | extra |
| `rump-steak-2` | Rump Steak (2) | $20 (2000c) | 10 | extra |
| `porterhouse` | Porterhouse | $18 (1800c) | 10 | extra |
| `diced-chuck` | Diced Chuck | $13 (1300c) | 15 | extra |

Existing extras (`beef-mince-500g` @ $12, `beef-bones-2kg` @ $10) remain unchanged.

**Prerequisite:** Stripe Price IDs must be created for each new product before implementation.

## Design

### Dual-Mode Order Form

The `/order` page operates in two modes on the same URL:

**Normal mode** (boxes in stock): Current 4-step flow unchanged.
- Step 1: Choose box
- Step 2: Optional extras (all 6, including new ones)
- Step 3: Delivery method
- Step 4: Summary & checkout

**Extras-only mode** (boxes sold out, extras still in stock):
- Box selection step hidden entirely
- Step 1: Extras (always enabled, requires at least 1 selected)
- Step 2: Delivery method (enabled once any extra is selected)
- Step 3: Summary & checkout
- Hero badge: "Beef Boxes Sold Out — Extras Still Available"

**Full sold-out** (boxes AND all extras out of stock): Current "All Sold Out" + waitlist, unchanged.

### Checkout API Changes

**File:** `src/pages/api/checkout.ts`

Make `box` optional:
```typescript
interface CheckoutRequest {
  box?: "5kg" | "10kg" | null;
  extras: { productId: string; quantity: number }[];
  deliveryMethod: "delivery" | "pickup";
}
```

Logic changes:
- If `box` is provided: validate, resolve stock, add box line item (current behavior)
- If `box` is absent/null: skip box validation, stock reservation, and line item
- New validation: if no box, require at least one extra with quantity > 0
- Stock reservation, Stripe session creation, and reservation saving remain identical

### Frontend Changes

**File:** `src/pages/order.astro`

HTML:
- Add 4 new `.extra-item` rows using the existing pattern (product ID, price, name, qty buttons)

JavaScript:
- Add new products to `EXTRA_PRICES` and `EXTRA_NAMES` maps
- New state variable: `extrasOnlyMode: boolean`
- Modify `fetchStock()`:
  - When `boxStock <= 0`: check if any extras have stock > 0
  - If yes: set `extrasOnlyMode = true`, hide box step, show extras-only form
  - If no: show "All Sold Out" + waitlist (current behavior)
- Modify `updateSectionStates()`:
  - In extras-only mode: extras section always enabled, delivery/summary enabled when any extra qty > 0
- Modify `updateSummary()`:
  - Handle `selectedBox === null` (no box line item)
- Modify checkout handler:
  - Send `box: null` when in extras-only mode
  - `checkoutBtn.disabled` logic: in extras-only mode, require any extra selected + delivery method
- Modify step numbers: when in extras-only mode, renumber visible steps to 1/2/3

### Product Config

**File:** `src/config/products.ts`

Add 4 new entries to the `products` record with `type: "extra"`.

### No Changes Required

- `src/pages/api/stock.ts` — already returns all product stock
- `src/lib/kv.ts` — stock operations are product-agnostic
- `src/pages/api/seed-stock.ts` — seeds from `products` config automatically
- `src/pages/api/webhooks/stripe.ts` — handles reservations generically
- `src/pages/api/cancel-checkout.ts` — releases stock generically

## Files to Modify

1. `src/config/products.ts` — add 4 new extra products
2. `src/pages/api/checkout.ts` — make box optional, add extras-only validation
3. `src/pages/order.astro` — add product HTML rows, extras-only mode logic, update JS maps

## Verification

1. Create Stripe Price IDs for 4 new products
2. Seed stock (`POST /api/seed-stock`), verify new products appear
3. **Normal mode test:** Select box + new extras + delivery → checkout → verify Stripe session has correct line items
4. **Extras-only test:** Set `stock:beef-box-5kg` to 0 in KV → refresh `/order` → verify extras-only form shows → checkout with extras only → verify Stripe session
5. **Full sold-out test:** Set all stock to 0 → verify "All Sold Out" + waitlist appears
6. **Stock enforcement:** Try to add more extras than available stock → verify + button disables
7. **409 handling:** Simulate stock race condition → verify UI updates stock counts
