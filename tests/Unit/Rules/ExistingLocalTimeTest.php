<?php

use App\Rules\ExistingLocalTime;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

function localTimeProblem(string $value): ?string
{
    $problem = null;

    (new ExistingLocalTime('Australia/Melbourne'))->validate('opens_at', $value, function (string $message) use (&$problem): PotentiallyTranslatedString {
        $problem = $message;

        return new PotentiallyTranslatedString($message, new Translator(new ArrayLoader, 'en'));
    });

    return $problem;
}

it('accepts an ordinary Melbourne time', function (string $time) {
    expect(localTimeProblem($time))->toBeNull();
})->with(['2026-10-10 09:00:00', '2026-10-04 01:59:00', '2026-10-04 03:00:00', '2027-04-04 01:59:00', '2027-04-04 03:00:00']);

it('rejects a time skipped when the clocks go forward', function () {
    expect(localTimeProblem('2026-10-04 02:30:00'))
        ->toBe('2:30am doesn’t exist on Sunday 4 October 2026 because the clocks go forward that morning. Pick a time after 3am.');
});

it('rejects a time that happens twice when the clocks go back', function () {
    expect(localTimeProblem('2027-04-04 02:30:00'))
        ->toBe('2:30am happens twice on Sunday 4 April 2027 because the clocks go back that morning. Pick a time before 2am or after 3am.');
});

it('leaves empty values to the required rule', function () {
    expect(localTimeProblem(''))->toBeNull();
});
