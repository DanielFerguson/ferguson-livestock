import type { APIRoute } from "astro";
import type { CartItem } from "../../../config/products";
import {
  claimReservation,
  releaseStock,
  deleteReservation,
  markRefundProcessed,
} from "../../../lib/kv";
import { getStripe } from "../../../lib/stripe";

export const prerender = false;

export const POST: APIRoute = async ({ request }) => {
  const stripe = getStripe();
  const signature = request.headers.get("stripe-signature");
  const webhookSecret = import.meta.env.STRIPE_WEBHOOK_SECRET;

  if (!signature || !webhookSecret) {
    return new Response("Missing signature or webhook secret", { status: 400 });
  }

  let event;
  try {
    const body = await request.text();
    event = stripe.webhooks.constructEvent(body, signature, webhookSecret);
  } catch (err) {
    console.error("Webhook signature verification failed:", err);
    return new Response("Invalid signature", { status: 400 });
  }

  try {
    switch (event.type) {
      case "checkout.session.completed": {
        const session = event.data.object;
        // Payment succeeded — delete reservation (stock stays decremented)
        await deleteReservation(session.id);
        console.log(`Order confirmed: ${session.id}`);
        break;
      }

      case "checkout.session.expired": {
        const session = event.data.object;
        // Atomically claim the reservation (get + delete).
        // If null, it was already claimed by cancel-checkout — no double-release.
        const reservation = await claimReservation(session.id);
        if (reservation) {
          await releaseStock(reservation.items);
          console.log(`Reservation expired, stock restored: ${session.id}`);
        }
        break;
      }

      case "charge.refunded": {
        const charge = event.data.object;

        // Idempotency: skip if this charge was already refund-processed
        const isNew = await markRefundProcessed(charge.id);
        if (!isNew) break;

        // Find the checkout session via payment intent
        const paymentIntentId =
          typeof charge.payment_intent === "string"
            ? charge.payment_intent
            : charge.payment_intent?.id;

        if (paymentIntentId) {
          const sessions = await stripe.checkout.sessions.list({
            payment_intent: paymentIntentId,
            limit: 1,
          });

          if (sessions.data.length > 0) {
            const session = sessions.data[0];
            const reservedItemsRaw = session.metadata?.reserved_items;

            if (reservedItemsRaw) {
              const items: CartItem[] = JSON.parse(reservedItemsRaw);
              await releaseStock(items);
              console.log(`Refund processed, stock restored: ${session.id}`);
            }
          }
        }
        break;
      }

      default:
        // Unhandled event type — acknowledge it
        break;
    }
  } catch (error) {
    console.error(`Webhook handler error for ${event.type}:`, error);
    return new Response("Webhook handler failed", { status: 500 });
  }

  return new Response(JSON.stringify({ received: true }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
};
