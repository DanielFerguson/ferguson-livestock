<?php

use App\Payments\Price;
use App\Payments\PriceCheck;

/**
 * @param  array{active?: bool, type?: string, currency?: string, unitAmount?: int}  $overrides
 */
function stripePrice(array $overrides = []): Price
{
    return new Price(
        id: 'price_123',
        active: $overrides['active'] ?? true,
        type: $overrides['type'] ?? 'one_time',
        currency: $overrides['currency'] ?? 'aud',
        unitAmount: $overrides['unitAmount'] ?? 16000,
        productName: '5kg Beef Box',
    );
}

it('accepts an active one-time AUD price for the same amount', function () {
    expect(PriceCheck::problems(stripePrice(), 16000))->toBe([]);
});

it('explains every reason a price can’t be used', function (?Price $price, string $problem) {
    expect(PriceCheck::problems($price, 16000))->toContain($problem);
})->with([
    'missing' => [null, 'Stripe has no price with this ID. Check it was copied from the right Stripe account (test or live).'],
    'archived' => [fn () => stripePrice(['active' => false]), 'This Stripe price is archived. Unarchive it or use an active price.'],
    'recurring' => [fn () => stripePrice(['type' => 'recurring']), 'This is a subscription price. Drops need a one-time price.'],
    'wrong currency' => [fn () => stripePrice(['currency' => 'usd']), 'This price is in USD. Drops are charged in AUD.'],
    'wrong amount' => [fn () => stripePrice(['unitAmount' => 15000]), 'Stripe charges $150 for this price, but the drop says $160.'],
]);

it('reports every problem at once', function () {
    $problems = PriceCheck::problems(stripePrice(['active' => false, 'currency' => 'usd']), 16000);

    expect($problems)->toHaveCount(2);
});
