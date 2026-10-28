<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Stripe;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Order;
use App\Models\StripeEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('One Stripe event with its processing state and a summary of the object it is about (checkout session, charge). Includes the order it matched, if any.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetStripeEventTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['event_id' => ['required', 'string', 'max:100']]);

        $event = StripeEvent::query()->find($arguments->requireString('event_id'));

        if (! $event instanceof StripeEvent) {
            return Response::error("No Stripe event with id {$arguments->requireString('event_id')}. Use list-stripe-events to find one.");
        }

        $object = $event->object();

        $sessionId = is_string($object['id'] ?? null) && str_starts_with($object['id'], 'cs_') ? $object['id'] : null;
        $intentId = is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null;

        $order = Order::query()
            ->when($sessionId !== null || $intentId !== null, fn ($query) => $query->where(fn ($query) => $query
                ->when($sessionId !== null, fn ($query) => $query->where('stripe_checkout_session_id', $sessionId))
                ->when($intentId !== null, fn ($query) => $query->orWhere('stripe_payment_intent_id', $intentId))))
            ->first();

        return Response::structured([
            ...Presenter::stripeEvent($event),
            'object' => [
                'id' => $object['id'] ?? null,
                'object' => $object['object'] ?? null,
                'status' => $object['status'] ?? $object['payment_status'] ?? null,
                'amount' => $object['amount_total'] ?? $object['amount'] ?? null,
                'amount_refunded' => $object['amount_refunded'] ?? null,
                'currency' => $object['currency'] ?? null,
                'payment_intent' => $intentId,
            ],
            'matched_order' => $order instanceof Order ? $order->reference() : null,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->string()->description('The Stripe event id, evt_…')->required(),
        ];
    }
}
