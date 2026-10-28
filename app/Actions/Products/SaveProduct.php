<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Enums\ProductType;
use App\Exceptions\CannotChangeDrop;
use App\Models\Bundle;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Adds a product to the catalogue, or edits one. A product's slug and type are fixed once it exists.
 *
 * A product can be a bundle of another: the 10kg beef box is two 5kg boxes, a 5kg mince pack is ten 500g packs.
 */
final readonly class SaveProduct
{
    public function __construct(private SetProductBundle $setBundle) {}

    /**
     * Anything left null is left as it is (or unset, for a new product).
     *
     * @param  array<string, mixed>|null  $boxDetails  weight_kg, best_for, freezer_guidance and contents; merged into a box's existing details.
     * @param  Product|null  $bundleOf  Make this product a bundle of that one (or change which it is a bundle of).
     * @param  int|null  $bundleUnits  How many of the component each one uses.
     * @param  bool  $removeBundle  Stop this product being a bundle, so it has stock of its own.
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
        ?Product $bundleOf = null,
        ?int $bundleUnits = null,
        bool $removeBundle = false,
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

        if ($removeBundle && ($bundleOf instanceof Product || $bundleUnits !== null)) {
            throw new CannotChangeDrop('Either make this a bundle or remove its bundle, not both.');
        }

        if ($name !== null && (trim($name) === '' || mb_strlen($name) > 100)) {
            throw new CannotChangeDrop('A product name must be 1 to 100 characters.');
        }

        $effectiveType = $product instanceof Product ? $product->type : $type;
        $details = $this->boxDetails($effectiveType, $product, $boxDetails);

        $attributes = array_filter([
            'name' => $name,
            'description' => $description,
            'sort' => $sort,
            'box_details' => $details,
        ], fn (mixed $value): bool => $value !== null);

        return DB::transaction(function () use ($product, $slug, $type, $attributes, $bundleOf, $bundleUnits, $removeBundle): Product {
            if ($product instanceof Product) {
                $product->update($attributes);
                $saved = $product;
            } else {
                $saved = Product::query()->create([...$attributes, 'slug' => $slug, 'type' => $type]);
            }

            if ($removeBundle) {
                ($this->setBundle)($saved, null);
            } elseif ($bundleOf instanceof Product || $bundleUnits !== null) {
                $component = $bundleOf ?? Bundle::query()->where('product_id', $saved->id)->first()?->component;

                if (! $component instanceof Product) {
                    throw new CannotChangeDrop('Say what it is a bundle of (bundle_of_product_id) as well as how many (bundle_units).');
                }

                ($this->setBundle)($saved, $component, $bundleUnits);
            }

            return $saved->refresh();
        });
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @return array<string, mixed>|null
     *
     * @throws CannotChangeDrop
     */
    private function boxDetails(?ProductType $type, ?Product $product, ?array $changes): ?array
    {
        if ($type !== ProductType::Box) {
            if ($changes !== null) {
                throw new CannotChangeDrop('Only boxes have box details.');
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
