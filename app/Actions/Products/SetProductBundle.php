<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Exceptions\CannotChangeDrop;
use App\Models\Bundle;
use App\Models\DropItem;
use App\Models\Product;

/**
 * Makes a product a bundle of another (the 10kg box is two 5kg boxes), changes what it is a bundle of, or stops
 * it being one. The one place those rules are applied, for the admin and the MCP tools.
 */
final class SetProductBundle
{
    /**
     * @param  Product|null  $component  Null stops the product being a bundle.
     * @param  int|null  $units  How many of the component each one uses. Left null, an existing bundle keeps its units.
     *
     * @throws CannotChangeDrop
     */
    public function __invoke(Product $product, ?Product $component, ?int $units = null): void
    {
        $bundle = Bundle::query()->where('product_id', $product->id)->first();

        if (! $component instanceof Product) {
            if ($bundle instanceof Bundle && DropItem::query()->where('product_id', $product->id)->exists()) {
                throw new CannotChangeDrop("{$product->name} is on a drop as a bundle, so it cannot stop being one. Take it off its drops first.");
            }

            $bundle?->delete();

            return;
        }

        $units ??= $bundle?->units;

        if ($units === null) {
            throw new CannotChangeDrop('A bundle needs both what it is a bundle of and how many of them (bundle_units).');
        }

        $problems = Bundle::problemsFor($product, $component, $units);

        if ($problems !== []) {
            throw new CannotChangeDrop(implode(' ', $problems));
        }

        Bundle::query()->updateOrCreate(['product_id' => $product->id], ['component_product_id' => $component->id, 'units' => $units]);
    }
}
