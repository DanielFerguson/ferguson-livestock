<?php

declare(strict_types=1);

namespace App\Payments;

use App\Exceptions\PaymentProviderUnavailable;

/**
 * Everything the app asks of the payment provider. Tests bind a fake in its place.
 */
interface PaymentGateway
{
    /**
     * The price with this ID, or null when the provider doesn't have it.
     */
    public function retrievePrice(string $priceId): ?Price;

    /**
     * The prices a drop can charge, which are the active, one-time ones in Australian dollars, for an admin to choose from.
     *
     * @return list<Price>
     *
     * @throws PaymentProviderUnavailable
     */
    public function activePrices(): array;

    /**
     * The webhook endpoints registered with the provider.
     *
     * @return list<WebhookEndpoint>
     */
    public function webhookEndpoints(): array;

    /**
     * @throws PaymentProviderUnavailable
     */
    public function createCheckoutSession(NewCheckoutSession $session): CheckoutSession;

    /**
     * @throws PaymentProviderUnavailable
     */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSession;

    /**
     * Close the payment page if it's still open, and say where it ended up: expired, or complete when the
     * customer paid first.
     *
     * @throws PaymentProviderUnavailable
     */
    public function expireCheckoutSession(string $sessionId): CheckoutSession;
}
