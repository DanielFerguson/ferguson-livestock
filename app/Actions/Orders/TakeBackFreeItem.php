<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Exceptions\CannotChangeOrder;
use App\Models\OrderItem;
use App\Stock\StockLedger;

/**
 * Takes a free item back off an order, for one given by mistake, and puts its stock back on sale. Only free items:
 * what a customer paid for stays on their order.
 */
final readonly class TakeBackFreeItem
{
    public function __construct(private StockLedger $stock) {}

    /**
     * @throws CannotChangeOrder
     */
    public function __invoke(OrderItem $line): void
    {
        if (! $line->free) {
            throw new CannotChangeOrder("{$line->product->name} was paid for, so it stays on the order.");
        }

        if (! $this->stock->takeBackFree($line)) {
            throw new CannotChangeOrder("That {$line->product->name} has already come off the order, or the order was cancelled.");
        }
    }
}
