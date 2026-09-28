<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Orders can only be placed while a drop is live.
 */
class DropNotOpen extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This drop isn’t taking orders right now.');
    }
}
