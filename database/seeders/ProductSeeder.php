<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bundle;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Creates any product in config/catalogue.php that doesn't exist yet. Existing products are left as the admin edited them.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, array{name: string, description: string, type: string, box?: array{weight_kg: int, contents: list<string>, best_for: string, freezer_guidance: string}, bundle?: array{of: string, units: int}}> $catalogue */
        $catalogue = config()->array('catalogue.products');

        $sort = 0;
        foreach ($catalogue as $slug => $product) {
            Product::query()->firstOrCreate(['slug' => $slug], [
                'name' => $product['name'],
                'description' => $product['description'],
                'type' => $product['type'],
                'box_details' => $product['box'] ?? null,
                'sort' => $sort++,
            ]);
        }

        // Linked once every product exists, since a bundle can be of a product listed after it.
        foreach ($catalogue as $slug => $product) {
            if (isset($product['bundle'])) {
                Bundle::query()->firstOrCreate(
                    ['product_id' => Product::query()->where('slug', $slug)->value('id')],
                    ['component_product_id' => Product::query()->where('slug', $product['bundle']['of'])->value('id'), 'units' => $product['bundle']['units']],
                );
            }
        }
    }
}
