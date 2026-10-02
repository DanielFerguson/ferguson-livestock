<?php

use App\Support\Turnstile;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
