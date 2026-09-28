<?php

use App\Support\Catalogue;
use App\Support\Faqs;
use App\Support\StructuredData;
use Database\Seeders\DemoDropSeeder;
use Database\Seeders\ProductSeeder;

it('describes the farm as one LocalBusiness node on the homepage', function () {
    $home = StructuredData::home('Title', 'Description');

    expect(data_get($home, '@graph.*.@type'))->toBe(['LocalBusiness', 'WebSite', 'WebPage'])
        ->and(data_get($home, '@graph.0.@id'))->toBe('https://www.fergusonlivestock.com.au/#organization')
        ->and(data_get($home, '@graph.0.telephone'))->toBe('+61427458706')
        ->and(data_get($home, '@graph.0.address.postalCode'))->toBe('3351')
        ->and(data_get($home, '@graph.0.contactPoint.contactType'))->toBe('sales');
});

it('links the website and homepage back to the business', function () {
    $home = StructuredData::home('Title', 'Description');

    expect(data_get($home, '@graph.1.publisher.@id'))->toBe('https://www.fergusonlivestock.com.au/#organization')
        ->and(data_get($home, '@graph.2.about.@id'))->toBe('https://www.fergusonlivestock.com.au/#organization')
        ->and(data_get($home, '@graph.2.url'))->toBe('https://www.fergusonlivestock.com.au/');
});

it('describes a page with its canonical URL', function () {
    $page = StructuredData::webPage('/our-story', 'Meet us', 'About the farm', 'AboutPage');

    expect($page)->toMatchArray([
        '@context' => 'https://schema.org',
        '@type' => 'AboutPage',
        '@id' => 'https://www.fergusonlivestock.com.au/our-story#webpage',
        'url' => 'https://www.fergusonlivestock.com.au/our-story',
        'name' => 'Meet us',
        'inLanguage' => 'en-AU',
    ]);
});

it('offers both boxes at the featured drop’s prices, sold by the business', function () {
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);

    $schemas = StructuredData::beefBoxes('Title', 'Description', app(Catalogue::class));

    expect(data_get($schemas, '1.@graph.*.offers.price'))->toBe(['160.00', '275.00'])
        ->and(data_get($schemas, '1.@graph.*.offers.priceCurrency'))->toBe(['AUD', 'AUD'])
        ->and(data_get($schemas, '1.@graph.*.offers.seller.@id'))->toBe([
            'https://www.fergusonlivestock.com.au/#organization',
            'https://www.fergusonlivestock.com.au/#organization',
        ]);
});

it('marks up every frequently asked question', function () {
    $faqs = app(Faqs::class)->all();
    $schema = StructuredData::faq($faqs);

    expect($schema['@type'])->toBe('FAQPage')
        ->and($schema['mainEntity'])->toHaveCount(count($faqs))
        ->and(data_get($schema, 'mainEntity.0.acceptedAnswer.text'))->toBe($faqs[0]['answer']);
});

it('leaves product offers out until a drop sets prices', function () {
    $this->seed(ProductSeeder::class);

    expect(StructuredData::beefBoxes('Title', 'Description', app(Catalogue::class)))->toHaveCount(1);
});

it('prices the FAQ answers from the featured drop', function () {
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);

    $answers = collect(app(Faqs::class)->all())->pluck('answer')->implode(' ');

    expect($answers)->toContain('$160')
        ->toContain('$275')
        ->toContain('Delivery is $15 per order');
});

it('explains how prices work before any drop sets them', function () {
    $this->seed(ProductSeeder::class);

    $answers = collect(app(Faqs::class)->all())->pluck('answer')->implode(' ');

    expect($answers)->toContain('Prices are set for each drop and shown on the order page before you pay.')
        ->toContain('Delivery is a flat fee per order');
});
