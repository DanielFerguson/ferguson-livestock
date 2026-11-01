<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Enums\DeliveryMethod;
use App\Support\AdCampaign;
use App\Support\MetaVisitor;
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
     * @param  string|null  $earlyAccessToken  the secret from an early-access link; checkout checks it against the drop's own
     * @param  MetaVisitor|null  $visitor  the customer's browser, for matching the order to a Meta ad
     * @param  AdCampaign|null  $campaign  the tagged ad link that brought the customer to the site, if any
     */
    public function __construct(
        public int $dropId,
        public array $quantities,
        public DeliveryMethod $deliveryMethod,
        public ?CarbonImmutable $deliveryDay,
        public string $sessionId,
        public string $ip,
        public string $botCheckToken = '',
        public ?string $earlyAccessToken = null,
        public ?MetaVisitor $visitor = null,
        public ?AdCampaign $campaign = null,
    ) {}
}
