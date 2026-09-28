<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a local wall-clock time that is skipped or repeated when daylight saving starts or ends,
 * so a drop never opens an hour earlier or later than the admin meant.
 */
class ExistingLocalTime implements ValidationRule
{
    public function __construct(private readonly string $timezone) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $timezone = new DateTimeZone($this->timezone);
        $wallClock = CarbonImmutable::parse($value, 'UTC')->format('Y-m-d H:i');
        $instant = CarbonImmutable::parse($value, $timezone);
        $time = $instant->format('g:ia');
        $day = CarbonImmutable::parse($value, 'UTC')->format('l j F Y');

        if ($instant->format('Y-m-d H:i') !== $wallClock) {
            $time = CarbonImmutable::parse($value, 'UTC')->format('g:ia');
            $fail("{$time} doesn’t exist on {$day} because the clocks go forward that morning. Pick a time after 3am.");

            return;
        }

        foreach ([-3600, 3600] as $offset) {
            $other = CarbonImmutable::createFromTimestamp($instant->getTimestamp() + $offset, $timezone);

            if ($other->format('Y-m-d H:i') === $wallClock) {
                $fail("{$time} happens twice on {$day} because the clocks go back that morning. Pick a time before 2am or after 3am.");

                return;
            }
        }
    }
}
