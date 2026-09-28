<?php

namespace Tests\Fixtures;

use App\Enums\DeliveryMethod;
use App\Exceptions\InsufficientStock;
use App\Models\Drop;
use App\Stock\StockLedger;
use Closure;

/**
 * A customer trying to buy one item, run in its own process by the concurrency tests.
 *
 * Defined here rather than in the test file because a serialised closure is restored in the class it was
 * written in, and child processes can't load Pest's generated test classes.
 */
final class ConcurrentBuyer
{
    /**
     * @return Closure(): string 'held' or 'sold out'
     */
    public static function task(int $dropId, int $dropItemId, float $startAt, string $buyer): Closure
    {
        return static function () use ($dropId, $dropItemId, $startAt, $buyer): string {
            time_sleep_until($startAt);

            try {
                app(StockLedger::class)->reserve(Drop::findOrFail($dropId), [$dropItemId => 1], DeliveryMethod::Pickup, null, $buyer, now()->addMinutes(31));

                return 'held';
            } catch (InsufficientStock) {
                return 'sold out';
            }
        };
    }
}
