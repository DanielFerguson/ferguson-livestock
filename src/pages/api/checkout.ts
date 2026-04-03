import type { APIRoute } from "astro";
import {
  products,
  bundles,
  resolveBoxStock,
  getBoxPriceId,
  type CartItem,
} from "../../config/products";
import { reserveStock, releaseStock, saveReservation } from "../../lib/kv";
import { getStripe } from "../../lib/stripe";

export const prerender = false;

interface CheckoutRequest {
  box?: "5kg" | "10kg" | null;
  extras: { productId: string; quantity: number }[];
  deliveryMethod: "delivery" | "pickup";
}

export const POST: APIRoute = async ({ request, url }) => {
  try {
    const data: CheckoutRequest = await request.json();

    // Validate box selection (optional — null/undefined means extras-only order)
    const hasBox = data.box === "5kg" || data.box === "10kg";
    if (data.box != null && !hasBox) {
      return new Response(
        JSON.stringify({ error: "Invalid box selection" }),
        { status: 400, headers: { "Content-Type": "application/json" } }
      );
    }

    // Validate delivery method
    if (data.deliveryMethod !== "delivery" && data.deliveryMethod !== "pickup") {
      return new Response(
        JSON.stringify({ error: "Invalid delivery method" }),
        { status: 400, headers: { "Content-Type": "application/json" } }
      );
    }

    // Validate extras are real products and type "extra"
    for (const extra of data.extras ?? []) {
      const product = products[extra.productId];
      if (!product || product.type !== "extra") {
        return new Response(
          JSON.stringify({ error: `Invalid extra: ${extra.productId}` }),
          { status: 400, headers: { "Content-Type": "application/json" } }
        );
      }
      if (!Number.isInteger(extra.quantity) || extra.quantity < 1) {
        return new Response(
          JSON.stringify({ error: "Invalid quantity" }),
          { status: 400, headers: { "Content-Type": "application/json" } }
        );
      }
    }

    // If no box, require at least one extra
    if (!hasBox && (!data.extras || data.extras.length === 0)) {
      return new Response(
        JSON.stringify({ error: "Please select at least one item" }),
        { status: 400, headers: { "Content-Type": "application/json" } }
      );
    }

    // Deduplicate extras by productId (merge quantities)
    const mergedExtras = new Map<string, number>();
    for (const extra of data.extras ?? []) {
      mergedExtras.set(
        extra.productId,
        (mergedExtras.get(extra.productId) ?? 0) + extra.quantity,
      );
    }

    // Build stock requirements
    const stockItems: CartItem[] = [];
    if (hasBox) {
      stockItems.push(resolveBoxStock(data.box as "5kg" | "10kg"));
    }
    for (const [productId, quantity] of mergedExtras) {
      stockItems.push({ productId, quantity });
    }

    // Reserve stock (atomic)
    const reserved = await reserveStock(stockItems);
    if (!reserved) {
      // Return current stock so UI can update
      const { getStock } = await import("../../lib/kv");
      const stock = await getStock();
      return new Response(
        JSON.stringify({ error: "Insufficient stock", stock }),
        { status: 409, headers: { "Content-Type": "application/json" } }
      );
    }

    // Build Stripe line items
    const lineItems: { price: string; quantity: number }[] = [];

    // Box (only if selected)
    if (hasBox) {
      lineItems.push({ price: getBoxPriceId(data.box as "5kg" | "10kg"), quantity: 1 });
    }

    // Extras (using deduplicated map)
    for (const [productId, quantity] of mergedExtras) {
      lineItems.push({
        price: products[productId].stripePriceId,
        quantity,
      });
    }

    // Delivery fee (only for delivery, not pickup)
    if (data.deliveryMethod === "delivery") {
      lineItems.push({
        price: products["delivery-fee"].stripePriceId,
        quantity: 1,
      });
    }

    // Create Stripe Checkout Session — wrapped in try-catch for rollback
    const stripe = getStripe();
    let session;
    try {
      session = await stripe.checkout.sessions.create({
        mode: "payment",
        line_items: lineItems,
        // Only collect shipping address and delivery preference for delivery, not pickup
        ...(data.deliveryMethod === "delivery"
          ? {
              shipping_address_collection: { allowed_countries: ["AU"] as const },
              custom_fields: [{
                key: "delivery_day",
                label: { type: "custom" as const, custom: "Preferred delivery (afternoon/evening)" },
                type: "dropdown" as const,
                dropdown: {
                  options: [
                    { label: "Saturday", value: "saturday" },
                    { label: "Sunday", value: "sunday" },
                  ],
                },
              }],
            }
          : {}),
        phone_number_collection: { enabled: true },
        expires_at: Math.floor(Date.now() / 1000) + 30 * 60, // 30 minutes
        success_url: `${url.origin}/order-confirmed?session_id={CHECKOUT_SESSION_ID}`,
        cancel_url: `${url.origin}/api/cancel-checkout?session_id={CHECKOUT_SESSION_ID}`,
        metadata: {
          reserved_items: JSON.stringify(stockItems),
          delivery_method: data.deliveryMethod,
        },
      });
    } catch (stripeError) {
      // Stripe call failed — rollback stock reservation
      console.error("Stripe session creation failed, rolling back stock:", stripeError);
      await releaseStock(stockItems);
      return new Response(
        JSON.stringify({ error: "Failed to create checkout session" }),
        { status: 500, headers: { "Content-Type": "application/json" } }
      );
    }

    // Save reservation for webhook handling
    try {
      await saveReservation(session.id, stockItems);
    } catch (saveError) {
      console.error("Failed to save reservation, rolling back:", saveError);
      await releaseStock(stockItems);
      try { await stripe.checkout.sessions.expire(session.id); } catch {}
      return new Response(
        JSON.stringify({ error: "Failed to create checkout session" }),
        { status: 500, headers: { "Content-Type": "application/json" } }
      );
    }

    return new Response(
      JSON.stringify({ url: session.url }),
      { status: 200, headers: { "Content-Type": "application/json" } }
    );
  } catch (error) {
    console.error("Checkout error:", error);
    return new Response(
      JSON.stringify({ error: "Checkout failed" }),
      { status: 500, headers: { "Content-Type": "application/json" } }
    );
  }
};
