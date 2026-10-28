<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\ProductType;
use App\Exceptions\CannotChangeDrop;
use App\Models\Product;

/**
 * Adds a product to the catalogue, or edits one. A product's slug and type are fixed once it exists.
 */
final class SaveProduct
{
    /**
     * Anything left null is left as it is (or unset, for a new product).
     *
     * @param  array<string, mixed>|null  $boxDetails  weight_kg, best_for, freezer_guidance and contents; merged into a box's existing details.
     *
     * @throws CannotChangeDrop
     */
    public function __invoke(
        ?Product $product,
        ?string $slug = null,
        ?ProductType $type = null,
        ?string $name = null,
        ?string $description = null,
        ?int $sort = null,
        ?array $boxDetails = null,
        ?int $stockProductId = null,
        ?int $stockUnits = null,
    ): Product {
        if ($product instanceof Product) {
            if ($slug !== null && $slug !== $product->slug) {
                throw new CannotChangeDrop("A product's slug cannot change once it is created: the site's code refers to it.");
            }

            if ($type instanceof ProductType && $type !== $product->type) {
                throw new CannotChangeDrop("A product's type cannot change once it is created.");
            }
        } else {
            if (! $type instanceof ProductType) {
                throw new CannotChangeDrop('A new product needs a type: box, extra or delivery.');
            }

            if ($slug === null || preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
                throw new CannotChangeDrop('A new product needs a slug made of letters, numbers, dashes and underscores.');
            }

            if (Product::query()->where('slug', $slug)->exists()) {
                throw new CannotChangeDrop("There is already a product with the slug \"{$slug}\".");
            }

            if ($name === null || $description === null) {
                throw new CannotChangeDrop('A new product needs a name and a description.');
            }
        }

        $effectiveType = $product instanceof Product ? $product->type : $type;

        if ($name !== null && (trim($name) === '' || mb_strlen($name) > 100)) {
            throw new CannotChangeDrop('A product name must be 1 to 100 characters.');
        }

        $details = $this->boxDetails($effectiveType, $product, $boxDetails, $stockProductId);

        if ($stockProductId !== null) {
            $source = Product::query()->find($stockProductId);

            if (! $source instanceof Product || $source->type !== ProductType::Box || $source->stock_product_id !== null || $source->id === $product?->id) {
                throw new CannotChangeDrop('Stock can only be borrowed from another box that has its own stock.');
            }
        }

        $attributes = array_filter([
            'name' => $name,
            'description' => $description,
            'sort' => $sort,
            'box_details' => $details,
            'stock_product_id' => $stockProductId,
            'stock_units' => $stockUnits,
        ], fn (mixed $value): bool => $value !== null);

        if ($product instanceof Product) {
            $product->update($attributes);

            return $product->refresh();
        }

        return Product::query()->create([...$attributes, 'slug' => $slug, 'type' => $type])->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @return array<string, mixed>|null
     *
     * @throws CannotChangeDrop
     */
    private function boxDetails(?ProductType $type, ?Product $product, ?array $changes, ?int $stockProductId): ?array
    {
        if ($type !== ProductType::Box) {
            if ($changes !== null || $stockProductId !== null) {
                throw new CannotChangeDrop('Only boxes have box details or borrow stock.');
            }

            return null;
        }

        $details = [...($product->box_details ?? []), ...($changes ?? [])];

        foreach (['weight_kg', 'best_for', 'freezer_guidance', 'contents'] as $required) {
            if (blank($details[$required] ?? null)) {
                throw new CannotChangeDrop("A box needs {$required} in its details.");
            }
        }

        return $details;
    }
}
