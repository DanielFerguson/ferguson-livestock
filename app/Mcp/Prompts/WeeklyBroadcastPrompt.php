<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('plan-broadcast')]
#[Description('Plan, draft, test and (once approved) schedule or send a text broadcast to the wait list.')]
final class WeeklyBroadcastPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $topic = $request->get('topic');
        $about = is_string($topic) && trim($topic) !== '' ? trim($topic) : 'the next drop';

        return Response::text(<<<TEXT
        Help me text the wait list about: {$about}.

        1. get-overview (and get-drop if it's about a drop) so every date, price and quantity you mention is true. Read the sms-rules resource.
        2. Ask who it's for if it isn't obvious: everyone, certain postcodes (count-audience will size it), or a few chosen people.
        3. Draft the message with draft-broadcast (or draft-drop-announcement for a drop opening). Write only the message: the sender name and the STOP line are added automatically. Short, plain, no hype, straight quotes.
        4. Offer send-broadcast-test so I can see it on my phone.
        5. When I'm happy, preview-broadcast (with send_at if it's to be scheduled). Show me exactly: the message as sent, how many people, the cost, and any warnings such as quiet hours. Wait for my yes.
        6. Only then schedule-broadcast or send-broadcast-now with the confirmation_token. Never text between 8pm and 8am Melbourne time unless I've said so.
        TEXT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(name: 'topic', description: 'What the text is about, e.g. "the November drop opening".', required: false),
        ];
    }
}
