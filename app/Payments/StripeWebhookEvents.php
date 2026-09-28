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

    /**
     * What's wrong with how Stripe is set up to tell the site about payments, if anything. Stripe must send
     * payment events to this deployment, so staging checks its own address rather than the canonical site's.
     *
     * @param  list<WebhookEndpoint>  $endpoints
     * @return list<string>
     */
    public static function problems(array $endpoints, string $appUrl): array
    {
        $url = rtrim($appUrl, '/').'/api/webhooks/stripe';
        $endpoint = array_values(array_filter($endpoints, fn (WebhookEndpoint $endpoint): bool => $endpoint->enabled && $endpoint->url === $url))[0] ?? null;

        if ($endpoint === null) {
            return ['Stripe isn’t set up to tell the site about payments. Add the webhook endpoint in Stripe.'];
        }

        $missing = array_values(array_diff(self::REQUIRED, $endpoint->events));

        return $missing === [] ? [] : ['The Stripe webhook doesn’t send '.implode(', ', $missing).'.'];
    }
}
