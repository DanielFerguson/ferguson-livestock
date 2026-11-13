<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * What a drop has taken: revenue after refunds, split into beef and delivery fees, plus the money not in hand yet.
 */
final class DropRevenue
{
    /** A cash order put aside for a friend is sold, though the cash may not be in hand yet. */
    private const array SOLD = [OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed];

    private const array AT_CHECKOUT = [OrderStatus::Pending, OrderStatus::Processing];

    /**
     * @return array{revenue: int, refunded: int, orders: int, beef: int, delivery_fees: int, cash_owed: int, cash_orders: int, at_checkout: int, orders_at_checkout: int}
     */
    public static function forDrop(Drop $drop): array
    {
        $orders = $drop->orders()
            ->whereIn('status', [...self::SOLD, ...self::AT_CHECKOUT])
            ->with('items.product')
            ->get(['id', 'drop_id', 'status', 'total', 'amount_refunded'])
            ->toBase();

        $sold = $orders->filter(fn (Order $order): bool => in_array($order->status, self::SOLD, true));
        $cashOwed = $sold->filter(fn (Order $order): bool => $order->status === OrderStatus::CashOwed);
        $atCheckout = $orders->filter(fn (Order $order): bool => in_array($order->status, self::AT_CHECKOUT, true));

        $lines = $sold->flatMap(fn (Order $order): Collection => $order->items->toBase());
        $isDeliveryFee = fn (OrderItem $line): bool => $line->product->type === ProductType::Delivery;

        $refunded = $sold->sum(fn (Order $order): int => $order->amount_refunded);

        return [
            'revenue' => self::total($sold) - $refunded,
            'refunded' => $refunded,
            'orders' => $sold->count(),
            'beef' => self::lineTotal($lines->reject($isDeliveryFee)),
            'delivery_fees' => self::lineTotal($lines->filter($isDeliveryFee)),
            'cash_owed' => self::total($cashOwed),
            'cash_orders' => $cashOwed->count(),
            'at_checkout' => self::total($atCheckout),
            'orders_at_checkout' => $atCheckout->count(),
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private static function total(Collection $orders): int
    {
        return $orders->sum(fn (Order $order): int => $order->total);
    }

    /**
     * @param  Collection<int, OrderItem>  $lines
     */
    private static function lineTotal(Collection $lines): int
    {
        return $lines->sum(fn (OrderItem $line): int => $line->lineTotal());
    }
}
