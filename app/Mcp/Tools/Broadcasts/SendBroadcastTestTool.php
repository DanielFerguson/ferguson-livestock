<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\SendBroadcastTest;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Models\SmsBroadcast;
use App\Support\AustralianMobile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Text the broadcast, exactly as subscribers would get it, to the owner\'s own mobile (SHOP_ADMIN_PHONE). Nobody else is texted. Costs one text.')]
#[IsDestructive(false)]
#[IsOpenWorld]
final class SendBroadcastTestTool extends Tool
{
    public function handle(Request $request, SendBroadcastTest $test): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['broadcast_id' => ['required', 'integer']]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        return Changes::run('send-broadcast-test', $request->all(), fn (): ResponseFactory => Response::structured([
            'sent_to' => AustralianMobile::format($test($broadcast)),
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
