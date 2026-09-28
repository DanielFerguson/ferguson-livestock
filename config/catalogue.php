<?php

/*
|--------------------------------------------------------------------------
| Catalogue
|--------------------------------------------------------------------------
|
| The products table is seeded from this file (database/seeders/ProductSeeder).
| Prices here are only suggestions for new drops and the local demo drop:
| the live prices are set on each drop in the admin. Keep them in line with
| docs/content/business-facts.md.
|
*/

return [

    'products' => [

        'beef-box-5kg' => [
            'name' => '5kg Beef Box',
            'description' => 'A balanced mix of steaks, slow-cook cuts, roast, sausages and mince.',
            'type' => 'box',
            'price' => 16000,
            'box' => [
                'weight_kg' => 5,
                'contents' => [
                    'Approximately 750g primary cuts — scotch, porterhouse, eye fillet or T-bone',
                    'Approximately 1.5kg secondary cuts — rump, osso buco, schnitzel, diced beef, ribs or oyster blade',
                    'Approximately 1.5kg roast',
                    'Approximately 500g sausages',
                    'Approximately 1kg mince',
                ],
                'best_for' => 'Couples and smaller households',
                'freezer_guidance' => 'Allow roughly one standard freezer drawer.',
            ],
        ],

        'beef-box-10kg' => [
            'name' => '10kg Beef Box',
            'description' => 'A larger balanced mix of steaks, slow-cook cuts, roasts, sausages and mince.',
            'type' => 'box',
            'price' => 27500,
            // Uses two units of the 5kg box's stock.
            'stock_product' => 'beef-box-5kg',
            'stock_units' => 2,
            'box' => [
                'weight_kg' => 10,
                'contents' => [
                    'Approximately 1.5kg primary cuts — scotch, porterhouse, eye fillet or T-bone',
                    'Approximately 3kg secondary cuts — rump, osso buco, schnitzel, diced beef, ribs or oyster blade',
                    'Approximately 3kg roast',
                    'Approximately 1kg sausages',
                    'Approximately 2kg mince',
                ],
                'best_for' => 'Families and regular beef eaters',
                'freezer_guidance' => 'Allow roughly two standard freezer drawers.',
            ],
        ],

        'beef-mince-500g' => [
            'name' => '500g Beef Mince',
            'description' => 'Versatile Murray Grey beef mince.',
            'type' => 'extra',
            'price' => 1200,
        ],

        'beef-bones-2kg' => [
            'name' => '2kg Beef Bones',
            'description' => 'Great for stock, broth, or pet bones.',
            'type' => 'extra',
            'price' => 1000,
        ],

        'sausage-packs-6' => [
            'name' => 'Sausage Pack (6)',
            'description' => 'Six premium beef sausages.',
            'type' => 'extra',
            'price' => 1200,
        ],

        'rump-steak-2' => [
            'name' => 'Rump Steak Pack (2)',
            'description' => 'Two Murray Grey rump steaks.',
            'type' => 'extra',
            'price' => 2000,
        ],

        'porterhouse' => [
            'name' => 'Porterhouse Steak',
            'description' => 'Premium porterhouse steak.',
            'type' => 'extra',
            'price' => 1800,
        ],

        'diced-chuck' => [
            'name' => '500g Diced Chuck',
            'description' => 'Diced chuck steak, great for slow cooking.',
            'type' => 'extra',
            'price' => 1300,
        ],

        'delivery-fee' => [
            'name' => 'Delivery',
            'description' => 'Flat fee delivery to your door in the Ballarat region.',
            'type' => 'delivery',
            'price' => 1500,
        ],

    ],

];
