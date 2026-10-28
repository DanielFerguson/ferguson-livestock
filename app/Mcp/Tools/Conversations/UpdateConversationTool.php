<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Conversations;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\ConversationState;
use App\Sms\ConversationList;
use App\Support\AustralianMobile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Manage one conversation in the inbox: flag it to follow up on, clear that flag, tick it off as done (a new text from them reopens it), or mark their texts read.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class UpdateConversationTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'phone' => ['required', 'string', 'max:30'],
            'action' => ['required', Rule::in(['follow_up', 'clear_follow_up', 'done', 'read'])],
        ]);

        $phone = AustralianMobile::toE164($arguments->requireString('phone')) ?? $arguments->requireString('phone');

        if (ConversationList::summary($phone) === null) {
            return Response::error('Nobody with that number has texted in, so there is no conversation.');
        }

        return Changes::run('update-conversation', $request->all(), function () use ($arguments, $phone): ResponseFactory {
            match ($arguments->requireString('action')) {
                'follow_up' => ConversationState::followUp($phone),
                'clear_follow_up' => ConversationState::clearFollowUp($phone),
                'done' => ConversationState::markDone($phone),
                default => ConversationList::markRead($phone),
            };

            $summary = ConversationList::summary($phone);

            return Response::structured(['updated' => true, 'conversation' => $summary === null ? null : Presenter::conversation($summary)]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'phone' => $schema->string()->description('The contact number, 0412 345 678 or +61412345678.')->required(),
            'action' => $schema->string()->enum(['follow_up', 'clear_follow_up', 'done', 'read'])->description('follow_up flags it, clear_follow_up removes the flag, done ticks it off, read marks their texts read.')->required(),
        ];
    }
}
