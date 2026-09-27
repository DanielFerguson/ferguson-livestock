<?php

use App\Support\Catalogue;

// These are the confirmed facts in docs/content/business-facts.md.
it('matches the confirmed prices in the business facts', function () {
    $catalogue = app(Catalogue::class);

    expect($catalogue->find('beef-box-5kg')->price)->toBe(16000)
        ->and($catalogue->find('beef-box-5kg')->box?->perKgPrice)->toBe(3200)
        ->and($catalogue->find('beef-box-10kg')->price)->toBe(27500)
        ->and($catalogue->find('beef-box-10kg')->box?->perKgPrice)->toBe(2750)
        ->and($catalogue->deliveryFee())->toBe(1500);
});

it('is shared as a single instance', function () {
    expect(app(Catalogue::class))->toBe(app(Catalogue::class));
});
