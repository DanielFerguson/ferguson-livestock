<?php

declare(strict_types=1);

use App\Support\RetryingPhpRedisConnector;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Fakes\FakeRedisClient;

/**
 * Opens a connection through the connector with a fake phpredis client, which the `new Redis` inside it can't take.
 */
function connectToCache(Redis $client): void
{
    (fn () => $this->establishConnection($client, ['host' => 'cache.example', 'port' => 6379, 'timeout' => 5]))
        ->call(new RetryingPhpRedisConnector);
}

it('connects once the cache stops resetting the TLS handshake', function (): void {
    Sleep::fake();
    $logged = [];
    Log::listen(function (MessageLogged $log) use (&$logged): void {
        $logged[] = [$log->level, $log->message, $log->context];
    });
    $cache = new FakeRedisClient(
        fn (): never => throw new ErrorException('Redis::connect(): SSL: Connection reset by peer'),
        fn (): bool => true,
    );

    connectToCache($cache);

    expect($cache->connectAttempts)->toBe(2);
    Sleep::assertSequence([Sleep::for(100)->milliseconds()]);
    expect($logged)->toBe([
        ['warning', 'Retrying a Redis connection the cache turned away.', ['error' => 'Redis::connect(): SSL: Connection reset by peer']],
    ]);
});

it('gives up on a cache that keeps refusing connections after retrying for 1.5 seconds', function (): void {
    Sleep::fake();
    config(['database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
    app()->forgetInstance('redis');

    expect(fn () => app('redis')->connection())->toThrow(RedisException::class, 'Connection refused');

    Sleep::assertSequence([
        Sleep::for(100)->milliseconds(),
        Sleep::for(200)->milliseconds(),
        Sleep::for(400)->milliseconds(),
        Sleep::for(800)->milliseconds(),
    ]);
});

it('does not retry a connection that timed out', function (): void {
    $this->freezeTime();
    Sleep::fake();
    $cache = new FakeRedisClient(function (): never {
        $this->travel(5)->seconds();

        throw new RedisException('Connection timed out');
    });

    expect(fn () => connectToCache($cache))->toThrow(RedisException::class, 'Connection timed out');

    expect($cache->connectAttempts)->toBe(1);
    Sleep::assertNeverSlept();
});
