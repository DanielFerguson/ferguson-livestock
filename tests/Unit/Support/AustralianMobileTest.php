<?php

use App\Support\AustralianMobile;

it('converts the ways people write a mobile number to international format', function (string $typed) {
    expect(AustralianMobile::toE164($typed))->toBe('+61412345678');
})->with([
    'spaced' => '0412 345 678',
    'unspaced' => '0412345678',
    'international' => '+61 412 345 678',
    'international without plus' => '61412345678',
    'with dashes' => '0412-345-678',
    'with brackets' => '(0412) 345 678',
    'with dots' => '0412.345.678',
]);

it('rejects numbers that cannot receive text messages', function (string $typed) {
    expect(AustralianMobile::toE164($typed))->toBeNull();
})->with([
    'landline' => '03 5344 1234',
    'too short' => '0412 345',
    'too long' => '0412 345 6789',
    'overseas mobile' => '+44 7700 900123',
    'letters' => 'call me',
    'empty' => '',
]);
