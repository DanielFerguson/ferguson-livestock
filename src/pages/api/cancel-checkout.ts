import type { APIRoute } from "astro";
import { getReservation, releaseStock, deleteReservation } from "../../lib/kv";
import { getStripe } from "../../lib/stripe";

export const prerender = false;

export const GET: APIRoute = async ({ url, redirect }) => {
  const sessionId = url.searchParams.get("session_id");

  // Validate session ID format (Stripe checkout sessions start with cs_)
  if (!sessionId || !/^cs_[a-zA-Z0-9_]+$/.test(sessionId)) {
    return redirect("/order", 302);
  }

  try {
    // Check if reservation still exists (idempotent — may already be cancelled)
    const reservation = await getReservation(sessionId);
    if (!reservation) {
      return redirect("/order", 302);
    }

    // Verify with Stripe that the session was NOT paid
    const stripe = getStripe();
    const session = await stripe.checkout.sessions.retrieve(sessionId);

    if (session.payment_status === "paid") {
      // Payment actually went through — don't release stock
      return redirect(`/order-confirmed?session_id=${sessionId}`, 302);
    }

    // Release reserved stock
    await releaseStock(reservation.items);
    await deleteReservation(sessionId);

    return redirect("/order", 302);
  } catch (error) {
    console.error("Cancel checkout error:", error);
    // On error, redirect to order page anyway — the reservation TTL will
    // eventually clean up, and the expired webhook will restore stock
    return redirect("/order", 302);
  }
};
