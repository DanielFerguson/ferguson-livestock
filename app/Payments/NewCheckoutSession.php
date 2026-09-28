<?php

namespace App\Payments;

use Carbon\CarbonImmutable;

/**
 * What the payment page needs for one order.
 */
final readonly class NewCheckoutSession
{
    /**
     * @param  list<array{price: string, quantity: int}>  $lineItems
     */
    public function __construct(
        public string $orderPublicId,
        public array $lineItems,
        public bool $collectShippingAddress,
        public CarbonImmutable $expiresAt,
        public string $successUrl,
        public string $cancelUrl,
    ) {}
}
