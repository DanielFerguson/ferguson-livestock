<?php

namespace App\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Product names, contents and display prices for the marketing pages.
 *
 * Backed by config/catalogue.php until drops and their prices move into the database.
 *
 * @phpstan-type BoxConfig array{weight_kg: int, contents: list<string>, best_for: string, freezer_guidance: string}
 * @phpstan-type ProductConfig array{name: string, description: string, type: 'box'|'extra'|'delivery', price: int, box?: BoxConfig}
 */
final class Catalogue
{
    /** @var Collection<string, Product> */
    private Collection $products;

    /**
     * @param  array<string, ProductConfig>  $products
     */
    public function __construct(array $products)
    {
        $built = [];

        foreach ($products as $slug => $product) {
            $built[$slug] = new Product(
                slug: $slug,
                name: $product['name'],
                description: $product['description'],
                type: $product['type'],
                price: $product['price'],
                box: isset($product['box']) ? new BoxDetails(
                    weightKg: $product['box']['weight_kg'],
                    contents: $product['box']['contents'],
                    bestFor: $product['box']['best_for'],
                    freezerGuidance: $product['box']['freezer_guidance'],
                    perKgPrice: intdiv($product['price'], $product['box']['weight_kg']),
                ) : null,
            );
        }

        $this->products = collect($built);
    }

    public function find(string $slug): Product
    {
        return $this->products->get($slug) ?? throw new InvalidArgumentException("Unknown product [{$slug}].");
    }

    /**
     * @return Collection<int, Product>
     */
    public function boxes(): Collection
    {
        return $this->ofType('box');
    }

    /**
     * @return Collection<int, Product>
     */
    public function extras(): Collection
    {
        return $this->ofType('extra');
    }

    public function deliveryFee(): int
    {
        return $this->ofType('delivery')->firstOrFail()->price;
    }

    public function startingBoxPrice(): int
    {
        return (int) $this->boxes()->min(fn (Product $box) => $box->price);
    }

    /**
     * @return Collection<int, Product>
     */
    private function ofType(string $type): Collection
    {
        return $this->products->filter(fn (Product $product) => $product->type === $type)->values();
    }
}
