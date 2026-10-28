<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Subscribers;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\McpAudit;
use App\Mcp\Support\Presenter;
use App\Models\Subscriber;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Stop texting a subscriber: they are left out of every broadcast and cannot be replied to. Use only when they ask to stop. This cannot be reversed here; only they can opt back in, by texting START from their own phone.')]
#[IsDestructive]
final class OptOutSubscriberTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['subscriber_id' => ['required', 'integer']]);

        $subscriber = Subscriber::query()->find($arguments->requireInteger('subscriber_id'));

        if (! $subscriber instanceof Subscriber) {
            return Response::error("There is no subscriber with id {$arguments->requireInteger('subscriber_id')}. Use list-subscribers to find them.");
        }

        $subscriber->optOut();

        McpAudit::record('opt-out-subscriber', $request->all(), 'done');

        return Response::structured(['opted_out' => true, 'subscriber' => Presenter::subscriber($subscriber->refresh())]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'subscriber_id' => $schema->integer()->description('From list-subscribers.')->required(),
        ];
    }
}
