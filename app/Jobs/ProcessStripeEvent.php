<?php

namespace App\Jobs;

use App\Checkout\HandleStripeEvent;
use App\Models\StripeEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes a stored Stripe event until it succeeds. Only one copy per event is queued at a time, and the order
 * changes it makes are safe to repeat, so the queue delivering it twice does no harm.
 */
class ProcessStripeEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public string $eventId) {}

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(HandleStripeEvent $handle): void
    {
        $event = StripeEvent::find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $event->increment('attempts');

        try {
            $handle($event);
        } catch (Throwable $exception) {
            $event->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        }

        $event->update(['processed_at' => now(), 'last_error' => null]);
    }
}
