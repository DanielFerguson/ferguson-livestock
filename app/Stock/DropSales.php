<?php

declare(strict_types=1);

namespace App\Stock;

use App\Enums\OrderStatus;
use App\Models\DropItem;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * How one item of a drop is selling: units sold, units held at checkout, units left, and revenue.
 */
final class DropSales
{
    /**
     * @return array{sold: int, at_checkout: int, left: int|null, revenue: int}
     */
    public static function forItem(DropItem $item): array
    {
        $sold = self::lines($item, [OrderStatus::Paid, OrderStatus::Fulfilled]);

        return [
            'sold' => $sold->sum(fn (OrderItem $line): int => $line->quantity),
            'at_checkout' => self::quantity($item, [OrderStatus::Pending, OrderStatus::Processing]),
            'left' => $item->hasOwnStock() ? $item->available : null,
            'revenue' => $sold->sum(fn (OrderItem $line): int => $line->lineTotal()),
        ];
    }

    /**
     * @param  list<OrderStatus>  $statuses
     */
    private static function quantity(DropItem $item, array $statuses): int
    {
        return self::lines($item, $statuses)->sum(fn (OrderItem $line): int => $line->quantity);
    }

    /**
     * @param  list<OrderStatus>  $statuses
     * @return Collection<int, OrderItem>
     */
    private static function lines(DropItem $item, array $statuses): Collection
    {
        return $item->orderItems->filter(fn (OrderItem $line): bool => in_array($line->order->status, $statuses, true))->toBase();
    }
}
