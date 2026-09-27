<?php

/*
|--------------------------------------------------------------------------
| Shop facts
|--------------------------------------------------------------------------
|
| Business facts and brand copy used across pages, metadata and structured
| data. Keep commercial claims in sync with docs/content/business-facts.md.
|
*/

return [

    'name' => 'Ferguson Livestock',

    // Canonical origin for canonical tags, sitemaps and structured data. It stays
    // the production host on every environment; non-production is noindexed.
    'url' => env('SHOP_URL', 'https://www.fergusonlivestock.com.au'),

    // Drop times are entered and shown in this timezone and stored in UTC.
    'timezone' => 'Australia/Melbourne',

    'owners' => ['Daniel Ferguson', 'Tahlia Ferguson'],

    'email' => 'ferguson.livestock.mg@outlook.com',

    'phone' => [
        'international' => '+61427458706',
        'display' => '0427 458 706',
    ],

    'location' => [
        'locality' => 'Snake Valley',
        'region' => 'VIC',
        'postal_code' => '3351',
        'country' => 'AU',
        'nearby_city' => 'Ballarat',
        'proximity' => 'about 30 minutes from Ballarat',
    ],

    'delivery' => [
        'area_name' => 'Ballarat region',
        'description' => 'Flat-fee personal delivery across the Ballarat region.',
    ],

    'pickup' => [
        'label' => 'Free farm pickup',
        'description' => 'Farm pickup by arrangement in Snake Valley.',
    ],

    'social' => [
        'facebook' => 'https://www.facebook.com/FergusonLivestockMG',
    ],

    'brand' => [
        'position' => 'Premium without pretence',
        'tagline' => 'Raised here. Delivered by us.',
        'eyebrow' => 'Murray Grey breeders · Snake Valley, Victoria',
        'hero_description' => 'We’re Daniel and Tahlia Ferguson, two young farmers building a future around Murray Grey cattle in Snake Valley. We raise our cattle with care and personally deliver our beef across the Ballarat region.',
        'short_description' => 'Farm-direct Murray Grey beef from Daniel and Tahlia Ferguson’s Snake Valley farm, available for pickup or delivery across the Ballarat region.',
        'promise' => 'Good cattle, honest beef and personal service from the farmers who raised it.',
    ],

    'navigation' => [
        'primary' => [
            ['label' => 'Beef boxes', 'route' => 'beef-boxes'],
            ['label' => 'Our story', 'route' => 'our-story'],
            ['label' => 'Delivery', 'route' => 'delivery'],
            ['label' => 'FAQ', 'route' => 'faq'],
            ['label' => 'Contact', 'route' => 'contact'],
        ],
        'information' => [
            ['label' => 'Privacy', 'route' => 'privacy'],
            ['label' => 'Refunds', 'route' => 'delivery', 'fragment' => 'refunds'],
        ],
    ],

    'og_image' => [
        'path' => '/og-image.jpg',
        'alt' => 'Ferguson Livestock Murray Grey cattle in Snake Valley, Victoria',
        'width' => 1200,
        'height' => 630,
    ],

];
