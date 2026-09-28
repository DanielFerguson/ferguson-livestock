<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Creates any product in config/catalogue.php that doesn't exist yet. Existing products are left as the admin edited them.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, array{name: string, description: string, type: string, box?: array{weight_kg: int, contents: list<string>, best_for: string, freezer_guidance: string}, stock_product?: string, stock_units?: int}> $catalogue */
        $catalogue = config()->array('catalogue.products');

        $sort = 0;
        foreach ($catalogue as $slug => $product) {
            Product::firstOrCreate(['slug' => $slug], [
                'name' => $product['name'],
                'description' => $product['description'],
                'type' => $product['type'],
                'box_details' => $product['box'] ?? null,
                'stock_units' => $product['stock_units'] ?? 1,
                'sort' => $sort++,
            ]);
        }

        // Linked once every product exists, since a product can draw on one listed after it.
        foreach ($catalogue as $slug => $product) {
            if (isset($product['stock_product'])) {
                Product::where('slug', $slug)->whereNull('stock_product_id')->update([
                    'stock_product_id' => Product::where('slug', $product['stock_product'])->value('id'),
                ]);
            }
        }
    }
}
