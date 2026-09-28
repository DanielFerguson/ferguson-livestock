<?php

use App\Stock\StockLabel;

it('describes what’s left the way the live script does', function (int $available, string $label) {
    expect(StockLabel::text($available))->toBe($label);
})->with([
    [0, 'Sold out'],
    [1, '1 left'],
    [10, '10 left'],
    [11, 'Available'],
]);
