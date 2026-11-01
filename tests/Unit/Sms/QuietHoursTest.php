<?php

declare(strict_types=1);

use App\Sms\QuietHours;
use Carbon\CarbonImmutable;

it('covers 8pm to 8am in Melbourne', function (string $melbourneTime, bool $quiet): void {
    expect(QuietHours::contains(CarbonImmutable::parse($melbourneTime, 'Australia/Melbourne')))->toBe($quiet);
})->with([
    ['2026-10-10 19:59', false],
    ['2026-10-10 20:00', true],
    ['2026-10-11 02:00', true],
    ['2026-10-11 07:59', true],
    ['2026-10-11 08:00', false],
]);

it('says when texting is next allowed', function (string $melbourneTime, string $expected): void {
    $next = QuietHours::nextSendingTime(CarbonImmutable::parse($melbourneTime, 'Australia/Melbourne'));

    expect($next->setTimezone('Australia/Melbourne')->format('Y-m-d H:i'))->toBe($expected);
})->with([
    'outside quiet hours' => ['2026-10-10 19:59', '2026-10-10 19:59'],
    'in the evening' => ['2026-10-10 20:00', '2026-10-11 08:00'],
    'before dawn' => ['2026-10-11 02:00', '2026-10-11 08:00'],
    'as quiet hours end' => ['2026-10-11 08:00', '2026-10-11 08:00'],
]);

it('uses Melbourne time whatever time zone it is given', function (): void {
    // 09:30 UTC is 8:30pm in Melbourne once daylight saving has started.
    expect(QuietHours::contains(CarbonImmutable::parse('2026-10-10 09:30', 'UTC')))->toBeTrue();
});
