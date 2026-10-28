<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\ScheduleBroadcast;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\BroadcastFingerprint;
use App\Mcp\Support\Changes;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\Presenter;
use App\Models\SmsBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Step 2: schedule a broadcast to text its audience at send_at. This commits to texting real people, so it needs the schedule confirmation_token from preview-broadcast (called with the same send_at), valid for 10 minutes and for the broadcast exactly as previewed.')]
#[IsDestructive]
final class ScheduleBroadcastTool extends Tool
{
    public function handle(Request $request, ScheduleBroadcast $schedule, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'broadcast_id' => ['required', 'integer'],
            'send_at' => ['required', 'string'],
            'confirmation_token' => ['required', 'string'],
            'acknowledge_quiet_hours' => ['nullable', 'boolean'],
        ]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        $sendAt = $arguments->dateTime('send_at');

        if (! $sendAt instanceof CarbonImmutable) {
            return Response::error('send_at is required.');
        }

        $confirmed = $tokens->consume(
            $arguments->requireString('confirmation_token'),
            'schedule-broadcast',
            "broadcast:{$broadcast->id}",
            BroadcastFingerprint::for($broadcast, $broadcast->recipients()->count(), $sendAt, $arguments->boolean('acknowledge_quiet_hours')),
        );

        if (! $confirmed) {
            return Response::error('That confirmation_token is not valid for this broadcast as it is now (used, expired, or the message, audience, audience size or send time changed). Call preview-broadcast again with send_at and show the owner the result.');
        }

        return Changes::run('schedule-broadcast', $request->all(), fn (): ResponseFactory => Response::structured([
            'scheduled' => true,
            'broadcast' => Presenter::broadcast($schedule($broadcast, $sendAt, $arguments->boolean('acknowledge_quiet_hours'))),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'broadcast_id' => $schema->integer()->description('The broadcast id.')->required(),
            'send_at' => $schema->string()->description('When to send, ISO-8601 (no offset = Melbourne). Must match the preview.')->required(),
            'confirmation_token' => $schema->string()->description('The schedule.confirmation_token from preview-broadcast.')->required(),
            'acknowledge_quiet_hours' => $schema->boolean()->description('Must match what you passed to preview-broadcast.'),
        ];
    }
}
