<?php

namespace App\Payments;

/**
 * The Stripe events the site needs to keep orders and stock in step with payments.
 */
final class StripeWebhookEvents
{
    /** @var list<string> */
    public const array REQUIRED = [
        'checkout.session.completed',
        'checkout.session.expired',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'charge.refunded',
    ];
}
