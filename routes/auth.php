<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
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
     *
     * SELF-SERVICE PASSWORD RESET IS ALSO GONE, deliberately, for the same reason.
     *
     * The flow used to ship unreachable: no link on the login page, because accounts
     * are made and reset by an administrator under Settings > Users. Present but
     * unlinked it was live attack surface -- `password.email` an unauthenticated
     * caller could use to spray mail and probe which addresses are registered, and
     * `password.store` a token endpoint with no reason to exist. Throttling it made it
     * less bad; deleting it makes the question moot.
     *
     * A signed-in user can still change their own password from their profile, which
     * requires the current password.
     */
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);
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
