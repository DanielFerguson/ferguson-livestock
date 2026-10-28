<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Stripe;

use App\Jobs\ProcessStripeEvent;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\McpAudit;
use App\Models\StripeEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Process a Stripe webhook event again. Only for an event that has not been processed (see list-stripe-events with state failing). It runs in the background; check get-stripe-event afterwards.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class RetryStripeEventTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['event_id' => ['required', 'string', 'max:100']]);

        $event = StripeEvent::query()->find($arguments->requireString('event_id'));

        if (! $event instanceof StripeEvent) {
            return Response::error("No Stripe event with id {$arguments->requireString('event_id')}.");
        }

        if ($event->processed_at !== null) {
            return Response::error('That event was already processed, so there is nothing to retry.');
        }

        ProcessStripeEvent::dispatch($event->id);

        McpAudit::record('retry-stripe-event', $request->all(), 'queued');

        return Response::structured(['queued' => true, 'event_id' => $event->id, 'type' => $event->type]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->description('The Stripe event id, evt_…')->required(),
        ];
    }
}
