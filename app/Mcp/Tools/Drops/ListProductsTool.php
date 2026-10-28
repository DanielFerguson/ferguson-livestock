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

#[Description('The catalogue: every product (boxes, extras and delivery) with its type, slug, and which product it borrows stock from.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListProductsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'products' => Product::query()->orderBy('sort')->get()->map(fn (Product $product): array => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'type' => $product->type->value,
                'description' => $product->description,
                'stock_product_id' => $product->stock_product_id,
                'stock_units' => $product->stock_units,
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
