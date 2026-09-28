<?php

use App\Support\ResponsiveImages;

function responsiveImages(): ResponsiveImages
{
    return new ResponsiveImages(
        variants: [
            'hero' => ['source' => 'wide.webp', 'widths' => [640, 960]],
            'portrait' => ['source' => 'wide.webp', 'aspect' => '4:5', 'widths' => [360, 480]],
        ],
        sourceDirectory: __DIR__.'/../../Fixtures/images',
        url: fn (string $path) => "/build/{$path}",
    );
}

it('builds a srcset for each format from the configured widths', function () {
    $image = responsiveImages()->get('hero');

    expect($image->srcset('avif'))->toBe(
        '/build/resources/images/generated/hero-640.avif 640w, /build/resources/images/generated/hero-960.avif 960w'
    )->and($image->srcset('webp'))->toBe(
        '/build/resources/images/generated/hero-640.webp 640w, /build/resources/images/generated/hero-960.webp 960w'
    );
});

it('falls back to the largest WebP', function () {
    expect(responsiveImages()->get('hero')->fallback())->toBe('/build/resources/images/generated/hero-960.webp');
});

it('keeps the source aspect ratio when none is configured', function () {
    // The fixture is 1200x800.
    $image = responsiveImages()->get('hero');

    expect($image->width)->toBe(960)->and($image->height)->toBe(640);
});

it('uses the configured aspect ratio for cropped variants', function () {
    $image = responsiveImages()->get('portrait');

    expect($image->width)->toBe(480)->and($image->height)->toBe(600);
});

it('rejects an unknown image', function () {
    responsiveImages()->get('missing');
})->throws(InvalidArgumentException::class, 'missing');
