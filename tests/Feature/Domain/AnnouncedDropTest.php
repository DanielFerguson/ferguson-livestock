<?php

use App\Models\Drop;

it('finds the next unpublished drop with its date announced', function () {
    Drop::factory()->announced()->create(['opens_at' => now()->addWeeks(4)]);
    $soonest = Drop::factory()->announced()->create(['opens_at' => now()->addWeeks(2)]);

    expect(Drop::announced()?->is($soonest))->toBeTrue();
});

it('ignores published drops, drafts that aren’t announced and dates that have passed', function () {
    Drop::factory()->create(['opens_at' => now()->addWeek()]);
    Drop::factory()->draft()->create(['opens_at' => now()->addWeek()]);
    Drop::factory()->announced()->create(['opens_at' => now()->subDay()]);

    expect(Drop::announced())->toBeNull();
});

it('names the opening day in Melbourne time', function () {
    // 10pm UTC on Friday 13 November is 9am on Saturday 14 November in Melbourne.
    $drop = Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    expect($drop->announcedLabel())->toBe('Saturday 14 November');
});
