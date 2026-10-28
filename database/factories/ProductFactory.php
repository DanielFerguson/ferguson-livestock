<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Bundle;
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
            'box_details' => null,
            'sort' => 0,
        ];
    }

    public function box(int $weightKg = 5): static
    {
        return $this->state(fn (): array => [
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
        return $this->state(fn (): array => ['type' => ProductType::Delivery]);
    }

    /**
     * A bundle: sold as several of another product, like the 10kg box using two 5kg-box units.
     */
    public function bundleOf(Product $component, int $units): static
    {
        return $this->afterCreating(function (Product $product) use ($component, $units): void {
            Bundle::factory()->create(['product_id' => $product->id, 'component_product_id' => $component->id, 'units' => $units]);
        });
    }
}
