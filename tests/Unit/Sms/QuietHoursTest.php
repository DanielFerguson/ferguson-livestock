<?php

use App\Sms\QuietHours;
use Carbon\CarbonImmutable;

it('covers 8pm to 8am in Melbourne', function (string $melbourneTime, bool $quiet) {
    expect(QuietHours::contains(CarbonImmutable::parse($melbourneTime, 'Australia/Melbourne')))->toBe($quiet);
})->with([
    ['2026-10-10 19:59', false],
    ['2026-10-10 20:00', true],
    ['2026-10-11 02:00', true],
    ['2026-10-11 07:59', true],
    ['2026-10-11 08:00', false],
]);

it('uses Melbourne time whatever time zone it is given', function () {
    // 09:30 UTC is 8:30pm in Melbourne once daylight saving has started.
    expect(QuietHours::contains(CarbonImmutable::parse('2026-10-10 09:30', 'UTC')))->toBeTrue();
});
