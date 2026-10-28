<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Conversations;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\SmsMessage;
use App\Sms\ConversationList;
use App\Support\AustralianMobile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The full thread with one phone number, oldest first: what they sent and what was sent to them, with broadcasts marked. Does not mark anything read unless mark_read is true.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetConversationTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'phone' => ['required', 'string', 'max:30'],
            'mark_read' => ['nullable', 'boolean'],
        ]);

        $phone = AustralianMobile::toE164($arguments->requireString('phone')) ?? $arguments->requireString('phone');

        $summary = ConversationList::summary($phone);

        if ($summary === null) {
            return Response::error('Nobody with that number has texted in, so there is no conversation. Numbers can be written 0412 345 678 or +61412345678.');
        }

        $thread = ConversationList::thread($phone);

        if ($arguments->boolean('mark_read')) {
            ConversationList::markRead($phone);
        }

        return Response::structured([
            ...Presenter::conversation($summary),
            'messages' => $thread->map(fn (SmsMessage $message): array => Presenter::message($message))->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'phone' => $schema->string()->description('The contact number, 0412 345 678 or +61412345678.')->required(),
            'mark_read' => $schema->boolean()->description('Also mark their texts as read. Defaults to false.'),
        ];
    }
}
