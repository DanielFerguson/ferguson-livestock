<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\OrderConfirmationTexts;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\McpAudit;
use App\Models\Drop;
use App\Sms\QuietHours;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Step 2: text the drop\'s customers their order confirmations, exactly as prepare-order-texts showed. Needs its confirmation_token, and nothing changed since (a new order or a text already sent voids it). Sends real texts straight away and cannot be undone; nobody is ever texted twice about the same order.')]
#[IsDestructive]
#[IsOpenWorld]
final class SendOrderTextsTool extends Tool
{
    public function handle(Request $request, OrderConfirmationTexts $texts, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'confirmation_token' => ['required', 'string'],
            'acknowledge_quiet_hours' => ['nullable', 'boolean'],
        ]);

        $drop = Drop::query()->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        if (QuietHours::contains(now()) && ! $arguments->boolean('acknowledge_quiet_hours')) {
            return Response::error('It is quiet hours (8pm to 8am Melbourne). Send only if the owner has agreed to text customers now, with acknowledge_quiet_hours.');
        }

        $confirmed = $tokens->consume(
            $arguments->requireString('confirmation_token'),
            'send-order-texts',
            "order-texts:{$drop->id}",
            PrepareOrderTextsTool::fingerprint($texts->preview($drop)),
        );

        if (! $confirmed) {
            return Response::error('That confirmation_token is not valid for these texts (used, expired, or the orders changed since). Call prepare-order-texts again and show the owner the texts.');
        }

        $people = $texts->send($drop);

        McpAudit::record('send-order-texts', $request->all(), "queued {$people}");

        return Response::structured(['queued' => $people, 'next' => 'The texts are going out now, one by one. A customer who replies shows up in list-conversations.']);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'confirmation_token' => $schema->string()->description('From prepare-order-texts.')->required(),
            'acknowledge_quiet_hours' => $schema->boolean()->description('Only if the owner has explicitly agreed to text customers between 8pm and 8am Melbourne.'),
        ];
    }
}
