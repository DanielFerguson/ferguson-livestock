<?php

namespace App\Stock;

use App\Models\DropItem;

/**
 * One item of an order and whose stock it uses: its own, another item's (the 10kg box uses the 5kg box's), or
 * none (the delivery fee).
 */
final readonly class StockLine
{
    public function __construct(
        public DropItem $item,
        public int $quantity,
        public ?DropItem $stock,
        public int $unitsEach,
    ) {}
}
