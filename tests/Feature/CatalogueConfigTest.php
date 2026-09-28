<?php

// config/catalogue.php seeds the products and suggests prices for new drops; keep it in line with
// the confirmed facts in docs/content/business-facts.md.
it('suggests the confirmed prices from the business facts', function () {
    expect(config('catalogue.products.beef-box-5kg.price'))->toBe(16000)
        ->and(config('catalogue.products.beef-box-10kg.price'))->toBe(27500)
        ->and(config('catalogue.products.delivery-fee.price'))->toBe(1500);
});
