<?php

use App\Sms\SmsText;

it('fits 160 plain characters in one text, then 153 per text', function (int $length, int $segments) {
    expect((new SmsText(str_repeat('a', $length)))->segments())->toBe($segments);
})->with([
    [160, 1],
    [161, 2],
    [306, 2],
    [307, 3],
]);

it('counts the characters that take two places in the alphabet twice', function () {
    $text = new SmsText('€{}');

    expect($text->isGsm())->toBeTrue()
        ->and($text->length())->toBe(6);
});

it('fits only 70 characters in one text, then 67, once a character is outside the alphabet', function (int $length, int $segments) {
    expect((new SmsText(str_repeat('a', $length - 1).'ā'))->segments())->toBe($segments);
})->with([
    [70, 1],
    [71, 2],
    [134, 2],
    [135, 3],
]);

it('counts an emoji as two characters', function () {
    $text = new SmsText('Hi 👋');

    expect($text->isGsm())->toBeFalse()
        ->and($text->length())->toBe(5)
        ->and($text->charactersOutsideAlphabet())->toBe(['👋']);
});

it('swaps curly quotes, dashes and ellipses for plain ones', function () {
    expect(SmsText::tidy("  “Rump” – it’s back…\r\n "))->toBe('"Rump" - it\'s back...');
});

it('names the sender first and ends with how to opt out', function () {
    expect(SmsText::forBroadcast('The next drop opens Saturday.')->text)
        ->toBe("Ferguson Livestock: The next drop opens Saturday.\nReply STOP to opt out");
});
