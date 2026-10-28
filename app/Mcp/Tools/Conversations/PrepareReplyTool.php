<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Conversations;

use App\Actions\SendReply;
use App\Exceptions\CannotReply;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\Presenter;
use App\Models\SmsMessage;
use App\Sms\ConversationList;
use App\Sms\SmsText;
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

#[Description('Step 1 of replying to a customer. Checks the reply can be sent (they have texted in, have not opted out, the text is 1 to 640 characters) and shows exactly what will be sent and what it costs, without sending. Returns a confirmation_token for send-reply. Replies carry no prefix or opt-out line. Show the owner the text and wait for their yes. A new text from the customer, or any change to your text, voids the token.')]
#[IsReadOnly]
#[IsIdempotent]
final class PrepareReplyTool extends Tool
{
    /**
     * What the owner agrees to: this exact text to this number, and the thread as it stood when they read it.
     *
     * @return array<string, mixed>
     */
    public static function fingerprint(string $phone, string $text): array
    {
        return [
            'phone' => $phone,
            'text' => $text,
            'last_message' => SmsMessage::query()->where('contact_phone', $phone)->max('id'),
        ];
    }

    public function handle(Request $request, SendReply $reply, ConfirmationTokens $tokens): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'phone' => ['required', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $phone = AustralianMobile::toE164($arguments->requireString('phone')) ?? $arguments->requireString('phone');
        $text = SmsText::tidy($arguments->requireString('body'));
        $blocker = $reply->blocker($phone, $text);
        $summary = ConversationList::summary($phone);
        $segments = (new SmsText($text))->segments();

        return Response::structured([
            'can_send' => ! $blocker instanceof CannotReply,
            'blocker' => $blocker?->getMessage(),
            'to' => AustralianMobile::readable($phone),
            'name' => $summary['name'] ?? null,
            'will_send' => $text,
            'characters' => mb_strlen($text),
            'texts' => $segments,
            'estimated_cost' => Presenter::money($segments * config()->integer('services.twilio.segment_cost')),
            'confirmation_token' => $blocker instanceof CannotReply ? null : $tokens->issue('send-reply', "reply:{$phone}", self::fingerprint($phone, $text)),
            'next' => $blocker instanceof CannotReply ? 'Fix the problem; this reply cannot be sent.' : 'Show the owner the text and send-reply with the confirmation_token once they say yes.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'phone' => $schema->string()->description('The contact number, 0412 345 678 or +61412345678.')->required(),
            'body' => $schema->string()->description('The reply, as the customer will read it.')->required(),
        ];
    }
}
