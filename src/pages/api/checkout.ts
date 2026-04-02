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
  box: "5kg" | "10kg";
  extras: { productId: string; quantity: number }[];
}

export const POST: APIRoute = async ({ request, url }) => {
  try {
    const data: CheckoutRequest = await request.json();

    // Validate box selection
    if (data.box !== "5kg" && data.box !== "10kg") {
      return new Response(
        JSON.stringify({ error: "Invalid box selection" }),
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

    // Build stock requirements
    const stockItems: CartItem[] = [resolveBoxStock(data.box)];
    for (const extra of data.extras ?? []) {
      stockItems.push({
        productId: extra.productId,
        quantity: extra.quantity,
      });
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

    // Box
    lineItems.push({ price: getBoxPriceId(data.box), quantity: 1 });

    // Extras
    for (const extra of data.extras ?? []) {
      lineItems.push({
        price: products[extra.productId].stripePriceId,
        quantity: extra.quantity,
      });
    }

    // Create Stripe Checkout Session — wrapped in try-catch for rollback
    const stripe = getStripe();
    let session;
    try {
      session = await stripe.checkout.sessions.create({
        mode: "payment",
        line_items: lineItems,
        shipping_address_collection: {
          allowed_countries: ["AU"],
        },
        phone_number_collection: { enabled: true },
        expires_at: Math.floor(Date.now() / 1000) + 30 * 60, // 30 minutes
        success_url: `${url.origin}/order-confirmed?session_id={CHECKOUT_SESSION_ID}`,
        cancel_url: `${url.origin}/api/cancel-checkout?session_id={CHECKOUT_SESSION_ID}`,
        metadata: {
          reserved_items: JSON.stringify(stockItems),
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
    await saveReservation(session.id, stockItems);

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
