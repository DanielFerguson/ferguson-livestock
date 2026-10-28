<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Conversations;

use App\Actions\SendReply;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\Presenter;
use App\Sms\SmsText;
use App\Support\AustralianMobile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Step 2: text the reply to the customer. Needs the confirmation_token from prepare-reply for this exact phone and text, and the thread unchanged since. Sends a real text and cannot be undone.')]
#[IsDestructive]
#[IsOpenWorld]
final class SendReplyTool extends Tool
{
    public function handle(Request $request, SendReply $reply, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'phone' => ['required', 'string', 'max:30'],
            'body' => ['required', 'string', 'max:2000'],
            'confirmation_token' => ['required', 'string'],
        ]);

        $phone = AustralianMobile::toE164($arguments->requireString('phone')) ?? $arguments->requireString('phone');
        $text = SmsText::tidy($arguments->requireString('body'));

        $confirmed = $tokens->consume(
            $arguments->requireString('confirmation_token'),
            'send-reply',
            "reply:{$phone}",
            PrepareReplyTool::fingerprint($phone, $text),
        );

        if (! $confirmed) {
            return Response::error('That confirmation_token is not valid for this reply (used, expired, the text changed, or the customer has texted again). Call prepare-reply again and show the owner the text.');
        }

        return Changes::run('send-reply', $request->all(), function () use ($reply, $phone, $text): ResponseFactory {
            $message = $reply($phone, $text);

            return Response::structured(['sent' => $message->status->value === 'sent', 'message' => Presenter::message($message)]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'phone' => $schema->string()->description('The contact number, 0412 345 678 or +61412345678.')->required(),
            'body' => $schema->string()->description('Exactly the text that was prepared.')->required(),
            'confirmation_token' => $schema->string()->description('From prepare-reply.')->required(),
        ];
    }
}
