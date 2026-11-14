<?php

declare(strict_types=1);

use App\Http\Controllers\Checkout\CancelCheckoutController;
use App\Http\Controllers\Checkout\OrderConfirmedController;
use App\Http\Controllers\EarlyAccessController;
use App\Http\Controllers\EarlyAccessStockController;
use App\Http\Controllers\JoinWaitlistController;
use App\Http\Controllers\OrderPageController;
use App\Http\Controllers\Orders\ShowOrderController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\PreventIndexing;
use App\Models\Order;
use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Middlewares\CacheResponse;

// The short domain in order texts. Its links go on to the order page on the main site, and anything else goes to the
// main site's home page. Registered first, so the main site's routes never answer on it.
if (filled(config('shop.short_domain'))) {
    Route::domain(config()->string('shop.short_domain'))->middleware('throttle:order-page')->group(function (): void {
        Route::get('/{token}', fn (string $token) => redirect()->away(config()->string('shop.url').route('orders.show', ['customerOrder' => $token], absolute: false)))
            ->where('token', '[A-Za-z0-9]{'.Order::ACCESS_TOKEN_LENGTH.'}')
            ->name('short-link');
        Route::get('/{path?}', fn () => redirect()->away(config()->string('shop.url')))->where('path', '.*');
    });
}

// Marketing pages look the same for everyone, so they're served from the response cache.
// Their catalogue and FAQ data comes from view composers in AppServiceProvider.
Route::middleware(CacheResponse::class)->group(function (): void {
    Route::view('/', 'pages.home')->name('home');
    Route::view('/beef-boxes', 'pages.beef-boxes')->name('beef-boxes');
    Route::view('/our-story', 'pages.our-story')->name('our-story');
    Route::view('/delivery', 'pages.delivery')->name('delivery');
    Route::view('/faq', 'pages.faq')->name('faq');
    Route::view('/contact', 'pages.contact')->name('contact');
    Route::view('/privacy', 'pages.privacy')->name('privacy');

    Route::get('/sitemap-index.xml', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('/sitemap-0.xml', [SitemapController::class, 'show'])->name('sitemap.pages');
});

// Refunds now live on the delivery page.
Route::permanentRedirect('/delivery-and-refunds', '/delivery#refunds');

// The short map link in pickup texts. A temporary redirect, so where it points can change.
Route::get('/farm', fn () => redirect()->away(config()->string('shop.location.map_url')))->name('farm');

Route::post('/waitlist', JoinWaitlistController::class)->middleware('throttle:waitlist')->name('waitlist.store');
Route::view('/thank-you', 'pages.thank-you')->name('thank-you');

// Not cached: the form holds each visitor's choices.
Route::get('/order', OrderPageController::class)->name('order');

// A drop's secret early-access link. Not cached: the form holds each visitor's choices, and the link is private.
Route::middleware(['throttle:early-access', PreventIndexing::class])->group(function (): void {
    Route::get('/early/{token}', EarlyAccessController::class)->name('early-access');
    Route::get('/early/{token}/stock', EarlyAccessStockController::class)->name('early-access.stock');
});

// A customer's own order page, linked from their order text and email. The secret in its address is the only key, so
// it's kept out of search results and caches, and throttled so guessing is pointless. See the customerOrder binding in
// AppServiceProvider.
Route::middleware(['throttle:order-page', PreventIndexing::class])->prefix('orders/{customerOrder}')->group(function (): void {
    Route::get('/', ShowOrderController::class)->middleware('cache.headers:private;no_store')->name('orders.show');
});

// Stripe sends customers back here. Neither page is cached: both depend on the order. Both can ask Stripe
// about the order, so they're throttled to keep the shop's Stripe rate limit for checkouts.
Route::middleware('throttle:checkout-return')->group(function (): void {
    Route::get('/checkout/cancel', CancelCheckoutController::class)->name('checkout.cancel');
    Route::get('/order-confirmed', OrderConfirmedController::class)->name('order-confirmed');
});
