<?php

use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/ai', [AiSettingsController::class, 'edit'])->name('ai-settings.edit');
    Route::put('settings/ai', [AiSettingsController::class, 'update'])->name('ai-settings.update');
    Route::delete('settings/ai/key', [AiSettingsController::class, 'destroyKey'])->name('ai-settings.key.destroy');
    Route::post('settings/ai/test', [AiSettingsController::class, 'test'])
        ->middleware('throttle:6,1')
        ->name('ai-settings.test');
});
