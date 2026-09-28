<?php

namespace App\Support;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * A drop's paid orders in the order they're packed and driven: deliveries by day, then suburb and postcode,
 * then farm pickups by name.
 */
final readonly class DeliveryRun
{
    private const array CSV_COLUMNS = ['order', 'name', 'phone', 'email', 'method', 'day', 'address', 'suburb', 'postcode', 'items', 'total'];

    /**
     * @param  Collection<int, Order>  $deliveries
     * @param  Collection<int, Order>  $pickups
     */
    public function __construct(
        public Drop $drop,
        public Collection $deliveries,
        public Collection $pickups,
    ) {}

    public static function forDrop(Drop $drop): self
    {
        $orders = Order::query()
            ->whereBelongsTo($drop)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Fulfilled])
            ->with('items.product')
            ->get();

        $isDelivery = fn (Order $order): bool => $order->delivery_method === DeliveryMethod::Delivery;

        return new self(
            $drop,
            $orders->filter($isDelivery)->sortBy([
                fn (Order $a, Order $b): int => ($a->delivery_day?->toDateString() ?? '') <=> ($b->delivery_day?->toDateString() ?? ''),
                fn (Order $a, Order $b): int => strcasecmp($a->shipping_address['city'] ?? '', $b->shipping_address['city'] ?? ''),
                fn (Order $a, Order $b): int => ($a->shipping_address['postal_code'] ?? '') <=> ($b->shipping_address['postal_code'] ?? ''),
            ])->values(),
            $orders->reject($isDelivery)->sortBy(fn (Order $order): string => strtolower($order->customer_name ?? ''))->values(),
        );
    }

    /**
     * "1 × 5kg Beef Box, 2 × 500g Beef Mince". The delivery fee isn't something to pack, so it's left out.
     */
    public static function items(Order $order): string
    {
        return $order->items
            ->reject(fn (OrderItem $item): bool => $item->product->type === ProductType::Delivery)
            ->map(fn (OrderItem $item): string => "{$item->quantity} × {$item->product->name}")
            ->implode(', ');
    }

    public function toCsv(): string
    {
        $file = fopen('php://temp', 'r+');

        if ($file === false) {
            return '';
        }

        fputcsv($file, self::CSV_COLUMNS, escape: '');

        foreach ($this->deliveries->concat($this->pickups) as $order) {
            fputcsv($file, [
                $order->reference(),
                SpreadsheetCell::safe($order->customer_name),
                $order->phone === null ? null : AustralianMobile::readable($order->phone),
                SpreadsheetCell::safe($order->email),
                $order->delivery_method->getLabel(),
                $order->delivery_day?->format('D j M'),
                SpreadsheetCell::safe(trim(($order->shipping_address['line1'] ?? '').' '.($order->shipping_address['line2'] ?? ''))),
                SpreadsheetCell::safe($order->shipping_address['city'] ?? null),
                $order->shipping_address['postal_code'] ?? null,
                self::items($order),
                Money::format($order->total),
            ], escape: '');
        }

        rewind($file);
        $csv = (string) stream_get_contents($file);
        fclose($file);

        return $csv;
    }
}
