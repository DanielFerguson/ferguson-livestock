<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\SaveBroadcast;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Save a new draft text broadcast. Write only the message: the "Ferguson Livestock:" prefix and the "Reply STOP to opt out" line are added automatically. It is only a draft and sends nothing; use preview-broadcast then send-broadcast-now or schedule-broadcast.')]
#[IsDestructive(false)]
final class DraftBroadcastTool extends Tool
{
    public function handle(Request $request, SaveBroadcast $save): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'body' => ['required', 'string', 'max:2000'],
            'postcodes' => ['nullable', 'array', 'max:50'],
            'postcodes.*' => ['string', 'size:4'],
            'subscriber_ids' => ['nullable', 'array', 'max:200'],
            'subscriber_ids.*' => ['integer'],
            'drop_id' => ['nullable', 'integer'],
        ]);

        $changes = array_filter([
            'body' => $arguments->requireString('body'),
            'postcodes' => $arguments->strings('postcodes'),
            'subscriber_ids' => $request->get('subscriber_ids') === null ? null : array_values(array_map(intval(...), array_filter((array) $request->get('subscriber_ids'), is_numeric(...)))),
            'drop_id' => $arguments->integer('drop_id'),
        ], fn (mixed $value): bool => $value !== null);

        return Changes::run('draft-broadcast', $request->all(), fn (): ResponseFactory => Response::structured([
            'drafted' => true,
            'broadcast' => Presenter::broadcast($save(null, $changes, $request->user() instanceof User ? $request->user()->id : null)),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->description('The message, up to 640 characters once tidied. Plain punctuation (straight quotes) keeps texts to 160 characters each.')->required(),
            'postcodes' => $schema->array()->items($schema->string())->description('Send only to subscribers in these four-digit postcodes. Leave out (with no subscriber_ids) for everyone subscribed.'),
            'subscriber_ids' => $schema->array()->items($schema->integer())->description('Send only to these subscribers (for testing). Overrides postcodes.'),
            'drop_id' => $schema->integer()->description('Link the broadcast to a drop.'),
        ];
    }
}
