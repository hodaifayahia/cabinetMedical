<?php

use App\Http\Controllers\Settings\IdleLockController;
use App\Http\Controllers\Settings\LocalPinController;
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

    Route::post('settings/local-pin', [LocalPinController::class, 'store'])
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('security.local-pin.store');

    Route::delete('settings/local-pin', [LocalPinController::class, 'destroy'])
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('security.local-pin.destroy');

    Route::put('settings/idle-lock', [IdleLockController::class, 'update'])
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('security.idle-lock.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
