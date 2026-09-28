<?php

use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\TwilioInboundController;
use App\Http\Controllers\Webhooks\TwilioStatusController;
use App\Http\Middleware\ValidateTwilioSignature;
use Illuminate\Support\Facades\Route;

// Same path as the Astro site, so the Stripe endpoint's URL doesn't change at the switch-over.
Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

Route::prefix('webhooks/twilio')->name('webhooks.twilio.')->middleware(ValidateTwilioSignature::class)->group(function () {
    Route::post('status', TwilioStatusController::class)->name('status');
    Route::post('inbound', TwilioInboundController::class)->name('inbound');
});
