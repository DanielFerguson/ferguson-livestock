<?php

use App\Models\Drop;

it('features the drop that is open now', function () {
    Drop::factory()->closed()->create();
    $open = Drop::factory()->open()->withStock()->create();

    expect(Drop::current()?->is($open))->toBeTrue()
        ->and(Drop::featured()?->is($open))->toBeTrue();
});

it('features the next scheduled drop when none is open', function () {
    Drop::factory()->closed()->create();
    $soonest = Drop::factory()->create(['opens_at' => now()->addDays(3)]);
    Drop::factory()->create(['opens_at' => now()->addDays(10)]);

    expect(Drop::current())->toBeNull()
        ->and(Drop::featured()?->is($soonest))->toBeTrue();
});

it('falls back to the most recent drop between drops', function () {
    Drop::factory()->closed()->create(['opens_at' => now()->subMonths(2)]);
    $latest = Drop::factory()->closed()->create(['opens_at' => now()->subWeek()]);

    expect(Drop::featured()?->is($latest))->toBeTrue();
});

it('never features a draft', function () {
    Drop::factory()->draft()->create(['opens_at' => now()->subHour()]);

    expect(Drop::featured())->toBeNull();
});
