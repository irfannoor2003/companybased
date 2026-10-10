<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    /**
     * No self-registration.
     *
     * This is a single-company installation: one company, one database, one
     * subscription. An open registration form is not a feature here, it is a way
     * for anyone who finds the login page to create an account. Accounts are made
     * by an administrator under Settings > Users.
     *
     * The route was previously behind `config('app.enable_registration')` and the
     * controller and blade were left in the tree behind it, unreachable but
     * shipped. Deleted rather than gated — a flag defaulting to false only means
     * the dangerous path still exists and one env var away from being live.
     * Removed as README PROD-2.
     */

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    /**
     * Throttled because this endpoint sends mail.
     *
     * Without a limit it can be used to spray mail at an address and to enumerate
     * accounts: the response has to stay identical either way (Laravel's
     * PasswordBroker does that deliberately), but the *volume* of outgoing mail is
     * not something an unauthenticated caller should control. Six a minute matches
     * the verification routes below.
     */
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    /**
     * Throttled separately from the link request: a leaked or guessed token is
     * brute-forceable, and without a limit an attacker with a valid email could
     * cycle through a token space at whatever rate the server allows.
     */
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
