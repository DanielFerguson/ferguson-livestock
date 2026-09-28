<?php

namespace App\Stock;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\DropItem;
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
     * @return array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, server_time: string, items: array<string, array{available: int, max: int}>, held: int}
     */
    public static function current(): array
    {
        /** @var array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, items: array<string, array{available: int, max: int}>, held: int} $snapshot */
        $snapshot = Cache::remember(self::CACHE_KEY, 1, fn (): array => self::build());

        return [...$snapshot, 'server_time' => now()->toIso8601ZuluString('millisecond')];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{drop: array{id: int, name: string, state: string, opens_at: string, closes_at: string|null}|null, announced: array{opens_at: string, label: string}|null, items: array<string, array{available: int, max: int}>, held: int}
     */
    private static function build(): array
    {
        $drop = Drop::featured()?->load('items.product');

        if ($drop === null) {
            return ['drop' => null, 'announced' => self::announced(), 'items' => [], 'held' => 0];
        }

        $items = [];

        foreach ($drop->items as $item) {
            $available = self::available($drop, $item);

            if ($available !== null) {
                $items[$item->product->slug] = ['available' => $available, 'max' => $item->max_per_order];
            }
        }

        return [
            'drop' => [
                'id' => $drop->id,
                'name' => $drop->name,
                'state' => $drop->status()->value,
                'opens_at' => $drop->opens_at->toIso8601ZuluString(),
                'closes_at' => $drop->effectiveClosesAt()?->toIso8601ZuluString(),
            ],
            'announced' => self::announced(),
            'items' => $items,
            'held' => $drop->orders()->where('status', OrderStatus::Pending)->count(),
        ];
    }

    /**
     * The next drop announced before it is published, for the "Next drop: Saturday 14 November" messages.
     *
     * @return array{opens_at: string, label: string}|null
     */
    private static function announced(): ?array
    {
        $drop = Drop::announced();

        return $drop === null ? null : ['opens_at' => $drop->opens_at->toIso8601ZuluString(), 'label' => $drop->announcedLabel()];
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

        if ($item->product->type === ProductType::Delivery || $item->product->stock_product_id === null) {
            return null;
        }

        $stock = $drop->items->firstWhere('product_id', $item->product->stock_product_id);

        return $stock instanceof DropItem ? intdiv((int) $stock->available, max(1, $item->product->stock_units)) : 0;
    }
}
