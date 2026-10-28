<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Models\Drop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft drop, or changes a drop's name, times, delivery days and whether its date is announced.
 *
 * Publishing is separate (PublishDrop): a drop only goes live on purpose.
 *
 * @phpstan-type Changes array{name?: string, opens_at?: CarbonImmutable, closes_at?: CarbonImmutable|null, delivery_days?: list<string>, announced?: bool}
 */
final class SaveDrop
{
    /**
     * @param  Changes  $changes  Only the keys present are changed. Times are absolute instants.
     *
     * @throws CannotChangeDrop
     */
    public function __invoke(?Drop $drop, array $changes): Drop
    {
        $attributes = [];

        if (array_key_exists('name', $changes)) {
            $name = trim($changes['name']);

            if ($name === '' || mb_strlen($name) > 100) {
                throw new CannotChangeDrop('A drop needs a name of up to 100 characters.');
            }

            $attributes['name'] = $name;
        }

        if (! $drop instanceof Drop && ! array_key_exists('opens_at', $changes)) {
            throw new CannotChangeDrop('A new drop needs an opening time.');
        }

        if (! $drop instanceof Drop && ! array_key_exists('name', $changes)) {
            throw new CannotChangeDrop('A new drop needs a name.');
        }

        $opensAt = $changes['opens_at'] ?? $drop?->opens_at;
        $closesAt = array_key_exists('closes_at', $changes) ? $changes['closes_at'] : $drop?->closes_at;

        if (array_key_exists('opens_at', $changes)) {
            $attributes['opens_at'] = $changes['opens_at'];
        }

        if (array_key_exists('closes_at', $changes)) {
            $attributes['closes_at'] = $changes['closes_at'];
        }

        if ($opensAt instanceof CarbonImmutable && $closesAt instanceof CarbonImmutable && ! $closesAt->isAfter($opensAt)) {
            throw new CannotChangeDrop('Orders must close after they open.');
        }

        if (array_key_exists('delivery_days', $changes)) {
            $attributes['delivery_days'] = $this->deliveryDays($changes['delivery_days']);
        }

        if (array_key_exists('announced', $changes)) {
            if ($drop?->published_at !== null) {
                throw new CannotChangeDrop('A published drop is already visible to customers; there is nothing to announce.');
            }

            $attributes['announced_at'] = $changes['announced'] ? ($drop->announced_at ?? now()) : null;
        }

        if ($drop?->published_at !== null && $opensAt instanceof CarbonImmutable) {
            $this->assertNoClash($opensAt, $closesAt, $drop->id);
        }

        return DB::transaction(function () use ($drop, $attributes): Drop {
            if ($drop instanceof Drop) {
                $drop->update($attributes);

                return $drop->refresh();
            }

            $attributes += ['delivery_days' => []];

            return Drop::query()->create($attributes)->refresh();
        });
    }

    /**
     * @param  list<string>  $days
     * @return list<string>
     */
    private function deliveryDays(array $days): array
    {
        $dates = [];

        foreach ($days as $day) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $day);

            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $day) {
                throw new CannotChangeDrop("\"{$day}\" is not a date. Use YYYY-MM-DD.");
            }

            $dates[$day] = $day;
        }

        ksort($dates);

        return array_values($dates);
    }

    /**
     * @throws CannotChangeDrop
     */
    public function assertNoClash(CarbonImmutable $opensAt, ?CarbonImmutable $closesAt, ?int $ignoring): void
    {
        $clash = Drop::conflictingWith($opensAt, $closesAt, $ignoring);

        if ($clash instanceof Drop) {
            $opens = $clash->opens_at->setTimezone(config()->string('shop.timezone'))->format('g:ia D j M');

            throw new CannotChangeDrop("This overlaps “{$clash->name}”, which opens at {$opens}. Only one drop can be open at a time.");
        }
    }
}
