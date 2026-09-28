<?php

namespace Database\Factories;

use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dropItem = DropItem::factory();

        return [
            'order_id' => Order::factory(),
            'drop_item_id' => $dropItem,
            'product_id' => fn (array $attributes): int => DropItem::query()->findOrFail(is_int($attributes['drop_item_id']) ? $attributes['drop_item_id'] : 0)->product_id,
            'quantity' => 1,
            'unit_price' => 1200,
            'stock_drop_item_id' => fn (array $attributes): mixed => $attributes['drop_item_id'],
            'stock_units' => 1,
        ];
    }
}
