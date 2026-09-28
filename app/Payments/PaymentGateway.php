<?php

namespace App\Payments;

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
     * The webhook endpoints registered with the provider.
     *
     * @return list<WebhookEndpoint>
     */
    public function webhookEndpoints(): array;
}
