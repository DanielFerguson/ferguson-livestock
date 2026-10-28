<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\SendBroadcastNow;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\BroadcastFingerprint;
use App\Mcp\Support\Changes;
use App\Mcp\Support\ConfirmationTokens;
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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Step 2: text the broadcast\'s whole audience right now. This cannot be undone. Needs the send_now confirmation_token from preview-broadcast, valid for 10 minutes and for the broadcast exactly as previewed. Refused during quiet hours (8pm to 8am Melbourne) unless the owner agreed.')]
#[IsDestructive]
#[IsOpenWorld]
final class SendBroadcastNowTool extends Tool
{
    public function handle(Request $request, SendBroadcastNow $sendNow, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'broadcast_id' => ['required', 'integer'],
            'confirmation_token' => ['required', 'string'],
            'acknowledge_quiet_hours' => ['nullable', 'boolean'],
        ]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        $confirmed = $tokens->consume(
            $arguments->requireString('confirmation_token'),
            'send-broadcast-now',
            "broadcast:{$broadcast->id}",
            BroadcastFingerprint::for($broadcast, $broadcast->recipients()->count(), null, $arguments->boolean('acknowledge_quiet_hours')),
        );

        if (! $confirmed) {
            return Response::error('That confirmation_token is not valid for this broadcast as it is now (used, expired, or the message, audience or audience size changed). Call preview-broadcast again and show the owner the result.');
        }

        return Changes::run('send-broadcast-now', $request->all(), function () use ($sendNow, $broadcast, $arguments): ResponseFactory {
            $queued = $sendNow($broadcast, $arguments->boolean('acknowledge_quiet_hours'));

            return Response::structured(['sending' => true, 'queued' => $queued, 'broadcast' => Presenter::broadcast($broadcast->refresh())]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'broadcast_id' => $schema->integer()->description('The broadcast id.')->required(),
            'confirmation_token' => $schema->string()->description('The send_now.confirmation_token from preview-broadcast.')->required(),
            'acknowledge_quiet_hours' => $schema->boolean()->description('Must match what you passed to preview-broadcast.'),
        ];
    }
}
