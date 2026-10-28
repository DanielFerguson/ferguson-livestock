<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Stripe;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\StripeEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Stripe webhook events the site received, newest first. Filter by type, or by state: all, unprocessed, failing (unprocessed with an error), processed.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListStripeEventsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'type' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['all', 'unprocessed', 'failing', 'processed'])],
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = StripeEvent::query()
            ->when($arguments->string('type') !== null, fn ($query) => $query->where('type', $arguments->string('type')))
            ->when($arguments->string('since') !== null, fn ($query) => $query->where('received_at', '>=', $arguments->string('since')))
            ->when($arguments->string('state') === 'unprocessed', fn ($query) => $query->whereNull('processed_at'))
            ->when($arguments->string('state') === 'failing', fn ($query) => $query->whereNull('processed_at')->whereNotNull('last_error'))
            ->when($arguments->string('state') === 'processed', fn ($query) => $query->whereNotNull('processed_at'))
            ->latest('received_at')
            ->limit($arguments->integer('limit') ?? 25)
            ->get();

        return Response::structured(['events' => $events->map(fn (StripeEvent $event): array => Presenter::stripeEvent($event))->all()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Event type, e.g. checkout.session.completed.'),
            'state' => $schema->string()->enum(['all', 'unprocessed', 'failing', 'processed'])->description('Defaults to all.'),
            'since' => $schema->string()->description('Only events received at or after this time (ISO-8601).'),
            'limit' => $schema->integer()->description('How many to return, 1 to 100. Defaults to 25.'),
        ];
    }
}
