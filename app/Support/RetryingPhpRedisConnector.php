<?php

declare(strict_types=1);

namespace App\Support;

use ErrorException;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Facades\Log;
use Redis;
use RedisException;
use Throwable;

/**
 * Connects to Redis the way Laravel does, but tries again when the cache turns a new connection away.
 *
 * Laravel Cloud's Valkey cache sleeps while the site is quiet, and as it sleeps or wakes it can reset a new connection
 * part-way through the TLS handshake ("SSL: Connection reset by peer"). Every request asks the cache whether the site
 * is down for maintenance before anything else, so that reset became the visitor's error page. phpredis's own retries
 * only reconnect a connection that was already open, never a first connect.
 */
final class RetryingPhpRedisConnector extends PhpRedisConnector
{
    /**
     * Milliseconds to wait before each retry: about 1.5 seconds in all, longer than the cache takes to wake.
     */
    private const array RETRY_DELAYS = [100, 200, 400, 800];

    /**
     * Retrying stops after this long. A connect that timed out (after 5 seconds) has already used it up, so it isn't
     * repeated and the request stays well inside Cloud's 20-second gateway limit.
     */
    private const int RETRY_FOR_SECONDS = 2;

    /**
     * @param  Redis  $client
     * @param  array<mixed>  $config
     */
    protected function establishConnection($client, array $config): void
    {
        $giveUpAt = now()->addSeconds(self::RETRY_FOR_SECONDS);

        retry(
            self::RETRY_DELAYS,
            fn () => parent::establishConnection($client, $config),
            when: function (Throwable $exception) use ($giveUpAt): bool {
                $wasTurnedAway = $exception instanceof RedisException || $exception instanceof ErrorException;

                if (! $wasTurnedAway || now()->isAfter($giveUpAt)) {
                    return false;
                }

                Log::warning('Retrying a Redis connection the cache turned away.', ['error' => $exception->getMessage()]);

                return true;
            },
        );
    }
}
