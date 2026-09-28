<?php

use App\Support\ResponsiveImages;

beforeEach(function () {
    /** @var array<string, array{source: string, widths: non-empty-list<int>, aspect?: string}> $variants */
    $variants = json_decode((string) file_get_contents(resource_path('images/variants.json')), true);

    app()->instance(ResponsiveImages::class, new ResponsiveImages(
        variants: $variants,
        sourceDirectory: resource_path('images'),
        url: fn (string $path) => "/build/{$path}",
    ));
});

it('offers AVIF with a WebP fallback at every configured width', function () {
    $this->blade('<x-picture name="beef-box" alt="Beef packed for the freezer" sizes="50vw" />')
        ->assertSee('<source type="image/avif" srcset="/build/resources/images/generated/beef-box-480.avif 480w, /build/resources/images/generated/beef-box-720.avif 720w" sizes="50vw">', false)
        ->assertSee('src="/build/resources/images/generated/beef-box-720.webp"', false)
        ->assertSee('srcset="/build/resources/images/generated/beef-box-480.webp 480w, /build/resources/images/generated/beef-box-720.webp 720w"', false)
        ->assertSee('alt="Beef packed for the freezer"', false);
});

it('sets intrinsic dimensions so the layout does not shift', function () {
    $this->blade('<x-picture name="family-portrait" alt="Daniel and Tahlia" />')
        ->assertSee('width="578"', false)
        ->assertSee('height="723"', false);
});

it('lazy loads by default', function () {
    $this->blade('<x-picture name="beef-box" alt="" />')
        ->assertSee('loading="lazy"', false)
        ->assertSee('decoding="async"', false)
        ->assertDontSee('fetchpriority', false);
});

it('loads the priority image eagerly for the largest contentful paint', function () {
    $this->blade('<x-picture name="hero-cattle" alt="" priority />')
        ->assertSee('loading="eager"', false)
        ->assertSee('fetchpriority="high"', false)
        ->assertDontSee('loading="lazy"', false);
});

it('can load an above-the-fold image eagerly without raising its priority', function () {
    $this->blade('<x-picture name="logo" alt="" eager />')
        ->assertSee('loading="eager"', false)
        ->assertDontSee('fetchpriority', false);
});

it('passes extra attributes such as classes to the image', function () {
    $this->blade('<x-picture name="beef-box" alt="" class="aspect-square w-full object-cover" />')
        ->assertSee('class="aspect-square w-full object-cover"', false);
});
