<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Drop;
use App\Models\DropItem;
use RuntimeException;

/**
 * Someone else got there first: there isn't enough left for part of the order, so none of it was held.
 */
class InsufficientStock extends RuntimeException
{
    /**
     * @param  array<int, int>  $available  for each drop item that's short, how many could still be ordered
     */
    public function __construct(public readonly array $available)
    {
        parent::__construct('There isn’t enough left for part of this order.');
    }

    /**
     * "Only 3 × 5kg Beef Box left. 500g Beef Mince is sold out." The drop's items must be loaded with their products.
     */
    public function describe(Drop $drop): string
    {
        $parts = [];

        foreach ($this->available as $dropItemId => $left) {
            $name = $drop->items->first(fn (DropItem $item): bool => $item->id === $dropItemId)?->product->name ?? 'One of the items';

            $parts[] = $left > 0 ? "Only {$left} × {$name} left." : "{$name} is sold out.";
        }

        return $parts === [] ? 'There isn’t enough stock left for this order.' : implode(' ', $parts);
    }
}
