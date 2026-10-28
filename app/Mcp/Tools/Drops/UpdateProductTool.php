<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Products\SaveProduct;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
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

#[Description('Edit a catalogue product\'s name, description, sort order, box details or stock source. Only the fields you pass change. Slug and type cannot be changed.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class UpdateProductTool extends Tool
{
    public function handle(Request $request, SaveProduct $save): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'product_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer'],
            'box_weight_kg' => ['nullable', 'integer', 'min:1'],
            'box_best_for' => ['nullable', 'string', 'max:200'],
            'box_freezer_guidance' => ['nullable', 'string', 'max:300'],
            'box_contents' => ['nullable', 'array', 'max:30'],
            'box_contents.*' => ['string', 'max:100'],
            'stock_product_id' => ['nullable', 'integer'],
            'stock_units' => ['nullable', 'integer', 'min:1'],
        ]);

        $product = Product::query()->find($arguments->requireInteger('product_id'));

        if (! $product instanceof Product) {
            return Response::error("There is no product with id {$arguments->requireInteger('product_id')}. Use list-products.");
        }

        $details = array_filter([
            'weight_kg' => $arguments->integer('box_weight_kg'),
            'best_for' => $arguments->string('box_best_for'),
            'freezer_guidance' => $arguments->string('box_freezer_guidance'),
            'contents' => $arguments->strings('box_contents'),
        ], fn (mixed $value): bool => $value !== null);
        $name = $arguments->string('name');
        $description = $arguments->string('description');
        $sort = $arguments->integer('sort');
        $stockProductId = $arguments->integer('stock_product_id');
        $stockUnits = $arguments->integer('stock_units');

        if ([$name, $description, $sort, $stockProductId, $stockUnits] === [null, null, null, null, null] && $details === []) {
            return Response::error('Nothing to change.');
        }

        return Changes::run('update-product', $request->all(), fn (): ResponseFactory => Response::structured([
            'updated' => true,
            'product_id' => $save($product, name: $name, description: $description, sort: $sort, boxDetails: $details === [] ? null : $details, stockProductId: $stockProductId, stockUnits: $stockUnits)->id,
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('From list-products.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('Shown on the site.'),
            'sort' => $schema->integer()->description('Order on the site.'),
            'box_weight_kg' => $schema->integer()->description('Boxes only.'),
            'box_best_for' => $schema->string()->description('Boxes only: who it suits.'),
            'box_freezer_guidance' => $schema->string()->description('Boxes only.'),
            'box_contents' => $schema->array()->items($schema->string())->description('Boxes only: what is in it.'),
            'stock_product_id' => $schema->integer()->description('Boxes only: borrow stock from this other box (e.g. the 10kg box from the 5kg box).'),
            'stock_units' => $schema->integer()->description('Units of that stock each one uses.'),
        ];
    }
}
