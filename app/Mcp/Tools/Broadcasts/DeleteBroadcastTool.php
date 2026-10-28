<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Models\SmsBroadcast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a draft broadcast that was never scheduled or sent. Cannot be undone. Unschedule a scheduled one first.')]
#[IsDestructive]
final class DeleteBroadcastTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['broadcast_id' => ['required', 'integer']]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        if ($broadcast->status() !== BroadcastStatus::Draft) {
            return Response::error("This broadcast is {$broadcast->status()->getLabel()}. Only a draft can be deleted; unschedule it first if it is scheduled.");
        }

        return Changes::run('delete-broadcast', $request->all(), function () use ($broadcast): ResponseFactory {
            $broadcast->delete();

            return Response::structured(['deleted' => true]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'broadcast_id' => $schema->integer()->description('The broadcast id.')->required(),
        ];
    }
}
