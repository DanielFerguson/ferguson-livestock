<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Enums\SmsDirection;
use App\Models\SmsMessage;
use App\Sms\ConversationList;
use App\Support\AustralianMobile;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('draft-reply')]
#[Description('Read a customer\'s text conversation and draft a reply in the owner\'s voice, ready to approve and send.')]
final class DraftReplyPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $given = $request->get('phone');
        $phone = is_string($given) ? (AustralianMobile::toE164($given) ?? $given) : '';

        $thread = $phone === '' ? new Collection : ConversationList::thread($phone);

        $transcript = $thread->isEmpty()
            ? '(no messages found for that number)'
            : $thread->map(fn (SmsMessage $message): string => ($message->direction === SmsDirection::Inbound ? 'Customer' : 'Us').': '.$message->body)->implode("\n");

        return Response::text(<<<TEXT
        Draft a reply to this customer for me to approve. Don't send anything yet.

        Conversation with {$phone}:
        {$transcript}

        Rules:
        - Answer what they actually asked. If it depends on stock, prices, delivery days or an order, check get-overview, get-drop or get-order first rather than guessing.
        - Plain, warm and brief, like a farmer texting a neighbour. No hype, no emoji, no sales pitch. Straight quotes and plain punctuation only.
        - Under 640 characters, ideally one or two texts. Don't add a sign-off line or opt-out wording.
        - Show me the draft. When I approve it, call prepare-reply, show me the final text and cost, and only call send-reply after I confirm.
        TEXT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(name: 'phone', description: 'The customer\'s number, from list-conversations.', required: true),
        ];
    }
}
