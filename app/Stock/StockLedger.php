<?php

namespace App\Stock;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InsufficientStock;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Holds and releases a drop's stock for orders.
 *
 * Holding happens in one transaction that locks the stock rows in ID order, so concurrent orders queue behind
 * each other (and can't deadlock) and each order gets all of its items or none. Releasing is guarded by the
 * order's `released_at`, so stock goes back exactly once. The database's CHECK constraint backs both up:
 * `available` can never go below zero or above the quantity.
 */
final class StockLedger
{
    /**
     * @param  array<int, int>  $quantities  drop item ID => quantity, including the delivery fee for deliveries
     *
     * @throws DropNotOpen
     * @throws InsufficientStock
     */
    public function reserve(Drop $drop, array $quantities, DeliveryMethod $deliveryMethod, ?CarbonImmutable $deliveryDay, string $sessionFingerprint, CarbonImmutable $expiresAt): Order
    {
        return DB::transaction(function () use ($drop, $quantities, $deliveryMethod, $deliveryDay, $sessionFingerprint, $expiresAt): Order {
            $drop = Drop::query()->with('items.product')->findOrFail($drop->id);

            if (! $drop->status()->isOpen()) {
                throw new DropNotOpen;
            }

            $lines = $this->lines($drop, $quantities);
            $this->takeStock($lines);

            $order = Order::create([
                'drop_id' => $drop->id,
                'status' => OrderStatus::Pending,
                'delivery_method' => $deliveryMethod,
                'delivery_day' => $deliveryDay,
                'total' => array_sum(array_map(fn (StockLine $line): int => $line->item->price * $line->quantity, $lines)),
                'session_fingerprint' => $sessionFingerprint,
                'expires_at' => $expiresAt,
            ]);

            foreach ($lines as $line) {
                $order->items()->create([
                    'drop_item_id' => $line->item->id,
                    'product_id' => $line->item->product_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->item->price,
                    'stock_drop_item_id' => $line->stock?->id,
                    'stock_units' => $line->unitsEach,
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * Put an order's stock back on sale. Returns false when it was already released.
     */
    public function release(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $claimed = Order::query()->whereKey($order->id)->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);

            if ($claimed === 0) {
                return false;
            }

            $units = OrderItem::query()
                ->where('order_id', $order->id)
                ->whereNotNull('stock_drop_item_id')
                ->get()
                ->groupBy('stock_drop_item_id')
                ->map(fn ($items): int => $items->sum(fn (OrderItem $item): int => $item->quantity * $item->stock_units))
                ->sortKeys();

            foreach ($units as $dropItemId => $count) {
                DropItem::query()->whereKey($dropItemId)->increment('available', $count);
            }

            $order->refresh();

            return true;
        });
    }

    /**
     * @param  array<int, int>  $quantities
     * @return list<StockLine>
     */
    private function lines(Drop $drop, array $quantities): array
    {
        $lines = [];

        foreach ($quantities as $dropItemId => $quantity) {
            $item = $drop->items->firstWhere('id', $dropItemId);

            if (! $item instanceof DropItem) {
                throw new InvalidArgumentException("Drop item {$dropItemId} isn’t in this drop.");
            }

            $stock = match (true) {
                $item->hasOwnStock() => $item,
                $item->product->stock_product_id !== null => $drop->items->firstWhere('product_id', $item->product->stock_product_id),
                default => null,
            };

            $lines[] = new StockLine($item, $quantity, $stock instanceof DropItem ? $stock : null, $stock === null ? 0 : $item->product->stock_units);
        }

        return $lines;
    }

    /**
     * @param  list<StockLine>  $lines
     *
     * @throws InsufficientStock
     */
    private function takeStock(array $lines): void
    {
        $needed = [];

        foreach ($lines as $line) {
            if ($line->stock !== null) {
                $needed[$line->stock->id] = ($needed[$line->stock->id] ?? 0) + $line->quantity * $line->unitsEach;
            }
        }

        ksort($needed);

        /** @var array<int, int> $available */
        $available = DropItem::query()->whereKey(array_keys($needed))->orderBy('id')->lockForUpdate()->pluck('available', 'id')->all();

        $short = [];

        foreach ($lines as $line) {
            if ($line->stock !== null && $needed[$line->stock->id] > ($available[$line->stock->id] ?? 0)) {
                $short[$line->item->id] = intdiv($available[$line->stock->id] ?? 0, $line->unitsEach);
            }
        }

        if ($short !== []) {
            throw new InsufficientStock($short);
        }

        foreach ($needed as $dropItemId => $units) {
            $taken = DropItem::query()->whereKey($dropItemId)->where('available', '>=', $units)->decrement('available', $units);

            // Unreachable while the rows are locked, but an order must never exist without its stock.
            if ($taken === 0) {
                throw new InsufficientStock([]);
            }
        }
    }
}
