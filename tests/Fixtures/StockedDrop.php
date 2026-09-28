<?php

namespace Tests\Fixtures;

use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Product;

/**
 * An open drop with the usual range: a 5kg box, a 10kg box packed from 5kg-box stock (two units each), mince
 * and the delivery fee.
 */
final readonly class StockedDrop
{
    public function __construct(
        public Drop $drop,
        public DropItem $box,
        public DropItem $largeBox,
        public DropItem $mince,
        public DropItem $delivery,
    ) {}

    /**
     * @param  array<string, mixed>  $drop
     */
    public static function create(int $boxes = 5, int $mince = 10, array $drop = []): self
    {
        $model = Drop::factory()->open()->create(['delivery_days' => [now()->addDays(3)->toDateString()], ...$drop]);
        $boxProduct = Product::factory()->box(5)->create(['name' => '5kg Beef Box']);

        $items = [
            'box' => DropItem::factory()->for($model)->for($boxProduct)->create(['price' => 16000, 'stripe_price_id' => 'price_box', 'quantity' => $boxes, 'max_per_order' => 1]),
            'largeBox' => DropItem::factory()->for($model)->for(Product::factory()->drawsStockFrom($boxProduct, 2)->create(['name' => '10kg Beef Box']))
                ->withoutStock()->create(['price' => 27500, 'stripe_price_id' => 'price_large_box', 'max_per_order' => 1]),
            'mince' => DropItem::factory()->for($model)->for(Product::factory()->create(['name' => '500g Beef Mince']))
                ->create(['price' => 1200, 'stripe_price_id' => 'price_mince', 'quantity' => $mince]),
            'delivery' => DropItem::factory()->for($model)->for(Product::factory()->delivery()->create(['name' => 'Delivery']))
                ->withoutStock()->create(['price' => 1500, 'stripe_price_id' => 'price_delivery']),
        ];

        return new self($model->refresh(), ...$items);
    }
}
