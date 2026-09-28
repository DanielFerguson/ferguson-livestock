<?php

namespace App\Support;

use App\Enums\ProductType;
use App\Models\Drop;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Products for the marketing pages, priced from the featured drop: the one open now, else the next
 * scheduled one, else the most recent. Prices are null until a drop sets them, and the pages hide them.
 */
final readonly class Catalogue
{
    /**
     * @param  Collection<int, CatalogueEntry>  $entries
     */
    public function __construct(private Collection $entries) {}

    public static function fromDatabase(): self
    {
        /** @var Collection<int, int> $prices */
        $prices = Drop::featured()?->items()->pluck('price', 'product_id') ?? collect();

        return new self(Product::orderBy('sort')->orderBy('id')->get()->map(function (Product $product) use ($prices): CatalogueEntry {
            $price = $prices->get($product->id);
            $details = $product->box_details;

            return new CatalogueEntry(
                slug: $product->slug,
                name: $product->name,
                description: $product->description,
                type: $product->type,
                price: $price,
                box: $details === null ? null : new BoxDetails(
                    weightKg: $details['weight_kg'],
                    contents: $details['contents'],
                    bestFor: $details['best_for'],
                    freezerGuidance: $details['freezer_guidance'],
                    perKgPrice: $price === null ? null : intdiv($price, $details['weight_kg']),
                ),
            );
        }));
    }

    public function find(string $slug): ?CatalogueEntry
    {
        return $this->entries->firstWhere('slug', $slug);
    }

    /**
     * @return Collection<int, CatalogueEntry>
     */
    public function boxes(): Collection
    {
        return $this->ofType(ProductType::Box);
    }

    /**
     * @return Collection<int, CatalogueEntry>
     */
    public function extras(): Collection
    {
        return $this->ofType(ProductType::Extra);
    }

    public function deliveryFee(): ?int
    {
        return $this->ofType(ProductType::Delivery)->first()?->price;
    }

    public function startingBoxPrice(): ?int
    {
        $prices = array_filter($this->boxes()->map(fn (CatalogueEntry $box): ?int => $box->price)->all(), fn (?int $price): bool => $price !== null);

        return $prices === [] ? null : min($prices);
    }

    /**
     * @return Collection<int, CatalogueEntry>
     */
    private function ofType(ProductType $type): Collection
    {
        return $this->entries->filter(fn (CatalogueEntry $entry): bool => $entry->type === $type)->values();
    }
}
