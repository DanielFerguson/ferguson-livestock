<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\TakeBackFreeItem;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\OrderReference;
use App\Mcp\Support\Presenter;
use App\Models\OrderItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Take a free item back off an order, when one was given by mistake: its stock goes back on sale. Only free items can come off; what the customer paid for stays.')]
#[IsDestructive]
final class RemoveFreeItemTool extends Tool
{
    public function handle(Request $request, TakeBackFreeItem $takeBackFreeItem): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'order' => ['required', 'string', 'max:60'],
            'product_id' => ['required', 'integer'],
        ]);

        $orders = OrderReference::matches($arguments->requireString('order'));

        if ($orders->count() !== 1) {
            return Response::error($orders->isEmpty() ? 'No order matches that reference.' : 'More than one order matches; use the full public id.');
        }

        $order = $orders->sole();
        $productId = $arguments->requireInteger('product_id');
        $line = $order->items->filter(fn (OrderItem $line): bool => $line->free && $line->product_id === $productId)->last();

        if (! $line instanceof OrderItem) {
            return Response::error("Order {$order->reference()} has no free item with product id {$productId}. Use get-order to see its lines.");
        }

        return Changes::run('remove-free-item', $request->all(), function () use ($takeBackFreeItem, $line, $order): ResponseFactory {
            $takeBackFreeItem($line);

            return Response::structured(['removed' => true, 'order' => Presenter::order($order->refresh()->load(['items.product', 'drop']))]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order reference like FL-7K2QXM.')->required(),
            'product_id' => $schema->integer()->description('The free item’s product id, from get-order’s lines.')->required(),
        ];
    }
}
