<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\SaveBroadcast;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\SmsBroadcast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Change a draft or scheduled broadcast\'s message, audience or linked drop. Only fields you pass change. Editing voids any earlier preview, so preview again before sending. Does not change when it is scheduled.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class UpdateBroadcastTool extends Tool
{
    public function handle(Request $request, SaveBroadcast $save): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'broadcast_id' => ['required', 'integer'],
            'body' => ['nullable', 'string', 'max:2000'],
            'postcodes' => ['nullable', 'array', 'max:50'],
            'postcodes.*' => ['string', 'size:4'],
            'subscriber_ids' => ['nullable', 'array', 'max:200'],
            'subscriber_ids.*' => ['integer'],
            'drop_id' => ['nullable', 'integer'],
        ]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        $changes = array_filter([
            'body' => $arguments->string('body'),
            'postcodes' => $arguments->strings('postcodes'),
            'subscriber_ids' => $request->get('subscriber_ids') === null ? null : array_values(array_map(intval(...), array_filter((array) $request->get('subscriber_ids'), is_numeric(...)))),
            'drop_id' => $arguments->integer('drop_id'),
        ], fn (mixed $value): bool => $value !== null);

        if ($changes === []) {
            return Response::error('Nothing to change. Pass body, postcodes, subscriber_ids or drop_id.');
        }

        return Changes::run('update-broadcast', $request->all(), fn (): ResponseFactory => Response::structured([
            'updated' => true,
            'broadcast' => Presenter::broadcast($save($broadcast, $changes)),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'broadcast_id' => $schema->integer()->description('The broadcast id.')->required(),
            'body' => $schema->string()->description('New message.'),
            'postcodes' => $schema->array()->items($schema->string())->description('Send only to subscribers in these four-digit postcodes. Leave out (with no subscriber_ids) for everyone subscribed.'),
            'subscriber_ids' => $schema->array()->items($schema->integer())->description('Send only to these subscribers (for testing). Overrides postcodes.'),
            'drop_id' => $schema->integer()->description('Link the broadcast to a drop.'),
        ];
    }
}
