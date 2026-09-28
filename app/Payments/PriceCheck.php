<?php

namespace App\Payments;

use App\Support\Money;

/**
 * Why a Stripe price can't be used for a drop item, in words the admin can act on.
 */
final class PriceCheck
{
    /**
     * @return list<string> Empty when the price is fine.
     */
    public static function problems(?Price $price, int $expectedCents): array
    {
        if ($price === null) {
            return ['Stripe has no price with this ID. Check it was copied from the right Stripe account (test or live).'];
        }

        $problems = [];

        if (! $price->active) {
            $problems[] = 'This Stripe price is archived. Unarchive it or use an active price.';
        }

        if ($price->type !== 'one_time') {
            $problems[] = 'This is a subscription price. Drops need a one-time price.';
        }

        if ($price->currency !== 'aud') {
            $problems[] = 'This price is in '.strtoupper($price->currency).'. Drops are charged in AUD.';
        }

        if ($price->unitAmount !== $expectedCents) {
            $problems[] = $price->unitAmount === null
                ? 'This price doesn’t have a fixed amount. Use a standard, fixed price.'
                : 'Stripe charges '.Money::format($price->unitAmount).' for this price, but the drop says '.Money::format($expectedCents).'.';
        }

        return $problems;
    }
}
