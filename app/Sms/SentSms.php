<?php

namespace App\Sms;

/**
 * A text the provider has accepted.
 */
final readonly class SentSms
{
    public function __construct(
        public string $sid,
        public int $segments,
    ) {}
}
