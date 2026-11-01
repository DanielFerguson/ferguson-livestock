<?php

declare(strict_types=1);

use App\Jobs\SendMetaEvent;
use App\Support\Turnstile;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and browser tests boot the application against the Postgres test
| database. Feature tests stub Vite so they don't need a front-end build;
| browser tests render the real built assets. Unit tests stay framework-free.
|
*/

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| `composer test:fast` runs Pest with --tia: it records which source files each
| test touches, then re-runs only the tests a change can affect and replays the
| rest from cache. It's switched on for local runs only, so CI and `composer ci`
| always run the whole suite. The recorded graph lives outside the repository.
|
*/

pest()->tia()->locally();

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

// Child processes can't see a test's uncommitted transaction, so these tests commit and truncate instead.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/**
 * Switch on the Turnstile bot check, with Cloudflare calling every token genuine or not.
 */
function fakeTurnstile(bool $genuine): void
{
    config(['services.turnstile.site_key' => 'test-site-key', 'services.turnstile.secret_key' => 'test-secret-key']);

    Http::preventStrayRequests();
    Http::fake([Turnstile::VERIFY_URL => Http::response(['success' => $genuine, 'error-codes' => $genuine ? [] : ['invalid-input-response']])]);
}

/**
 * Switch on Meta ads tracking: the pixel and, unless told otherwise, the Conversions API.
 */
function enableMetaAds(bool $conversionsApi = true): void
{
    config([
        'services.meta.pixel_id' => '1234567890',
        'services.meta.conversions_api_token' => $conversionsApi ? 'test-meta-token' : null,
    ]);
}

/**
 * The one event queued for Meta. Fails the test unless exactly one was queued; call Queue::fake() first.
 *
 * @return array<string, mixed>
 */
function queuedMetaEvent(): array
{
    $events = [];

    Queue::assertPushed(SendMetaEvent::class, function (SendMetaEvent $job) use (&$events): bool {
        $events[] = $job->event;

        return true;
    });

    expect($events)->toHaveCount(1);

    return $events[0];
}
