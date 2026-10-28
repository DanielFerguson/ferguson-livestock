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

#[Description('Edit a catalogue product\'s name, description, sort order, box details, or what it is a bundle of. Only the fields you pass change. Slug and type cannot be changed.')]
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
            'bundle_of_product_id' => ['nullable', 'integer'],
            'bundle_units' => ['nullable', 'integer', 'min:1'],
            'remove_bundle' => ['nullable', 'boolean'],
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
        $bundleOf = $arguments->integer('bundle_of_product_id') === null ? null : Product::query()->find($arguments->integer('bundle_of_product_id'));
        $bundleUnits = $arguments->integer('bundle_units');
        $removeBundle = $arguments->boolean('remove_bundle');

        if ($arguments->integer('bundle_of_product_id') !== null && ! $bundleOf instanceof Product) {
            return Response::error('There is no product with that bundle_of_product_id. Use list-products.');
        }

        if ([$name, $description, $sort, $bundleOf, $bundleUnits] === [null, null, null, null, null] && $details === [] && ! $removeBundle) {
            return Response::error('Nothing to change.');
        }

        return Changes::run('update-product', $request->all(), fn (): ResponseFactory => Response::structured([
            'updated' => true,
            'product_id' => $save($product, name: $name, description: $description, sort: $sort, boxDetails: $details === [] ? null : $details, bundleOf: $bundleOf, bundleUnits: $bundleUnits, removeBundle: $removeBundle)->id,
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
            'bundle_of_product_id' => $schema->integer()->description('Make this a bundle of that product (or change which one).'),
            'bundle_units' => $schema->integer()->description('How many of the component each one uses.'),
            'remove_bundle' => $schema->boolean()->description('Stop this being a bundle so it has stock of its own. Not allowed while it is on a drop.'),
        ];
    }
}
