<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * So many people were buying the same stock at once that this order couldn't get to it in time. Nothing was held.
 */
class CheckoutBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('We’re very busy right now and couldn’t hold your order. Please try again in a few seconds.');
    }
}
