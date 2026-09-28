<?php

namespace App\Exceptions;

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
}
