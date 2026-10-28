<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\SaveDrop;
use App\Actions\Drops\SetDropItem;
use App\Exceptions\CannotChangeDrop;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Create a new draft drop, optionally with its items (products with a Stripe price and stock) and delivery days in one go. It stays a draft, invisible to customers, until publish-drop. Run run-drop-preflight before publishing.')]
#[IsDestructive(false)]
final class CreateDropTool extends Tool
{
    public function handle(Request $request, SaveDrop $saveDrop, SetDropItem $setDropItem): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'name' => ['required', 'string', 'max:100'],
            'opens_at' => ['required', 'string'],
            'closes_at' => ['nullable', 'string'],
            'delivery_days' => ['nullable', 'array', 'max:14'],
            'delivery_days.*' => ['string'],
            'announce_date' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.stripe_price_id' => ['nullable', 'string', 'max:100'],
            'items.*.price_cents' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.max_per_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $opensAt = $arguments->dateTime('opens_at');
        $closesAt = $arguments->dateTime('closes_at');
        $items = $request->get('items');

        return Changes::run('create-drop', $request->all(), function () use ($arguments, $saveDrop, $setDropItem, $opensAt, $closesAt, $items): ResponseFactory {
            $drop = DB::transaction(function () use ($arguments, $saveDrop, $setDropItem, $opensAt, $closesAt, $items): Drop {
                $drop = $saveDrop(null, array_filter([
                    'name' => $arguments->requireString('name'),
                    'opens_at' => $opensAt,
                    'closes_at' => $closesAt,
                    'delivery_days' => $arguments->strings('delivery_days') ?? [],
                    'announced' => $arguments->boolean('announce_date') ? true : null,
                ], fn (mixed $value): bool => $value !== null));

                foreach ($this->lines($items) as $line) {
                    $product = Product::query()->find($line['product_id']);

                    if (! $product instanceof Product) {
                        throw new CannotChangeDrop('One of the items names a product that does not exist. Use list-products.');
                    }

                    $setDropItem($drop, $product, $line['stripe_price_id'], $line['price_cents'], $line['quantity'], $line['max_per_order']);
                }

                return $drop;
            });

            return Response::structured(['created' => true, 'drop' => Presenter::drop($drop->load('items.product'), withItems: true)]);
        });
    }

    /**
     * @return list<array{product_id: int, stripe_price_id: string|null, price_cents: int|null, quantity: int|null, max_per_order: int|null}>
     */
    private function lines(mixed $items): array
    {
        $lines = [];

        foreach (is_array($items) ? $items : [] as $line) {
            if (! is_array($line) || ! is_int($line['product_id'] ?? null)) {
                continue;
            }

            $lines[] = [
                'product_id' => $line['product_id'],
                'stripe_price_id' => is_string($line['stripe_price_id'] ?? null) ? $line['stripe_price_id'] : null,
                'price_cents' => is_int($line['price_cents'] ?? null) ? $line['price_cents'] : null,
                'quantity' => is_int($line['quantity'] ?? null) ? $line['quantity'] : null,
                'max_per_order' => is_int($line['max_per_order'] ?? null) ? $line['max_per_order'] : null,
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Shown in the admin, e.g. "November drop".')->required(),
            'opens_at' => $schema->string()->description('When orders open, ISO-8601. No offset means Melbourne time, e.g. 2026-11-14T09:00:00.')->required(),
            'closes_at' => $schema->string()->description('Optional closing time. Leave out to stay open until sold out, closed by hand, or the next drop opens.'),
            'delivery_days' => $schema->array()->items($schema->string())->description('Dates customers can choose for delivery, YYYY-MM-DD.'),
            'announce_date' => $schema->boolean()->description('Show "Next drop: {date}" on the website while it is still a draft.'),
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('From list-products.')->required(),
                'stripe_price_id' => $schema->string()->description('From list-stripe-prices.')->required(),
                'price_cents' => $schema->integer()->description('Defaults to the Stripe price amount. Must match Stripe.'),
                'quantity' => $schema->integer()->description('Units for sale. Only for products with their own stock; leave out for delivery and boxes that borrow stock.'),
                'max_per_order' => $schema->integer()->description('Limit per order. Defaults to 10.'),
            ]))->description('Products on this drop.'),
        ];
    }
}
