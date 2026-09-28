<?php

use App\Support\Money;

it('formats whole dollar amounts without cents', function () {
    expect(Money::format(16000))->toBe('$160')
        ->and(Money::format(1500))->toBe('$15');
});

it('keeps cents when an amount has them', function () {
    expect(Money::format(1250))->toBe('$12.50')
        ->and(Money::format(2750))->toBe('$27.50');
});

it('groups thousands', function () {
    expect(Money::format(125000))->toBe('$1,250');
});

it('formats a per-kilogram price', function () {
    expect(Money::perKg(3200))->toBe('$32/kg')
        ->and(Money::perKg(2750))->toBe('$27.50/kg');
});
