<?php

namespace App\Payments;

/**
 * The parts of a Stripe Price that decide whether a drop can charge it.
 */
final readonly class Price
{
    public function __construct(
        public string $id,
        public bool $active,
        /** 'one_time' or 'recurring' */
        public string $type,
        /** Lowercase ISO code, e.g. 'aud' */
        public string $currency,
        /** In cents; null for tiered or custom-amount prices */
        public ?int $unitAmount,
        public ?string $productName = null,
    ) {}
}
