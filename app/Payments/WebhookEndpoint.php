<?php

declare(strict_types=1);

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
