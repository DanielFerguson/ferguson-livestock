<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Closure;
use LogicException;
use Redis;

/**
 * A phpredis client that never reaches a server: each connect runs the next scripted attempt, which can throw the way
 * phpredis does when the cache turns a connection away.
 */
final class FakeRedisClient extends Redis
{
    public int $connectAttempts = 0;

    /** @var list<Closure(): bool> */
    private readonly array $attempts;

    /**
     * @param  Closure(): bool  ...$attempts
     */
    public function __construct(Closure ...$attempts)
    {
        parent::__construct();

        $this->attempts = array_values($attempts);
    }

    /**
     * @param  array<mixed>|null  $context
     */
    public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
    {
        $attempt = $this->attempts[$this->connectAttempts++] ?? throw new LogicException('The test did not expect another connection attempt.');

        return $attempt();
    }
}
