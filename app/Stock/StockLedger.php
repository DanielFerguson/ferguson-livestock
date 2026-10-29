<?php

declare(strict_types=1);

namespace App\Stock;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\CheckoutBusy;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InsufficientStock;
use App\Models\Bundle;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
     * Long enough for a rush to queue up behind one stock row, short enough to answer "busy" before the customer gives up.
     */
    private const string LOCK_TIMEOUT = '5s';

    /**
     * Postgres's SQLSTATE for a lock that wasn't granted in time.
     */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    /**
     * @param  array<int, int>  $quantities  drop item ID => quantity, including the delivery fee for deliveries
     *
     * @throws DropNotOpen
     * @throws InsufficientStock
     */
    public function reserve(Drop $drop, array $quantities, DeliveryMethod $deliveryMethod, ?CarbonImmutable $deliveryDay, string $sessionFingerprint, CarbonImmutable $expiresAt): Order
    {
        $order = $this->waitingForLocks(fn (): Order => DB::transaction(function () use ($drop, $quantities, $deliveryMethod, $deliveryDay, $sessionFingerprint, $expiresAt): Order {
            // Postgres only: give up on a stock row that stays locked, rather than queueing behind it indefinitely.
            DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");

            $drop = Drop::query()->with('items.product.bundle')->findOrFail($drop->id);

            if (! $drop->status()->isOpen()) {
                throw new DropNotOpen;
            }

            $lines = $this->lines($drop, $quantities);
            $this->takeStock($lines);

            $order = Order::query()->create([
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
        }, attempts: 3));

        DropSnapshot::forget();

        return $order;
    }

    /**
     * Take back the stock of an order that was released but then paid for after all. Returns false, taking nothing,
     * when there isn't enough left. Call it inside the transaction that marks the order paid, so a refusal can undo it.
     */
    public function reclaim(Order $order): bool
    {
        $claimed = Order::query()->whereKey($order->id)->whereNotNull('released_at')->update(['released_at' => null, 'updated_at' => now()]);

        // Never released, so the stock is still held for it.
        if ($claimed === 0) {
            return true;
        }

        $needed = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereNotNull('stock_drop_item_id')
            ->get()
            ->groupBy('stock_drop_item_id')
            ->map(fn ($items): int => $items->sum(fn (OrderItem $item): int => $item->quantity * $item->stock_units))
            ->sortKeys()
            ->all();

        /** @var array<int, int> $available */
        $available = DropItem::query()->whereKey(array_keys($needed))->orderBy('id')->lockForUpdate()->pluck('available', 'id')->all();

        foreach ($needed as $dropItemId => $units) {
            if ($units > ($available[$dropItemId] ?? 0)) {
                return false;
            }
        }

        foreach ($needed as $dropItemId => $units) {
            DropItem::query()->whereKey($dropItemId)->decrement('available', $units);
        }

        DB::afterCommit(DropSnapshot::forget(...));

        return true;
    }

    /**
     * Put an order's stock back on sale. Returns false when it was already released.
     */
    public function release(Order $order): bool
    {
        $released = DB::transaction(function () use ($order): bool {
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

        if ($released) {
            // After the outer transaction too, when an order is expired and released together.
            DB::afterCommit(DropSnapshot::forget(...));
        }

        return $released;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $hold
     * @return T
     *
     * @throws CheckoutBusy
     */
    private function waitingForLocks(callable $hold): mixed
    {
        try {
            return $hold();
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new CheckoutBusy;
            }

            throw $exception;
        }
    }

    /**
     * What of an order someone wants is still in stock, worked out as checkout does: items that share stock (a mince
     * pack and 500g mince, which both come from the same mince) draw on it together, largest bundles first. Used to
     * trim an order after someone else got there first, so that trying again can work.
     *
     * @param  array<int, int>  $quantities  drop item ID => quantity wanted
     * @return array<int, int> for each item that has to be cut, how many can still be had (0 when none)
     */
    public function fit(Drop $drop, array $quantities): array
    {
        $drop = Drop::query()->with('items.product.bundle')->findOrFail($drop->id);

        $wanted = [];

        foreach ($quantities as $dropItemId => $quantity) {
            $item = $drop->items->firstWhere('id', $dropItemId);

            if ($item instanceof DropItem && $item->product->type !== ProductType::Delivery) {
                $wanted[] = [$item, $quantity, max(1, $item->product->bundle->units ?? 1)];
            }
        }

        // The biggest bundles first, so a pack is kept and the singles beside it are trimmed, not the other way
        // round, which would leave the pack's worth of stock unsold. Keep in step with apply() in order-form.js.
        usort($wanted, fn (array $a, array $b): int => [$b[2], $a[0]->id] <=> [$a[2], $b[0]->id]);

        $left = [];
        $cut = [];

        foreach ($wanted as [$item, $quantity, $units]) {
            $stock = $this->stockOf($drop, $item);

            if (! $stock instanceof DropItem) {
                $cut[$item->id] = 0;

                continue;
            }

            $left[$stock->id] ??= (int) $stock->available;
            $fits = min($quantity, intdiv($left[$stock->id], $units));
            $left[$stock->id] -= $fits * $units;

            if ($fits < $quantity) {
                $cut[$item->id] = $fits;
            }
        }

        return $cut;
    }

    /**
     * The drop item whose stock an item sells from: its own, its bundle's component, or none for the delivery fee.
     */
    private function stockOf(Drop $drop, DropItem $item): ?DropItem
    {
        $bundle = $item->product->bundle;

        $stock = match (true) {
            $item->hasOwnStock() => $item,
            $bundle instanceof Bundle => $drop->items->firstWhere('product_id', $bundle->component_product_id),
            default => null,
        };

        return $stock instanceof DropItem ? $stock : null;
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

            $bundle = $item->product->bundle;
            $stock = $this->stockOf($drop, $item);

            // Only the delivery fee goes without stock. Anything else whose stock is missing from the drop, like a
            // 10kg box whose 5kg boxes aren't in it, is sold out, as the order page already shows it.
            if (! $stock instanceof DropItem && $item->product->type !== ProductType::Delivery) {
                throw new InsufficientStock([$item->id => 0]);
            }

            $lines[] = new StockLine($item, $quantity, $stock instanceof DropItem ? $stock : null, $stock instanceof DropItem ? $bundle->units ?? 1 : (0));
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
