<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The catalogue: every product (boxes, extras and delivery) with its type, slug and, for a bundle, what it is a bundle of (the 10kg box is 2 × the 5kg box).')]
#[IsReadOnly]
#[IsIdempotent]
final class ListProductsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'products' => Product::query()->with('bundle.component')->orderBy('sort')->get()->map(fn (Product $product): array => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'type' => $product->type->value,
                'description' => $product->description,
                'bundle' => $product->bundle === null ? null : [
                    'of_product_id' => $product->bundle->component_product_id,
                    'of' => $product->bundle->component->name,
                    'units' => $product->bundle->units,
                ],
                'box_details' => $product->box_details,
            ])->all(),
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
