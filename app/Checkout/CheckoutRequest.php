<?php

namespace App\Checkout;

use App\Enums\DeliveryMethod;
use App\Models\Drop;
use Carbon\CarbonImmutable;

/**
 * What a customer asked to buy, and who's asking.
 */
final readonly class CheckoutRequest
{
    /**
     * @param  array<int, int>  $quantities  drop item ID => quantity
     * @param  string  $sessionId  the visitor's session, to find and let go of their earlier unpaid checkout
     */
    public function __construct(
        public Drop $drop,
        public array $quantities,
        public DeliveryMethod $deliveryMethod,
        public ?CarbonImmutable $deliveryDay,
        public string $sessionId,
        public string $ip,
    ) {}
}
