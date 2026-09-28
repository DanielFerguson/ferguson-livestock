<?php

use App\Http\Controllers\Webhooks\TwilioInboundController;
use App\Http\Controllers\Webhooks\TwilioStatusController;
use App\Http\Middleware\ValidateTwilioSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks/twilio')->name('webhooks.twilio.')->middleware(ValidateTwilioSignature::class)->group(function () {
    Route::post('status', TwilioStatusController::class)->name('status');
    Route::post('inbound', TwilioInboundController::class)->name('inbound');
});
