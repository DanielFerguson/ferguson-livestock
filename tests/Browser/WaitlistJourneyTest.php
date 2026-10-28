<?php

declare(strict_types=1);

use App\Models\Subscriber;

it('signs someone up from the home page and thanks them by name', function (): void {
    visit('/')
        ->type('first_name', 'Sam')
        ->type('phone', '0412 345 678')
        ->type('postcode', '3350')
        ->check('sms_consent')
        ->click('form[action$="/waitlist"] button')
        ->assertSee('Thanks for joining, Sam!')
        ->assertPathIs('/thank-you')
        ->assertNoJavaScriptErrors();

    $subscriber = Subscriber::query()->sole();

    expect($subscriber->first_name)->toBe('Sam')
        ->and($subscriber->phone)->toBe('+61412345678')
        ->and($subscriber->isSubscribed())->toBeTrue();
});

it('explains what’s wrong with a mobile number it can’t text, and keeps what was typed', function (): void {
    visit('/')
        ->type('first_name', 'Sam')
        ->type('phone', '123')
        ->type('postcode', '3350')
        ->check('sms_consent')
        ->click('form[action$="/waitlist"] button')
        ->assertSee('Please enter an Australian mobile number')
        ->assertValue('#waitlist-first_name', 'Sam')
        ->assertNoJavaScriptErrors();

    expect(Subscriber::query()->count())->toBe(0);
});

it('signs someone up from the order page while no drop is open', function (): void {
    visit('/order')
        ->type('first_name', 'Alex')
        ->type('phone', '0498 765 432')
        ->type('postcode', '3351')
        ->check('sms_consent')
        ->click('form[action$="/waitlist"] button')
        ->assertSee('Thanks for joining, Alex!')
        ->assertNoJavaScriptErrors();

    expect(Subscriber::query()->sole()->phone)->toBe('+61498765432');
});
