<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Meta's Conversions API: reports events from the server, which ad blockers and browser privacy settings can't stop.
 */
class MetaConversions
{
    /**
     * Whether the pixel ID and access token are both set. Without them nothing is sent.
     */
    public static function isEnabled(): bool
    {
        return filled(config('services.meta.pixel_id')) && filled(config('services.meta.conversions_api_token'));
    }

    public static function endpoint(): string
    {
        return sprintf('https://graph.facebook.com/%s/%s/events', config()->string('services.meta.graph_api_version'), config()->string('services.meta.pixel_id'));
    }

    /**
     * The token goes in the body, not the URL, so it never shows up in an error message or a log.
     *
     * @param  array<string, mixed>  $event  one event from MetaEvents
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function send(array $event): void
    {
        Http::connectTimeout(3)->timeout(10)->post(self::endpoint(), array_filter([
            'data' => [$event],
            'access_token' => config('services.meta.conversions_api_token'),
            'test_event_code' => config('services.meta.test_event_code'),
        ], filled(...)))->throw();
    }
}
