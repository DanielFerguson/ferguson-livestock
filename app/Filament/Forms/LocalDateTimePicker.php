<?php

namespace App\Filament\Forms;

use App\Rules\ExistingLocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Forms\Components\DateTimePicker;

/**
 * A date and time entered and shown in Melbourne time, stored in UTC.
 *
 * The conversion happens here on the server rather than in the browser, so the value validation
 * sees is the wall-clock time the admin typed. That lets ExistingLocalTime reject times skipped or
 * repeated when daylight saving starts or ends.
 */
class LocalDateTimePicker extends DateTimePicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $timezone = config()->string('shop.timezone');

        $this
            // The state is already Melbourne wall-clock time; stop the browser shifting it again.
            ->timezone('UTC')
            ->seconds(false)
            ->formatStateUsing(function (mixed $state) use ($timezone): ?string {
                if ($state === '' || (! is_string($state) && ! $state instanceof DateTimeInterface)) {
                    return null;
                }

                return CarbonImmutable::parse($state)->setTimezone($timezone)->format('Y-m-d H:i:s');
            })
            ->dehydrateStateUsing(fn (mixed $state): ?CarbonImmutable => is_string($state) && $state !== ''
                ? CarbonImmutable::parse($state, $timezone)->utc()
                : null)
            ->rule(new ExistingLocalTime($timezone))
            ->hint('Melbourne time');
    }
}
