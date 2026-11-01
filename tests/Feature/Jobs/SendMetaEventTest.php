<?php

declare(strict_types=1);

use App\Jobs\SendMetaEvent;
use App\Support\MetaConversions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

const META_EVENTS_URL = 'https://graph.facebook.com/v26.0/1234567890/events';

/**
 * @return array<string, mixed>
 */
function metaLead(): array
{
    return ['event_name' => 'Lead', 'event_time' => 1700000000, 'event_id' => 'lead-1', 'action_source' => 'website', 'event_source_url' => 'https://fergusonlivestock.com.au/', 'user_data' => ['ph' => 'hashed']];
}

function sendMetaLead(): SendMetaEvent
{
    $job = (new SendMetaEvent(metaLead()))->withFakeQueueInteractions();
    $job->handle(app(MetaConversions::class));

    return $job;
}

it('sends the event to the shop’s pixel, with the token in the body rather than the address', function (): void {
    enableMetaAds();
    Http::preventStrayRequests();
    Http::fake([META_EVENTS_URL => Http::response(['events_received' => 1])]);

    sendMetaLead()->assertNotFailed();

    Http::assertSent(fn (Request $request): bool => $request->url() === META_EVENTS_URL
        && $request->data() === ['data' => [metaLead()], 'access_token' => 'test-meta-token']);
});

it('sends the event to Events Manager’s test tab while a test event code is set', function (): void {
    enableMetaAds();
    config(['services.meta.test_event_code' => 'TEST123']);
    Http::preventStrayRequests();
    Http::fake([META_EVENTS_URL => Http::response(['events_received' => 1])]);

    sendMetaLead();

    Http::assertSent(fn (Request $request): bool => $request['test_event_code'] === 'TEST123');
});

it('gives up at once when Meta turns the event down, since retrying won’t change its answer', function (): void {
    enableMetaAds();
    Http::preventStrayRequests();
    Http::fake([META_EVENTS_URL => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);

    sendMetaLead()->assertFailed();
});

it('tries again later when Meta is down or busy', function (int $status): void {
    enableMetaAds();
    Http::preventStrayRequests();
    Http::fake([META_EVENTS_URL => Http::response([], $status)]);

    expect(fn (): SendMetaEvent => sendMetaLead())->toThrow(RequestException::class);
})->with([500, 503, 429]);
