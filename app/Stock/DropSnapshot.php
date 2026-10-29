<?php

declare(strict_types=1);

namespace App\Stock;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Bundle;
use App\Models\Drop;
use App\Models\DropItem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * What every page's live stock script polls for: the drop customers see and what's left of each item.
 *
 * Built at most once a second however many people are watching, and rebuilt straight after anything that
 * changes stock, so a poll never shows numbers older than the last change.
 */
final class DropSnapshot
{
    private const string CACHE_KEY = 'drop-snapshot';

    /**
     * @return array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, server_time: string, items: array<string, array{available: int, max: int, pool: string, units: int}>, held: int}
     */
    public static function current(): array
    {
        $snapshot = self::cached();

        return [...$snapshot, 'server_time' => now()->toIso8601ZuluString('millisecond')];
    }

    /**
     * Rebuilt by one request at a time. When a stock change clears the snapshot, every viewer's next poll arrives
     * together; the first rebuilds it and the rest wait a moment for the result instead of all querying the database.
     *
     * @return array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, items: array<string, array{available: int, max: int, pool: string, units: int}>, held: int}
     */
    private static function cached(): array
    {
        $snapshot = self::stored();

        if ($snapshot !== null) {
            return $snapshot;
        }

        try {
            Cache::lock(self::CACHE_KEY.':building', 5)->block(2, function (): void {
                if (self::stored() === null) {
                    Cache::put(self::CACHE_KEY, self::build(), 1);
                }
            });
        } catch (LockTimeoutException) {
            // Whoever holds the lock is taking too long; answering from the database beats not answering.
            return self::build();
        }

        return self::stored() ?? self::build();
    }

    /**
     * @return array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, items: array<string, array{available: int, max: int, pool: string, units: int}>, held: int}|null
     */
    private static function stored(): ?array
    {
        /** @var array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, items: array<string, array{available: int, max: int, pool: string, units: int}>, held: int}|null $snapshot */
        $snapshot = Cache::get(self::CACHE_KEY);

        return $snapshot;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, items: array<string, array{available: int, max: int, pool: string, units: int}>, held: int}
     */
    private static function build(): array
    {
        $drop = Drop::featured()?->load('items.product.bundle');

        if ($drop === null) {
            return ['drop' => null, 'announced' => self::announced(), 'items' => [], 'held' => 0];
        }

        $stock = self::stockOf($drop);

        return [
            'drop' => [
                'id' => $drop->id,
                'name' => $drop->name,
                'state' => $drop->status()->value,
                'opens_at' => $drop->opens_at->toIso8601ZuluString(),
                'closes_at' => $drop->effectiveClosesAt()?->toIso8601ZuluString(),
            ],
            'announced' => self::announced(),
            ...$stock,
        ];
    }

    /**
     * What's left of each item of one drop, not necessarily the featured one, and how many checkouts are open. The
     * early-access page reads this for a drop the public can't see yet.
     *
     * @return array{items: array<string, array{available: int, max: int, pool: string, units: int}>, held: int}
     */
    public static function stockOf(Drop $drop): array
    {
        $drop->loadMissing('items.product.bundle');
        $items = [];

        foreach ($drop->items as $item) {
            $available = self::available($drop, $item);

            if ($available !== null) {
                $items[$item->product->slug] = ['available' => $available, 'max' => $item->max_per_order, ...self::pool($drop, $item)];
            }
        }

        return ['items' => $items, 'held' => $drop->orders()->where('status', OrderStatus::Pending)->count()];
    }

    /**
     * The next drop announced before it is published, for the "Next drop: Saturday 14 November" messages.
     *
     * @return array{opens_at: string, label: string}|null
     */
    private static function announced(): ?array
    {
        $drop = Drop::announced();

        return $drop instanceof Drop ? ['opens_at' => $drop->opens_at->toIso8601ZuluString(), 'label' => $drop->announcedLabel()] : null;
    }

    /**
     * Which stock an item sells from: the slug of the product whose `available` it draws on, and how many of that
     * it uses each. An item with stock of its own is its own pool, one at a time; a bundle uses its component's.
     * The order form adds up everything chosen from one pool, so a mince pack and 500g mince can't both be had
     * from the same last few.
     *
     * @return array{pool: string, units: int}
     */
    private static function pool(Drop $drop, DropItem $item): array
    {
        $bundle = $item->product->bundle;

        if ($item->hasOwnStock() || ! $bundle instanceof Bundle) {
            return ['pool' => $item->product->slug, 'units' => 1];
        }

        $component = $drop->items->firstWhere('product_id', $bundle->component_product_id);

        return ['pool' => $component instanceof DropItem ? $component->product->slug : $item->product->slug, 'units' => max(1, $bundle->units)];
    }

    /**
     * How many of an item could be ordered now. A box packed from another box's stock can have as many as that
     * stock covers. Null for the delivery fee.
     */
    private static function available(Drop $drop, DropItem $item): ?int
    {
        if ($item->hasOwnStock()) {
            return (int) $item->available;
        }

        $bundle = $item->product->bundle;

        if ($item->product->type === ProductType::Delivery || ! $bundle instanceof Bundle) {
            return null;
        }

        $stock = $drop->items->firstWhere('product_id', $bundle->component_product_id);

        return $stock instanceof DropItem ? intdiv((int) $stock->available, max(1, $bundle->units)) : 0;
    }
}
