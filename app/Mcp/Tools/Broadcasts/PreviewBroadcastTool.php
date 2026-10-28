<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\ScheduleBroadcast;
use App\Actions\Broadcasts\SendBroadcastNow;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\BroadcastFingerprint;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\Presenter;
use App\Models\SmsBroadcast;
use App\Sms\BroadcastPreview;
use App\Sms\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Step 1 of sending. Shows exactly what subscribers would receive, who it reaches, how many texts and the cost, and any problems (nobody to send to, quiet hours, odd characters). Returns a confirmation_token for send-broadcast-now, and for schedule-broadcast when you pass send_at. Show the owner the preview and wait for their yes before using the token. Editing the broadcast afterwards voids the token.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class PreviewBroadcastTool extends Tool
{
    public function handle(Request $request, SendBroadcastNow $sendNow, ScheduleBroadcast $schedule, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'broadcast_id' => ['required', 'integer'],
            'send_at' => ['nullable', 'string'],
            'acknowledge_quiet_hours' => ['nullable', 'boolean'],
        ]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        $preview = BroadcastPreview::of($broadcast);
        $sendAt = $arguments->dateTime('send_at');
        $ack = $arguments->boolean('acknowledge_quiet_hours');

        $fingerprint = BroadcastFingerprint::for($broadcast, $preview->recipients, $sendAt, $ack);

        $sendNowBlocker = $sendNow->blocker($broadcast, $ack);
        $scheduleBlocker = $sendAt instanceof CarbonImmutable ? $schedule->blocker($broadcast, $sendAt, $ack) : null;

        if ($sendAt instanceof CarbonImmutable && $preview->recipients === 0) {
            $scheduleBlocker ??= 'No one subscribed matches this audience, so there is nobody to send to.';
        }

        return Response::structured([
            'broadcast' => Presenter::broadcast($broadcast),
            'message_as_sent' => $preview->text->text,
            'length' => $preview->length(),
            'recipients' => $preview->recipients,
            'estimated_cost' => Presenter::money($preview->estimatedCost()),
            'summary' => $preview->confirmation(),
            'warnings' => array_values(array_filter([$preview->alphabetWarning(), QuietHours::contains(now()) ? 'It is currently quiet hours (8pm to 8am Melbourne).' : null], filled(...))),
            'send_now' => [
                'allowed' => $sendNowBlocker === null,
                'blocker' => $sendNowBlocker,
                'confirmation_token' => $sendNowBlocker === null ? $tokens->issue('send-broadcast-now', "broadcast:{$broadcast->id}", $fingerprint) : null,
            ],
            'schedule' => $sendAt instanceof CarbonImmutable ? [
                'send_at' => Presenter::time($sendAt),
                'in_quiet_hours' => QuietHours::contains($sendAt),
                'allowed' => $scheduleBlocker === null,
                'blocker' => $scheduleBlocker,
                'confirmation_token' => $scheduleBlocker === null ? $tokens->issue('schedule-broadcast', "broadcast:{$broadcast->id}", $fingerprint) : null,
            ] : null,
            'next' => 'Show the owner the message, who it reaches and the cost, and only call send-broadcast-now or schedule-broadcast with the confirmation_token after they say yes.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'broadcast_id' => $schema->integer()->description('The broadcast id.')->required(),
            'send_at' => $schema->string()->description('To preview scheduling instead of sending now: when to send, ISO-8601 (no offset = Melbourne).'),
            'acknowledge_quiet_hours' => $schema->boolean()->description('Only if the owner has explicitly agreed to texting between 8pm and 8am Melbourne.'),
        ];
    }
}
