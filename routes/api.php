<?php

use App\ApiResponse;
use App\Http\Controllers\FxController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PaypalWebhookController;
use App\Http\Controllers\SwitchController;
use App\Http\Middleware\VerifyApiAccess;
use Illuminate\Support\Facades\Route;

if (config('app.docs_only_routes')) {
    return;
}

Route::prefix('v1')->middleware(VerifyApiAccess::class)->group(function () {
    Route::prefix('payment')->controller(SwitchController::class)->group(function () {
        Route::post('/quote', 'quotePayment');
        Route::post('/request', 'requestPayment');
        Route::post('/verify', 'verifyPayment');
    });

    Route::prefix('payout')->controller(PayoutController::class)->group(function () {
        Route::post('/request', 'requestPayout');
        Route::post('/verify', 'verifyPayout');
    });

    Route::prefix('fx')->controller(FxController::class)->group(function () {
        Route::get('/rates', 'rates');
        Route::post('/quote', 'quote');
        Route::get('/quote/{id}', 'show');
    });
});

// Provider webhooks: authenticated by the provider's signature, not an API token.
Route::post('/webhooks/paypal/{provider}', PaypalWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.paypal');

// catch all 404 for api routes
Route::any('/{any}', function () {
    return ApiResponse::error('Not Found', 404);
})->where('any', '.*');
