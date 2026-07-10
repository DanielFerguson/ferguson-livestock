# Optional Extras Expansion & Extras-Only Ordering — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 4 new optional extras and allow extras-only checkout when beef boxes are sold out.

**Architecture:** Extend the existing `products` config with 4 new extras (placeholder Stripe Price IDs). Modify checkout API to accept optional `box`. Add extras-only mode to the order page that activates when boxes are out of stock but extras remain.

**Tech Stack:** Astro, TypeScript, Upstash Redis, Stripe

**Spec:** `docs/superpowers/specs/2026-04-03-optional-extras-expansion-design.md`

---

### Task 1: Add new products to config

**Files:**
- Modify: `src/config/products.ts`

- [ ] **Step 1: Add 4 new extra products to the `products` record**

Add these entries after the `"beef-bones-2kg"` entry and before `"delivery-fee"`:

```typescript
  "sausage-packs-6": {
    name: "Sausage Packs (6)",
    description: "Six premium beef sausages.",
    price: 1200,
    stripePriceId: "PLACEHOLDER_SAUSAGE_PACKS_6",
    initialStock: 20,
    type: "extra",
  },
  "rump-steak-2": {
    name: "Rump Steak (2)",
    description: "Two grass-fed rump steaks.",
    price: 2000,
    stripePriceId: "PLACEHOLDER_RUMP_STEAK_2",
    initialStock: 10,
    type: "extra",
  },
  "porterhouse": {
    name: "Porterhouse",
    description: "Premium porterhouse steak.",
    price: 1800,
    stripePriceId: "PLACEHOLDER_PORTERHOUSE",
    initialStock: 10,
    type: "extra",
  },
  "diced-chuck": {
    name: "Diced Chuck",
    description: "Diced chuck steak, great for slow cooking.",
    price: 1300,
    stripePriceId: "PLACEHOLDER_DICED_CHUCK",
    initialStock: 15,
    type: "extra",
  },
```

- [ ] **Step 2: Verify the build compiles**

Run: `npx astro check` (or `npm run build` if `astro check` is not configured)

Expected: No type errors.

- [ ] **Step 3: Commit**

```bash
git add src/config/products.ts
git commit -m "feat: add 4 new optional extras to product config"
```

---

### Task 2: Make box optional in checkout API

**Files:**
- Modify: `src/pages/api/checkout.ts`

- [ ] **Step 1: Update `CheckoutRequest` interface to make `box` optional**

Change the interface at line 14 from:

```typescript
interface CheckoutRequest {
  box: "5kg" | "10kg";
  extras: { productId: string; quantity: number }[];
  deliveryMethod: "delivery" | "pickup";
}
```

to:

```typescript
interface CheckoutRequest {
  box?: "5kg" | "10kg" | null;
  extras: { productId: string; quantity: number }[];
  deliveryMethod: "delivery" | "pickup";
}
```

- [ ] **Step 2: Replace box validation with conditional logic**

Replace the box validation block (lines 24-30):

```typescript
    // Validate box selection
    if (data.box !== "5kg" && data.box !== "10kg") {
      return new Response(
        JSON.stringify({ error: "Invalid box selection" }),
        { status: 400, headers: { "Content-Type": "application/json" } }
      );
    }
```

with:

```typescript
    // Validate box selection (optional — null/undefined means extras-only order)
    const hasBox = data.box === "5kg" || data.box === "10kg";
    if (data.box != null && !hasBox) {
      return new Response(
        JSON.stringify({ error: "Invalid box selection" }),
        { status: 400, headers: { "Content-Type": "application/json" } }
      );
    }
```

- [ ] **Step 3: Add validation requiring at least one extra when no box**

After the extras validation loop (after line 55), add:

```typescript
    // If no box, require at least one extra
    if (!hasBox && (!data.extras || data.extras.length === 0)) {
      return new Response(
        JSON.stringify({ error: "Please select at least one item" }),
        { status: 400, headers: { "Content-Type": "application/json" } }
      );
    }
```

- [ ] **Step 4: Make stock reservation conditional on box**

Replace the stock items construction (line 67):

```typescript
    const stockItems: CartItem[] = [resolveBoxStock(data.box)];
```

with:

```typescript
    const stockItems: CartItem[] = [];
    if (hasBox) {
      stockItems.push(resolveBoxStock(data.box as "5kg" | "10kg"));
    }
```

- [ ] **Step 5: Make box line item conditional**

Replace the box line item push (lines 87-88):

```typescript
    // Box
    lineItems.push({ price: getBoxPriceId(data.box), quantity: 1 });
```

with:

```typescript
    // Box (only if selected)
    if (hasBox) {
      lineItems.push({ price: getBoxPriceId(data.box as "5kg" | "10kg"), quantity: 1 });
    }
```

- [ ] **Step 6: Verify the build compiles**

Run: `npx astro check` (or `npm run build`)

Expected: No type errors.

- [ ] **Step 7: Commit**

```bash
git add src/pages/api/checkout.ts
git commit -m "feat: make box optional in checkout API for extras-only orders"
```

---

### Task 3: Add new extras HTML to order page

**Files:**
- Modify: `src/pages/order.astro`

- [ ] **Step 1: Add 4 new extra item rows after the beef bones row**

After the closing `</div>` of the beef bones `.extra-item` (the `</div>` at line 183), add these 4 new items inside the `#extras-list` div:

```html
                            <!-- Sausage Packs (6) -->
                            <div class="extra-item flex items-center justify-between gap-4 bg-cream rounded-xl p-5" data-product="sausage-packs-6" data-price="1200">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <h3 class="font-semibold text-forest">Sausage Packs (6)</h3>
                                        <span class="text-sm font-bold text-forest">$12</span>
                                    </div>
                                    <p class="text-sm text-gray-500">Six premium beef sausages</p>
                                    <span class="extra-stock text-xs font-medium text-sage mt-1 inline-block"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="qty-btn qty-minus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                        disabled
                                    >
                                        &minus;
                                    </button>
                                    <span class="qty-display w-8 text-center font-bold text-forest">0</span>
                                    <button
                                        type="button"
                                        class="qty-btn qty-plus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>

                            <!-- Rump Steak (2) -->
                            <div class="extra-item flex items-center justify-between gap-4 bg-cream rounded-xl p-5" data-product="rump-steak-2" data-price="2000">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <h3 class="font-semibold text-forest">Rump Steak (2)</h3>
                                        <span class="text-sm font-bold text-forest">$20</span>
                                    </div>
                                    <p class="text-sm text-gray-500">Two grass-fed rump steaks</p>
                                    <span class="extra-stock text-xs font-medium text-sage mt-1 inline-block"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="qty-btn qty-minus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                        disabled
                                    >
                                        &minus;
                                    </button>
                                    <span class="qty-display w-8 text-center font-bold text-forest">0</span>
                                    <button
                                        type="button"
                                        class="qty-btn qty-plus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>

                            <!-- Porterhouse -->
                            <div class="extra-item flex items-center justify-between gap-4 bg-cream rounded-xl p-5" data-product="porterhouse" data-price="1800">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <h3 class="font-semibold text-forest">Porterhouse</h3>
                                        <span class="text-sm font-bold text-forest">$18</span>
                                    </div>
                                    <p class="text-sm text-gray-500">Premium porterhouse steak</p>
                                    <span class="extra-stock text-xs font-medium text-sage mt-1 inline-block"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="qty-btn qty-minus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                        disabled
                                    >
                                        &minus;
                                    </button>
                                    <span class="qty-display w-8 text-center font-bold text-forest">0</span>
                                    <button
                                        type="button"
                                        class="qty-btn qty-plus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>

                            <!-- Diced Chuck -->
                            <div class="extra-item flex items-center justify-between gap-4 bg-cream rounded-xl p-5" data-product="diced-chuck" data-price="1300">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <h3 class="font-semibold text-forest">Diced Chuck</h3>
                                        <span class="text-sm font-bold text-forest">$13</span>
                                    </div>
                                    <p class="text-sm text-gray-500">Diced chuck steak, great for slow cooking</p>
                                    <span class="extra-stock text-xs font-medium text-sage mt-1 inline-block"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="qty-btn qty-minus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                        disabled
                                    >
                                        &minus;
                                    </button>
                                    <span class="qty-display w-8 text-center font-bold text-forest">0</span>
                                    <button
                                        type="button"
                                        class="qty-btn qty-plus w-9 h-9 rounded-full bg-white border border-forest/20 text-forest flex items-center justify-center font-bold text-lg transition-colors hover:bg-forest hover:text-cream disabled:opacity-30 disabled:hover:bg-white disabled:hover:text-forest cursor-pointer"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>
```

- [ ] **Step 2: Add new products to JS price/name maps**

Update the `EXTRA_PRICES` constant (currently at line ~337):

```typescript
        const EXTRA_PRICES: Record<string, number> = {
            "beef-mince-500g": 1200,
            "beef-bones-2kg": 1000,
            "sausage-packs-6": 1200,
            "rump-steak-2": 2000,
            "porterhouse": 1800,
            "diced-chuck": 1300,
        };
        const EXTRA_NAMES: Record<string, string> = {
            "beef-mince-500g": "500g Beef Mince",
            "beef-bones-2kg": "2kg Beef Bones",
            "sausage-packs-6": "Sausage Packs (6)",
            "rump-steak-2": "Rump Steak (2)",
            "porterhouse": "Porterhouse",
            "diced-chuck": "Diced Chuck",
        };
```

- [ ] **Step 3: Commit**

```bash
git add src/pages/order.astro
git commit -m "feat: add 4 new extra product rows to order form"
```

---

### Task 4: Add extras-only mode to the order page

**Files:**
- Modify: `src/pages/order.astro`

This is the core behavior change. When boxes sell out but extras still have stock, the form switches to extras-only mode.

- [ ] **Step 1: Add `id` to the box section and hero badge for JS targeting**

Add `id="box-section"` to the box step wrapper div. Change line 56 from:

```html
                    <div class="bg-white rounded-2xl shadow-xl border border-forest/5 p-6 md:p-8">
```

to:

```html
                    <div id="box-section" class="bg-white rounded-2xl shadow-xl border border-forest/5 p-6 md:p-8">
```

Add `id="hero-badge"` to the hero badge div. Change line 20 from:

```html
                <div class="inline-flex items-center gap-2 bg-mint/15 border border-mint/30 px-4 py-2 rounded-full text-sm font-medium text-mint-light mb-6">
```

to:

```html
                <div id="hero-badge" class="inline-flex items-center gap-2 bg-mint/15 border border-mint/30 px-4 py-2 rounded-full text-sm font-medium text-mint-light mb-6">
```

- [ ] **Step 2: Add `extrasOnlyMode` state variable and new DOM refs**

After `const extras: Map<string, number> = new Map();` (line 319), add:

```typescript
        let extrasOnlyMode = false;
```

After `const waitlistFallback = document.getElementById("waitlist-fallback")!;` (line 333), add:

```typescript
        const boxSection = document.getElementById("box-section")!;
        const heroBadge = document.getElementById("hero-badge")!;
```

- [ ] **Step 3: Add helper to check if any extras have stock**

After the `DELIVERY_FEE` constant (line 345), add:

```typescript
        const EXTRA_PRODUCT_IDS = Object.keys(EXTRA_PRICES);

        function hasAnyExtrasInStock(): boolean {
            if (!stockData) return false;
            return EXTRA_PRODUCT_IDS.some((id) => (stockData!.stock[id] ?? 0) > 0);
        }
```

- [ ] **Step 4: Modify `fetchStock()` to handle extras-only mode**

Replace the `fetchStock` function (lines 371-395) with:

```typescript
        async function fetchStock(): Promise<void> {
            try {
                const res = await fetch("/api/stock");
                if (!res.ok) throw new Error("Failed to fetch stock");
                stockData = await res.json();

                loadingState.classList.add("hidden");

                if (!stockData!.active) {
                    showInactiveState();
                    return;
                }

                const boxStock = stockData!.stock["beef-box-5kg"] ?? 0;
                if (boxStock <= 0) {
                    if (hasAnyExtrasInStock()) {
                        // Extras-only mode
                        extrasOnlyMode = true;
                        boxSection.classList.add("hidden");
                        heroBadge.textContent = "Beef Boxes Sold Out — Extras Still Available";
                        // Update step numbers
                        extrasSection.querySelector(".bg-forest.text-cream.text-sm")!.textContent = "1";
                        deliverySection.querySelector(".bg-forest.text-cream.text-sm")!.textContent = "2";
                        summarySection.querySelector(".bg-forest.text-cream.text-sm")!.textContent = "3";
                        // Change extras subtitle since there's no box
                        const extrasSubtitle = extrasSection.querySelector("p.text-sm.text-gray-500");
                        if (extrasSubtitle) extrasSubtitle.textContent = "Select from our range of premium cuts.";
                        // Change summary placeholder
                        summaryPlaceholder.textContent = "Select extras to get started";
                        orderForm.classList.remove("hidden");
                        updateStockDisplay();
                    } else {
                        showInactiveState();
                    }
                    return;
                }

                orderForm.classList.remove("hidden");
                updateStockDisplay();
            } catch {
                loadingState.textContent = "Failed to load products. Please refresh the page.";
            }
        }
```

- [ ] **Step 5: Modify `updateSectionStates()` for extras-only mode**

Replace the `updateSectionStates` function (lines 460-468) with:

```typescript
        function updateSectionStates(): void {
            if (extrasOnlyMode) {
                // Extras always enabled; delivery/summary enabled when any extra selected
                const hasExtras = extras.size > 0;
                extrasSection.classList.remove("opacity-50", "pointer-events-none");
                deliverySection.classList.toggle("opacity-50", !hasExtras);
                deliverySection.classList.toggle("pointer-events-none", !hasExtras);
                summarySection.classList.toggle("opacity-50", !hasExtras);
                summarySection.classList.toggle("pointer-events-none", !hasExtras);
            } else {
                const hasBox = selectedBox !== null;
                extrasSection.classList.toggle("opacity-50", !hasBox);
                extrasSection.classList.toggle("pointer-events-none", !hasBox);
                deliverySection.classList.toggle("opacity-50", !hasBox);
                deliverySection.classList.toggle("pointer-events-none", !hasBox);
                summarySection.classList.toggle("opacity-50", !hasBox);
                summarySection.classList.toggle("pointer-events-none", !hasBox);
            }
        }
```

- [ ] **Step 6: Modify `updateSummary()` to handle no-box checkout**

Replace the `checkoutBtn.disabled` line at the end of `updateSummary()` (line 546):

```typescript
            checkoutBtn.disabled = !selectedBox || !deliveryMethod;
```

with:

```typescript
            if (extrasOnlyMode) {
                checkoutBtn.disabled = extras.size === 0 || !deliveryMethod;
            } else {
                checkoutBtn.disabled = !selectedBox || !deliveryMethod;
            }
```

- [ ] **Step 7: Modify `updateStockDisplay()` to handle extras-only sold out transition**

Replace the block at lines 450-455 in `updateStockDisplay()`:

```typescript
            const boxStock = stockData!.stock["beef-box-5kg"] ?? 0;
            if (boxStock <= 0) {
                orderForm.classList.add("hidden");
                inactiveState.classList.remove("hidden");
                waitlistFallback.classList.remove("hidden");
            }
```

with:

```typescript
            const boxStock = stockData!.stock["beef-box-5kg"] ?? 0;
            if (boxStock <= 0 && !extrasOnlyMode) {
                if (hasAnyExtrasInStock()) {
                    // Switch to extras-only mode dynamically (e.g., after 409 stock update)
                    extrasOnlyMode = true;
                    boxSection.classList.add("hidden");
                    selectedBox = null;
                    heroBadge.textContent = "Beef Boxes Sold Out — Extras Still Available";
                    extrasSection.querySelector(".bg-forest.text-cream.text-sm")!.textContent = "1";
                    deliverySection.querySelector(".bg-forest.text-cream.text-sm")!.textContent = "2";
                    summarySection.querySelector(".bg-forest.text-cream.text-sm")!.textContent = "3";
                    const extrasSubtitle = extrasSection.querySelector("p.text-sm.text-gray-500");
                    if (extrasSubtitle) extrasSubtitle.textContent = "Select from our range of premium cuts.";
                    summaryPlaceholder.textContent = "Select extras to get started";
                } else {
                    orderForm.classList.add("hidden");
                    inactiveState.classList.remove("hidden");
                    waitlistFallback.classList.remove("hidden");
                }
            } else if (extrasOnlyMode && !hasAnyExtrasInStock()) {
                // All extras now sold out too
                orderForm.classList.add("hidden");
                inactiveState.classList.remove("hidden");
                waitlistFallback.classList.remove("hidden");
            }
```

- [ ] **Step 8: Modify checkout handler to send `box: null` in extras-only mode**

Replace the checkout guard and payload (lines 631-646):

```typescript
        checkoutBtn.addEventListener("click", async () => {
            if (!selectedBox || !deliveryMethod) return;

            checkoutBtn.disabled = true;
            checkoutBtn.textContent = "Processing...";
            checkoutError.classList.add("hidden");

            const extrasPayload: ExtraSelection[] = [];
            extras.forEach((qty, productId) => {
                if (qty > 0) extrasPayload.push({ productId, quantity: qty });
            });

            try {
                const res = await fetch("/api/checkout", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ box: selectedBox, extras: extrasPayload, deliveryMethod }),
                });
```

with:

```typescript
        checkoutBtn.addEventListener("click", async () => {
            if (extrasOnlyMode) {
                if (extras.size === 0 || !deliveryMethod) return;
            } else {
                if (!selectedBox || !deliveryMethod) return;
            }

            checkoutBtn.disabled = true;
            checkoutBtn.textContent = "Processing...";
            checkoutError.classList.add("hidden");

            const extrasPayload: ExtraSelection[] = [];
            extras.forEach((qty, productId) => {
                if (qty > 0) extrasPayload.push({ productId, quantity: qty });
            });

            try {
                const res = await fetch("/api/checkout", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ box: extrasOnlyMode ? null : selectedBox, extras: extrasPayload, deliveryMethod }),
                });
```

- [ ] **Step 9: Verify the build compiles**

Run: `npx astro check` (or `npm run build`)

Expected: No type errors.

- [ ] **Step 10: Commit**

```bash
git add src/pages/order.astro
git commit -m "feat: add extras-only order mode when beef boxes are sold out"
```

---

### Task 5: Manual verification

No automated tests exist in this project. Verify by running the dev server and testing each scenario.

- [ ] **Step 1: Start dev server**

Run: `npm run dev`

- [ ] **Step 2: Verify normal mode**

Open `/order` in browser. Confirm:
- All 6 extras appear in step 2 (beef mince, beef bones, sausage packs, rump steak, porterhouse, diced chuck)
- Correct prices displayed ($12, $10, $12, $20, $18, $13)
- Stock counts show for each extra
- Quantity +/- buttons work
- Summary totals calculate correctly with box + extras + delivery
- Checkout button sends correct payload

- [ ] **Step 3: Verify extras-only mode**

Set `stock:beef-box-5kg` to 0 in Upstash Redis dashboard (or via CLI). Refresh `/order`. Confirm:
- Box selection step is hidden
- Hero badge shows "Beef Boxes Sold Out — Extras Still Available"
- Extras section is step 1 and always enabled
- Steps are numbered 1, 2, 3 (not 2, 3, 4)
- Can select extras, choose delivery, and reach checkout
- Checkout button enables when extras selected + delivery chosen
- Checkout sends `box: null` in payload

- [ ] **Step 4: Verify full sold-out state**

Set all extra stock keys to 0 as well. Refresh `/order`. Confirm:
- "All Sold Out" card appears
- Waitlist form shows
- No order form visible

- [ ] **Step 5: Build check**

Run: `npm run build`

Expected: Build succeeds with no errors.
