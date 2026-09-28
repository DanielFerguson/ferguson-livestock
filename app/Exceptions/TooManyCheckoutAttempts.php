<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Someone has started checkout too many times in a short while, which is what a script grabbing stock does.
 */
class TooManyCheckoutAttempts extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        parent::__construct("That’s a lot of tries in a short time. Please wait {$minutes} ".str('minute')->plural($minutes).' and try again.');
    }
}
