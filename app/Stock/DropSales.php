<?php

declare(strict_types=1);

namespace App\Stock;

use App\Enums\OrderStatus;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * How one item of a drop is selling: units sold, units given away, units held at checkout, units left, and revenue.
 */
final class DropSales
{
    /**
     * @return array{sold: int, given_away: int, at_checkout: int, left: int|null, revenue: int}
     */
    public static function forItem(DropItem $item): array
    {
        // A cash order put aside for a friend is sold, though the cash may not be in hand yet. A free item, like a
        // sample pack at pickup, takes stock but isn't a sale.
        $placed = self::lines($item, [OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed]);
        $givenAway = $placed->filter(fn (OrderItem $line): bool => $line->free);
        $sold = $placed->reject(fn (OrderItem $line): bool => $line->free);

        return [
            'sold' => $sold->sum(fn (OrderItem $line): int => $line->quantity),
            'given_away' => $givenAway->sum(fn (OrderItem $line): int => $line->quantity),
            'at_checkout' => self::quantity($item, [OrderStatus::Pending, OrderStatus::Processing]),
            'left' => $item->hasOwnStock() ? $item->available : null,
            'revenue' => $sold->sum(fn (OrderItem $line): int => $line->lineTotal()),
        ];
    }

    /**
     * How many more of an item can be had: its own stock, or for a bundle, how many its component's stock makes.
     * The drop's items must be loaded with their products and bundles.
     */
    public static function left(Drop $drop, DropItem $item): int
    {
        if ($item->hasOwnStock()) {
            return (int) $item->available;
        }

        $bundle = $item->product->bundle;
        $component = $bundle === null ? null : $drop->items->firstWhere('product_id', $bundle->component_product_id);

        return $component instanceof DropItem && $bundle !== null ? intdiv((int) $component->available, max(1, $bundle->units)) : 0;
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
