<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * An individual cut by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'type' => ProductType::Extra,
            'stock_product_id' => null,
            'stock_units' => 1,
            'box_details' => null,
            'sort' => 0,
        ];
    }

    public function box(int $weightKg = 5): static
    {
        return $this->state(fn () => [
            'type' => ProductType::Box,
            'box_details' => [
                'weight_kg' => $weightKg,
                'contents' => ['Primary cuts', 'Secondary cuts', 'Roast', 'Sausages', 'Mince'],
                'best_for' => 'Households of all sizes',
                'freezer_guidance' => 'Allow roughly one standard freezer drawer.',
            ],
        ]);
    }

    public function delivery(): static
    {
        return $this->state(fn () => ['type' => ProductType::Delivery]);
    }

    /**
     * A box that uses another product's stock, like the 10kg box using two 5kg-box units.
     */
    public function drawsStockFrom(Product $product, int $units): static
    {
        return $this->box()->state(fn () => ['stock_product_id' => $product->id, 'stock_units' => $units]);
    }
}
