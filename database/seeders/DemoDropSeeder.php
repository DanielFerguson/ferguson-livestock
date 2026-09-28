<?php

namespace Database\Seeders;

use App\Models\Drop;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * An open drop with every product, priced from the suggestions in config/catalogue.php.
 * For local development and tests only: its Stripe price IDs are placeholders.
 */
class DemoDropSeeder extends Seeder
{
    public function run(): void
    {
        $drop = Drop::create([
            'name' => 'Demo drop',
            'opens_at' => now()->subHour(),
            'published_at' => now(),
            'delivery_days' => [now()->next('Saturday')->toDateString()],
        ]);

        foreach (Product::orderBy('sort')->get() as $product) {
            $drop->items()->create([
                'product_id' => $product->id,
                'price' => config()->integer("catalogue.products.{$product->slug}.price"),
                'stripe_price_id' => "price_demo_{$product->slug}",
                'quantity' => $product->hasOwnStock() ? ($product->type->value === 'box' ? 5 : 10) : null,
                'max_per_order' => $product->type->value === 'box' ? 1 : 10,
            ]);
        }
    }
}
