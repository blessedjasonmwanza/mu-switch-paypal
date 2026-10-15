<?php

use App\Http\Controllers\AiCredentialController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeePolicyController;
use App\Http\Controllers\FloatRuleController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaypalCheckoutController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\TreasuryController;
use App\Http\Controllers\TreasuryTransferController;
use App\Support\Coverage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome', [
    'countries' => Coverage::countries(),
    'githubUrl' => 'https://github.com/moneyUnify/mu-switch',
    'stats' => Coverage::stats(),
    'docsOnly' => config('app.docs_only'),
]))->name('home');

if (! config('app.docs_only_routes')) {
    Route::get('/up', fn (Request $request) => $request->expectsJson()
        ? response()->json(['status' => 'up'])
        : response(View::file(base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/health-up.blade.php'))));

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/api-token', [ApiTokenController::class, 'show'])
            ->name('api-token.show');

        Route::post('/api-token/regenerate', [ApiTokenController::class, 'regenerate'])
            ->name('api-token.regenerate');

        Route::get('logs', [ApiLogController::class, 'index'])->name('logs.index');

        Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
        Route::get('providers/{provider}/logs', [ProviderController::class, 'logs'])->name('providers.logs');
        Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
        Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
        Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

        Route::get('fee-policy', [FeePolicyController::class, 'show'])->name('fee-policy.show');
        Route::put('fee-policy', [FeePolicyController::class, 'update'])->name('fee-policy.update');

        Route::get('treasury', [TreasuryController::class, 'index'])->name('treasury.index');
        Route::put('treasury/float-rules', [FloatRuleController::class, 'upsert'])->name('treasury.float-rules.upsert');
        Route::delete('treasury/float-rules/{floatRule}', [FloatRuleController::class, 'destroy'])->name('treasury.float-rules.destroy');
        Route::prefix('treasury/transfers')->name('treasury.transfers.')->controller(TreasuryTransferController::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('{transfer}/approve', 'approve')->name('approve');
            Route::post('{transfer}/send', 'send')->name('send');
            Route::post('{transfer}/settle', 'settle')->name('settle');
            Route::post('{transfer}/fail', 'fail')->name('fail');
            Route::post('{transfer}/cancel', 'cancel')->name('cancel');
        });

        Route::get('ai', [AiCredentialController::class, 'index'])->name('ai.index');
        Route::prefix('ai/credentials')->name('ai.credentials.')->controller(AiCredentialController::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::put('order', 'reorder')->name('reorder');
            Route::put('{credential}', 'update')->name('update');
            Route::delete('{credential}', 'destroy')->name('destroy');
            Route::delete('{credential}/key', 'destroyKey')->name('key.destroy');
            Route::post('{credential}/test', 'test')->middleware('throttle:6,1')->name('test');
        });
    });

    // Where PayPal returns the payer after checkout (signed links).
    Route::prefix('checkout/paypal/{transaction:transaction_id}')
        ->controller(PaypalCheckoutController::class)
        ->name('paypal.checkout.')
        ->group(function () {
            Route::get('return', 'approved')->name('return');
            Route::get('cancel', 'cancelled')->name('cancel');
        });

    require __DIR__.'/settings.php';
}

require __DIR__.'/prezet.php';
