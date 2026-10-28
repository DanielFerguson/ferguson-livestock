<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\UnscheduleBroadcast;
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

#[Description('Cancel a scheduled send. The broadcast goes back to a draft and nothing is sent. Not possible once it has started sending.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class UnscheduleBroadcastTool extends Tool
{
    public function handle(Request $request, UnscheduleBroadcast $unschedule): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['broadcast_id' => ['required', 'integer']]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        return Changes::run('unschedule-broadcast', $request->all(), fn (): ResponseFactory => Response::structured([
            'unscheduled' => true,
            'broadcast' => Presenter::broadcast($unschedule($broadcast)),
        ]));
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
