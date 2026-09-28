<?php

use App\Http\Controllers\Checkout\CancelCheckoutController;
use App\Http\Controllers\Checkout\OrderConfirmedController;
use App\Http\Controllers\JoinWaitlistController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Middlewares\CacheResponse;

// Marketing pages look the same for everyone, so they're served from the response cache.
// Their catalogue and FAQ data comes from view composers in AppServiceProvider.
Route::middleware(CacheResponse::class)->group(function () {
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

Route::post('/waitlist', JoinWaitlistController::class)->middleware('throttle:waitlist')->name('waitlist.store');
Route::view('/thank-you', 'pages.thank-you')->name('thank-you');

// Placeholder until the live order page is built.
Route::view('/order', 'pages.order')->name('order');

// Stripe sends customers back here. Neither page is cached: both depend on the order.
Route::get('/checkout/cancel', CancelCheckoutController::class)->name('checkout.cancel');
Route::get('/order-confirmed', OrderConfirmedController::class)->name('order-confirmed');
