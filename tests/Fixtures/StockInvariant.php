<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\Drop;
use App\Models\DropItem;
use App\Models\OrderItem;

/**
 * What must be true of a drop's stock whatever happened to it: every unit is either on sale or in an order that
 * hasn't given it back, never both and never neither.
 */
final class StockInvariant
{
    /**
     * @return array<string, array{quantity: int, available: int, held: int}> by product slug
     */
    public static function check(Drop $drop): array
    {
        $report = [];

        foreach (DropItem::query()->with('product')->where('drop_id', $drop->id)->whereNotNull('quantity')->get() as $item) {
            $held = (int) OrderItem::query()
                ->where('stock_drop_item_id', $item->id)
                ->whereHas('order', fn ($orders) => $orders->whereNull('released_at'))
                ->get()
                ->sum(fn (OrderItem $line): int => $line->quantity * $line->stock_units);

            $quantity = (int) $item->quantity;
            $available = (int) $item->available;

            expect($available)->toBe($quantity - $held)
                ->and($available)->toBeGreaterThanOrEqual(0)
                ->and($available)->toBeLessThanOrEqual($quantity);

            $report[$item->product->slug] = ['quantity' => $quantity, 'available' => $available, 'held' => $held];
        }

        return $report;
    }
}
