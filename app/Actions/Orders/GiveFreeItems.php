<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\CannotChangeOrder;
use App\Exceptions\CheckoutBusy;
use App\Exceptions\InsufficientStock;
use App\Models\DropItem;
use App\Models\Order;
use App\Stock\StockLedger;

/**
 * Gives a customer something extra on their order, like a sample pack at pickup. It comes out of the drop's stock
 * like anything sold, so the stock stays right, but it's free: what they paid doesn't change. The drop may have
 * closed, since pickups come after. Nothing is sent.
 */
final readonly class GiveFreeItems
{
    /** Orders whose stock is held for good: paid for, or put aside for cash. */
    private const array CAN_TAKE_FREE_ITEMS = [OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed];

    public function __construct(private StockLedger $stock) {}

    public static function canTakeFreeItems(Order $order): bool
    {
        return in_array($order->status, self::CAN_TAKE_FREE_ITEMS, true) && $order->released_at === null;
    }

    /**
     * @param  array<int, int>  $quantities  drop item ID => quantity
     *
     * @throws CannotChangeOrder
     */
    public function __invoke(Order $order, array $quantities): Order
    {
        $drop = $order->drop()->with('items.product')->firstOrFail();
        $quantities = array_filter($quantities, fn (int $quantity): bool => $quantity > 0);

        if ($quantities === []) {
            throw new CannotChangeOrder('Choose at least one item to give.');
        }

        foreach (array_keys($quantities) as $dropItemId) {
            $item = $drop->items->first(fn (DropItem $item): bool => $item->id === $dropItemId);

            if (! $item instanceof DropItem || $item->product->type === ProductType::Delivery) {
                throw new CannotChangeOrder("Something you chose isn’t sold in {$drop->name}.");
            }
        }

        try {
            $this->stock->addFree($order, $quantities, function (Order $order): void {
                if (! self::canTakeFreeItems($order)) {
                    throw new CannotChangeOrder("Order {$order->reference()} is {$order->status->getLabel()}; only paid, fulfilled and cash owed orders can take free items.");
                }
            });
        } catch (InsufficientStock $exception) {
            throw new CannotChangeOrder($exception->describe($drop).' If you’ve got more on hand, raise its stock on the drop first.', $exception->getCode(), previous: $exception);
        } catch (CheckoutBusy $exception) {
            throw new CannotChangeOrder('Lots of orders are coming in right now. Try again in a moment.', $exception->getCode(), previous: $exception);
        }

        return $order->refresh()->load(['items.product', 'drop']);
    }
}
