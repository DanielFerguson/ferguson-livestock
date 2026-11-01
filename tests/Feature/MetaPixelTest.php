<?php

declare(strict_types=1);

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Support\PublicPages;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Js;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

it('loads Meta’s pixel with the shop’s pixel ID on every public page', function (string $path): void {
    enableMetaAds();

    $this->get($path)
        ->assertOk()
        ->assertSee('https://connect.facebook.net/en_US/fbevents.js', false)
        ->assertSee("fbq('init', '1234567890');", false)
        ->assertSee("fbq('track', 'PageView');", false)
        ->assertDontSee('<meta name="referrer"', false);
})->with(fn (): array => [...PublicPages::indexable(), ...PublicPages::noindex()]);

it('reports a view of the beef boxes', function (): void {
    enableMetaAds();

    $this->get('/beef-boxes')->assertSee("fbq('track', 'ViewContent', ".Js::from(['content_name' => 'Beef boxes']).');', false);
});

it('keeps the pixel off a drop’s secret early-access link, and its address out of the next page’s referrer', function (): void {
    enableMetaAds();
    $shop = StockedDrop::create(drop: ['published_at' => null, 'opens_at' => now()->addWeek()]);
    $shop->drop->startEarlyAccess();

    $this->get(route('early-access', ['token' => $shop->drop->early_access_token]))
        ->assertOk()
        ->assertDontSee('fbq(', false)
        ->assertSee('<meta name="referrer" content="strict-origin">', false);
});

it('keeps the pixel off the order confirmation, whose address shows the order', function (): void {
    enableMetaAds(conversionsApi: false);
    Mail::fake();
    $stripe = FakePaymentGateway::swap();
    $shop = StockedDrop::create();
    app(StartCheckout::class)(new CheckoutRequest($shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, 'visitor-a', '192.0.2.1'));
    $stripe->completeSession('cs_test_1', 'paid', 'Sam Buyer');

    $this->get(route('order-confirmed', ['session_id' => 'cs_test_1']))
        ->assertOk()
        ->assertDontSee('fbq(', false)
        ->assertSee('<meta name="referrer" content="strict-origin">', false);
});
