<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\MetaConversions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;

/**
 * Reports one event to Meta in the background, so a slow or unreachable Meta never holds up a sign-up or checkout.
 * Meta counts an event once per event ID, so a retry can't count it twice.
 */
class SendMetaEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    /**
     * @param  array<string, mixed>  $event  one event from MetaEvents, with the customer's details already hashed
     */
    public function __construct(public array $event) {}

    public function handle(MetaConversions $meta): void
    {
        try {
            $meta->send($this->event);
        } catch (RequestException $exception) {
            // Meta turning the event down (a wrong token, say) won't change on a retry. Its outages and limits might.
            if ($exception->response->clientError() && $exception->response->status() !== 429) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
