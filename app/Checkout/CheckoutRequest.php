<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Enums\DeliveryMethod;
use Carbon\CarbonImmutable;

/**
 * What a customer asked to buy, and who's asking.
 */
final readonly class CheckoutRequest
{
    /**
     * @param  int  $dropId  only the ID: checkout loads the drop itself, after counting the try
     * @param  array<int, int>  $quantities  drop item ID => quantity
     * @param  string  $sessionId  the visitor's session, to find and let go of their earlier unpaid checkout
     * @param  string  $botCheckToken  the Turnstile widget's token, from the form in the browser
     */
    public function __construct(
        public int $dropId,
        public array $quantities,
        public DeliveryMethod $deliveryMethod,
        public ?CarbonImmutable $deliveryDay,
        public string $sessionId,
        public string $ip,
        public string $botCheckToken = '',
    ) {}
}
