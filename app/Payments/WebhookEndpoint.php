<?php

namespace App\Payments;

final readonly class WebhookEndpoint
{
    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public string $url,
        public bool $enabled,
        public array $events,
    ) {}
}
