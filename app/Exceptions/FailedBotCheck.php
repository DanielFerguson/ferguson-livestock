<?php

namespace App\Exceptions;

use App\Support\Turnstile;
use RuntimeException;

/**
 * Cloudflare's bot check didn't pass, so checkout didn't start.
 */
class FailedBotCheck extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(Turnstile::FAILED_MESSAGE);
    }
}
