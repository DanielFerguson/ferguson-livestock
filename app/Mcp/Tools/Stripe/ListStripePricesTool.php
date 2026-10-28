<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Stripe;

use App\Mcp\Support\Presenter;
use App\Payments\PaymentGateway;
use App\Payments\Price;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('The active one-time AUD prices in Stripe, for choosing a stripe_price_id when adding an item to a drop. Cached for five minutes.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListStripePricesTool extends Tool
{
    public function handle(Request $request, PaymentGateway $payments): Response|ResponseFactory
    {
        try {
            $prices = $payments->activePrices();
        } catch (Throwable $exception) {
            return Response::error('Could not reach Stripe: '.$exception->getMessage());
        }

        return Response::structured([
            'prices' => array_map(fn (Price $price): array => [
                'id' => $price->id,
                'product_name' => $price->productName,
                'amount' => $price->unitAmount === null ? null : Presenter::money($price->unitAmount),
            ], $prices),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
