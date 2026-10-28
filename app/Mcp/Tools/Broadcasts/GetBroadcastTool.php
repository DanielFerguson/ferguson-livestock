<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Sms\TwilioErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('One broadcast: the message as it will be sent, audience, status, delivery counts and the failures with plain-English reasons.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetBroadcastTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['broadcast_id' => ['required', 'integer']]);

        $broadcast = SmsBroadcast::query()->find($arguments->requireInteger('broadcast_id'));

        if (! $broadcast instanceof SmsBroadcast) {
            return Response::error("There is no broadcast with id {$arguments->requireInteger('broadcast_id')}. Use list-broadcasts to find one.");
        }

        $failures = $broadcast->messages()->with('subscriber')
            ->whereIn('status', ['failed', 'undelivered'])
            ->limit(50)->get()
            ->map(fn (SmsMessage $message): array => [
                'phone' => $message->contact_phone,
                'name' => $message->subscriber?->first_name,
                'status' => $message->status->value,
                'reason' => TwilioErrors::explain($message->error_code),
            ])->all();

        return Response::structured([
            ...Presenter::broadcast($broadcast),
            'message_as_sent' => $broadcast->text()->text,
            'failures' => $failures,
        ]);
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
