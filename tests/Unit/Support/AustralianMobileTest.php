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

it('writes a mobile number the way Australians read it', function () {
    expect(AustralianMobile::format('+61412345678'))->toBe('0412 345 678');
});

it('turns a search for a mobile number into the digits stored', function (string $search, ?string $digits) {
    expect(AustralianMobile::searchDigits($search))->toBe($digits);
})->with([
    ['0412 345', '412345'],
    ['+61412345678', '412345678'],
    ['61412', '412'],
    ['Jane', null],
]);

it('writes any phone number readably, leaving ones that aren’t Australian mobiles as they are', function (string $number, string $readable) {
    expect(AustralianMobile::readable($number))->toBe($readable);
})->with([
    ['+61412345678', '0412 345 678'],
    ['+61353420000', '+61353420000'],
    ['+447700900123', '+447700900123'],
]);
