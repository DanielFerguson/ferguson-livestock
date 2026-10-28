<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to a broadcast that the rules don't allow. The title is a short heading for the admin's notification.
 */
class CannotChangeBroadcast extends RuntimeException
{
    public function __construct(string $message, public readonly string $title = 'That can’t be done')
    {
        parent::__construct($message);
    }
}
