<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A drop item's quantity can't drop below the units customers are already holding or have bought.
 */
class QuantityBelowCommitted extends RuntimeException
{
    public function __construct(public readonly int $committed)
    {
        parent::__construct("{$committed} units are already held or sold, so the quantity can't be lower than that.");
    }
}
