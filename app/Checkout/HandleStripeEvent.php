<?php

namespace App\Checkout;

use App\Models\Order;
use App\Models\StripeEvent;
use App\Payments\CheckoutSession;

/**
 * Applies one of Stripe's events to its order. Events about sessions the shop didn't start, and event types it
 * doesn't use, change nothing.
 */
final readonly class HandleStripeEvent
{
    public function __construct(private OrderPayments $orders) {}

    public function __invoke(StripeEvent $event): void
    {
        $object = $event->object();

        if ($event->type === 'charge.refunded') {
            $this->refund($object);

            return;
        }

        $session = CheckoutSession::fromStripe($object);
        $order = $this->orderFor($session);

        if ($order === null) {
            return;
        }

        match ($event->type) {
            'checkout.session.completed', 'checkout.session.expired' => $this->orders->apply($order, $session),
            'checkout.session.async_payment_succeeded' => $this->orders->markPaid($order, $session),
            'checkout.session.async_payment_failed' => $this->orders->markFailed($order),
            default => null,
        };
    }

    private function orderFor(CheckoutSession $session): ?Order
    {
        if ($session->id === '') {
            return null;
        }

        return Order::query()->where('stripe_checkout_session_id', $session->id)->first()
            ?? ($session->orderPublicId === null ? null : Order::query()->where('public_id', $session->orderPublicId)->first());
    }

    /**
     * @param  array<array-key, mixed>  $charge
     */
    private function refund(array $charge): void
    {
        $paymentIntent = $charge['payment_intent'] ?? null;
        $refunded = $charge['amount_refunded'] ?? null;

        $order = is_string($paymentIntent) ? Order::query()->where('stripe_payment_intent_id', $paymentIntent)->first() : null;

        if ($order !== null && is_int($refunded)) {
            $this->orders->recordRefund($order, $refunded);
        }
    }
}
