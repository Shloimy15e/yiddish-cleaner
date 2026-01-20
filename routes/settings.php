<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile')->name('settings.index');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('settings.profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('settings.profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('settings.profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('settings.user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('settings.user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('settings.appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('settings.two-factor.show');

    // API Credentials
    Route::get('settings/credentials', [SettingsController::class, 'credentials'])->name('settings.credentials');
    Route::post('settings/credentials', [SettingsController::class, 'storeApiCredential'])->name('settings.credentials.store');
    Route::delete('settings/credentials/{provider}/{type}', [SettingsController::class, 'deleteApiCredential'])->name('settings.credentials.delete');

    // Google OAuth
    Route::get('settings/google/redirect', [SettingsController::class, 'googleRedirect'])->name('settings.google.redirect');
    Route::get('settings/google/callback', [SettingsController::class, 'googleCallback'])->name('settings.google.callback');
    Route::delete('settings/google', [SettingsController::class, 'googleDisconnect'])->name('settings.google.disconnect');
});
