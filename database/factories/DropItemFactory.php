<?php

namespace Database\Factories;

use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DropItem>
 */
class DropItemFactory extends Factory
{
    /**
     * An individual cut with ten units by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'drop_id' => Drop::factory(),
            'product_id' => Product::factory(),
            'price' => fake()->randomElement([1000, 1200, 1800, 2000]),
            'stripe_price_id' => 'price_'.fake()->unique()->bothify('test????????????????'),
            'quantity' => 10,
            'available' => null,
            'max_per_order' => 10,
        ];
    }

    /**
     * An item that doesn't track its own stock, such as a box that borrows another box's stock.
     */
    public function withoutStock(): static
    {
        return $this->state(fn () => ['quantity' => null, 'available' => null]);
    }

    /**
     * The drop's delivery fee.
     */
    public function delivery(): static
    {
        return $this->withoutStock()->state(fn () => ['product_id' => Product::factory()->delivery()]);
    }
}
