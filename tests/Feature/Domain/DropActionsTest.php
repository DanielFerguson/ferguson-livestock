<?php

use App\Enums\DropStatus;
use App\Models\Drop;
use App\Models\DropItem;

it('closes a drop straight away', function () {
    $this->freezeSecond();
    $drop = Drop::factory()->open()->withStock()->create();

    $drop->closeNow();

    expect($drop->refresh()->closed_at?->equalTo(now()))->toBeTrue()
        ->and($drop->status())->toBe(DropStatus::Closed);
});

it('duplicates a drop as a draft a week later, with fresh stock', function () {
    $drop = Drop::factory()->closed()->create([
        'name' => 'Autumn drop',
        'opens_at' => '2026-04-11 23:00:00',
        'closes_at' => '2026-04-13 23:00:00',
        'delivery_days' => ['2026-04-18', '2026-04-19'],
        'preflight_report' => ['passed' => true, 'problems' => [], 'checked_at' => '2026-04-11T22:50:00+00:00'],
    ]);
    DropItem::factory()->for($drop)->create(['quantity' => 5, 'available' => 0, 'price' => 16000, 'stripe_price_id' => 'price_box']);
    DropItem::factory()->for($drop)->delivery()->create(['price' => 1500, 'stripe_price_id' => 'price_delivery']);

    $copy = $drop->duplicateAsDraft();

    expect($copy->name)->toBe('Autumn drop (copy)')
        ->and($copy->status())->toBe(DropStatus::Draft)
        ->and($copy->opens_at->toDateTimeString())->toBe('2026-04-18 23:00:00')
        ->and($copy->closes_at?->toDateTimeString())->toBe('2026-04-20 23:00:00')
        ->and($copy->closed_at)->toBeNull()
        ->and($copy->delivery_days)->toBe(['2026-04-25', '2026-04-26'])
        ->and($copy->preflight_report)->toBeNull()
        ->and($copy->items->map->only('price', 'stripe_price_id', 'quantity', 'available')->all())->toBe([
            ['price' => 16000, 'stripe_price_id' => 'price_box', 'quantity' => 5, 'available' => 5],
            ['price' => 1500, 'stripe_price_id' => 'price_delivery', 'quantity' => null, 'available' => null],
        ]);
});
