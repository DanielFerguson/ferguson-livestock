<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Products\SaveProduct;
use App\Enums\ProductType;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Add a new product to the catalogue (a box, an extra or the delivery fee). Its slug and type can never change afterwards. Boxes need weight, best_for, freezer guidance and contents. A product only sells once it is added to a drop with set-drop-item.')]
#[IsDestructive(false)]
final class CreateProductTool extends Tool
{
    public function handle(Request $request, SaveProduct $save): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'slug' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(ProductType::class)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer'],
            'box_weight_kg' => ['nullable', 'integer', 'min:1'],
            'box_best_for' => ['nullable', 'string', 'max:200'],
            'box_freezer_guidance' => ['nullable', 'string', 'max:300'],
            'box_contents' => ['nullable', 'array', 'max:30'],
            'box_contents.*' => ['string', 'max:100'],
            'stock_product_id' => ['nullable', 'integer'],
            'stock_units' => ['nullable', 'integer', 'min:1'],
        ]);

        $details = array_filter([
            'weight_kg' => $arguments->integer('box_weight_kg'),
            'best_for' => $arguments->string('box_best_for'),
            'freezer_guidance' => $arguments->string('box_freezer_guidance'),
            'contents' => $arguments->strings('box_contents'),
        ], fn (mixed $value): bool => $value !== null);

        return Changes::run('create-product', $request->all(), fn (): ResponseFactory => Response::structured([
            'created' => true,
            'product_id' => $save(
                null,
                slug: $arguments->requireString('slug'),
                type: ProductType::from($arguments->requireString('type')),
                name: $arguments->requireString('name'),
                description: $arguments->requireString('description'),
                sort: $arguments->integer('sort'),
                boxDetails: $details === [] ? null : $details,
                stockProductId: $arguments->integer('stock_product_id'),
                stockUnits: $arguments->integer('stock_units'),
            )->id,
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Letters, numbers, dashes. Fixed forever.')->required(),
            'type' => $schema->string()->enum(array_column(ProductType::cases(), 'value'))->description('box, extra or delivery. Fixed forever.')->required(),
            'name' => $schema->string()->description('Shown on the site and in the admin.')->required(),
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
