<?php

use App\Enums\DropStatus;
use App\Models\Drop;
use App\Models\DropItem;
use Carbon\CarbonImmutable;

it('is a draft until it is published, whatever the time', function () {
    $drop = Drop::factory()->draft()->create(['opens_at' => now()->subDay()]);

    expect($drop->status())->toBe(DropStatus::Draft);
});

it('is scheduled until it opens, then live', function () {
    $drop = Drop::factory()->withStock()->create(['opens_at' => now()->addHour()]);

    expect($drop->status())->toBe(DropStatus::Scheduled);

    $this->travelTo($drop->opens_at);

    expect($drop->status())->toBe(DropStatus::Live);
});

it('opens at the right moment when the clocks go forward in Melbourne', function () {
    // 9am on Sunday 4 October 2026 is AEDT (UTC+11), the first morning of daylight saving.
    $opensAt = CarbonImmutable::parse('2026-10-04 09:00', 'Australia/Melbourne')->utc();
    $drop = Drop::factory()->withStock()->create(['opens_at' => $opensAt]);

    expect($opensAt->toIso8601String())->toBe('2026-10-03T22:00:00+00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-03 21:59:59', 'UTC'));
    expect($drop->status())->toBe(DropStatus::Scheduled);

    $this->travelTo(CarbonImmutable::parse('2026-10-03 22:00:00', 'UTC'));
    expect($drop->status())->toBe(DropStatus::Live);
});

it('is sold out when every item with its own stock has none left', function () {
    $drop = Drop::factory()->open()->create();
    DropItem::factory()->for($drop)->create(['quantity' => 5, 'available' => 0]);
    DropItem::factory()->for($drop)->delivery()->create();

    expect($drop->status())->toBe(DropStatus::SoldOut);
});

it('stays live while any item with its own stock is left', function () {
    $drop = Drop::factory()->open()->create();
    DropItem::factory()->for($drop)->create(['quantity' => 5, 'available' => 0]);
    DropItem::factory()->for($drop)->create(['quantity' => 10, 'available' => 1]);

    expect($drop->status())->toBe(DropStatus::Live);
});

it('closes when closed by hand', function () {
    $drop = Drop::factory()->open()->withStock()->create(['closed_at' => now()]);

    expect($drop->status())->toBe(DropStatus::Closed);
});

it('closes at its close time', function () {
    $drop = Drop::factory()->open()->withStock()->create(['closes_at' => now()->addHour()]);

    $this->travel(59)->minutes();
    expect($drop->status())->toBe(DropStatus::Live);

    $this->travel(1)->minutes();
    expect($drop->status())->toBe(DropStatus::Closed);
});

it('closes when the next published drop opens', function () {
    $current = Drop::factory()->open()->withStock()->create();
    Drop::factory()->withStock()->create(['opens_at' => now()->addDay()]);

    expect($current->status())->toBe(DropStatus::Live);

    $this->travel(1)->days();
    expect($current->status())->toBe(DropStatus::Closed);
});

it('is not closed by a later draft', function () {
    $current = Drop::factory()->open()->withStock()->create();
    Drop::factory()->draft()->create(['opens_at' => now()->subMinute()]);

    expect($current->status())->toBe(DropStatus::Live);
});
