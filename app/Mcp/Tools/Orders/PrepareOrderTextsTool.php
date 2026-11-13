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

#[Description('Step 1 of texting a drop\'s customers their order confirmation now: what they ordered, and the pickup times and farm address, or their delivery night. Shows, without sending: new texts for orders not yet texted (one per person, covering all their orders), texts already saved and waiting for 8am (paid in quiet hours), whose orders are left out and why (already collected, already texted, no mobile, opted out), and the cost. Paid orders are texted automatically, so this is for orders that haven\'t been, or to send the waiting ones early. Returns a confirmation_token for send-order-texts. Show the owner the texts and wait for their yes.')]
#[IsReadOnly]
#[IsIdempotent]
final class PrepareOrderTextsTool extends Tool
{
    /**
     * What the owner agrees to: these exact new texts to these people, and these waiting ones.
     *
     * @param  array{texts: list<array{phone: string, text: string, ...}>, waiting: list<array{message_id: int, ...}>, ...}  $preview
     * @return array{new: list<array{string, string}>, waiting: list<int>}
     */
    public static function fingerprint(array $preview): array
    {
        return [
            'new' => array_map(fn (array $text): array => [$text['phone'], $text['text']], $preview['texts']),
            'waiting' => array_map(fn (array $text): int => $text['message_id'], $preview['waiting']),
        ];
    }

    public function handle(Request $request, OrderConfirmationTexts $texts, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $preview = $texts->preview($drop);
        $segments = array_sum(array_column($preview['texts'], 'segments')) + array_sum(array_column($preview['waiting'], 'segments'));
        $quietHours = QuietHours::contains(now());
        $anything = $preview['texts'] !== [] || $preview['waiting'] !== [];

        return Response::structured([
            'people' => count($preview['texts']) + count($preview['waiting']),
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
            'waiting_for_8am' => array_map(fn (array $text): array => [
                'to' => AustralianMobile::readable($text['phone']),
                'name' => $text['name'],
                'orders' => $text['orders'],
                'text' => $text['text'],
                'texts' => $text['segments'],
            ], $preview['waiting']),
            'skipped' => $preview['skipped'],
            'confirmation_token' => $anything ? $tokens->issue('send-order-texts', "order-texts:{$drop->id}", self::fingerprint($preview)) : null,
            'next' => ! $anything
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
