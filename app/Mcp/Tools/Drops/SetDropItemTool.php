<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\SetDropItem;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Add a product to a drop, or change an existing item: its Stripe price, price, stock quantity (total for sale) or order limit. Raising stock adds units; lowering it never goes below what customers have held or bought. Set quantity 0 to stop selling an item.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class SetDropItemTool extends Tool
{
    public function handle(Request $request, SetDropItem $setDropItem): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'stripe_price_id' => ['nullable', 'string', 'max:100'],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'max_per_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $product = Product::query()->find($arguments->requireInteger('product_id'));

        if (! $product instanceof Product) {
            return Response::error("There is no product with id {$arguments->requireInteger('product_id')}. Use list-products.");
        }

        return Changes::run('set-drop-item', $request->all(), fn (): ResponseFactory => Response::structured([
            'saved' => true,
            'item' => Presenter::item($setDropItem(
                $drop,
                $product,
                $arguments->string('stripe_price_id'),
                $arguments->integer('price_cents'),
                $arguments->integer('quantity'),
                $arguments->integer('max_per_order'),
            )),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'product_id' => $schema->integer()->description('From list-products.')->required(),
            'stripe_price_id' => $schema->string()->description('From list-stripe-prices. Required when adding; the price amount must match Stripe.'),
            'price_cents' => $schema->integer()->description('Price in cents. Defaults to the Stripe price amount.'),
            'quantity' => $schema->integer()->description('Total units for sale (not units to add). Only for products with their own stock.'),
            'max_per_order' => $schema->integer()->description('Limit per order.'),
        ];
    }
}
