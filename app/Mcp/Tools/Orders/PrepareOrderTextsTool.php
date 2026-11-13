<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\OrderConfirmationTexts;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Sms\QuietHours;
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

#[Description('Step 1 of texting a drop\'s customers their order confirmation: what they ordered, and the pickup times and farm address, or their delivery night. Shows every text exactly as it will be sent, whose orders are left out and why (already collected, already texted, no mobile, opted out), and the cost, without sending. One text per person, covering all their orders. Orders paid from now on are texted automatically when paid, so this is for orders that haven\'t been. Returns a confirmation_token for send-order-texts. Show the owner the texts and wait for their yes.')]
#[IsReadOnly]
#[IsIdempotent]
final class PrepareOrderTextsTool extends Tool
{
    /**
     * What the owner agrees to: these exact texts to these people.
     *
     * @param  array{texts: list<array{phone: string, text: string, ...}>, ...}  $preview
     * @return list<array{string, string}>
     */
    public static function fingerprint(array $preview): array
    {
        return array_map(fn (array $text): array => [$text['phone'], $text['text']], $preview['texts']);
    }

    public function handle(Request $request, OrderConfirmationTexts $texts, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $preview = $texts->preview($drop);
        $segments = array_sum(array_column($preview['texts'], 'segments'));
        $quietHours = QuietHours::contains(now());

        return Response::structured([
            'people' => count($preview['texts']),
            'texts_in_total' => $segments,
            'estimated_cost' => Presenter::money($segments * config()->integer('services.twilio.segment_cost')),
            'warnings' => $quietHours ? ['It is quiet hours (8pm to 8am Melbourne). Only send now if the owner has agreed to text customers at this hour.'] : [],
            'texts' => array_map(fn (array $text): array => [
                'to' => AustralianMobile::readable($text['phone']),
                'name' => $text['name'],
                'orders' => $text['orders'],
                'text' => $text['text'],
                'texts' => $text['segments'],
            ], $preview['texts']),
            'skipped' => $preview['skipped'],
            'confirmation_token' => $preview['texts'] === [] ? null : $tokens->issue('send-order-texts', "order-texts:{$drop->id}", self::fingerprint($preview)),
            'next' => $preview['texts'] === []
                ? 'Nobody is waiting for an order text.'
                : 'Show the owner every text and the skipped orders, and call send-order-texts with the confirmation_token once they say yes'.($quietHours ? ', with acknowledge_quiet_hours only if they agreed to text now.' : '.'),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
        ];
    }
}
