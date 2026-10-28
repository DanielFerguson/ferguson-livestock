<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('sms-rules')]
#[Uri('ferguson://sms-rules')]
#[MimeType('text/markdown')]
#[Description('The rules for texting customers: consent, opt-out, quiet hours, length and cost. Read before drafting or sending any message.')]
final class SmsRulesResource extends Resource
{
    public function handle(Request $request): Response
    {
        return Response::text(<<<'MD'
        # Texting customers

        ## Who can be texted
        - Only people on the wait list who agreed to receive texts and have not opted out. Broadcasts always exclude opted-out people.
        - A person opts out by replying STOP (or similar). They can only opt back in by texting START from their own phone. Never opt anyone back in on their behalf.
        - Replies can only go to a number that has texted the shop first, and never to someone who has opted out.

        ## Broadcasts
        - The text "Ferguson Livestock: " is added at the start and "Reply STOP to opt out" at the end. Don't write either yourself.
        - Audience is everyone subscribed, chosen postcodes, or a hand-picked list of subscribers (for testing).
        - Start with the test text to the owner's phone. Then preview, then send.
        - Do not send or schedule between 8pm and 8am Melbourne time unless the owner has said to.

        ## Replies
        - One-to-one, no prefix or suffix. Keep them short, warm, plain and specific. Never invent stock, prices or delivery promises: check the drop first.

        ## Length and cost
        - Up to 640 characters. A text holds 160 characters (70 if it contains characters outside the standard text alphabet, such as curly quotes or emoji), and longer ones split into segments that each cost.
        - Prefer straight quotes and plain punctuation.
        MD);
    }
}
