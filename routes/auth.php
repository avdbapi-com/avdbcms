<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\EmailVerification;
use App\Http\Controllers\Auth\PhoneVerification;
use App\Support\OAuthProviders;

// LOGIN
Route::controller(LoginController::class)->group(function () {
    Route::get('/login', 'show')->name('login')->middleware('guest');
    Route::post('/login', 'login')->name('login.perform')->middleware(['guest', 'throttle:login']);
    // Signing out changes something, so it is a POST with the page's token (auth-22): a link or an image on another
    // site cannot sign a member out. An old page's "Logout" link (a GET) only shows a small page with the same button.
    Route::match(['GET', 'POST'], '/logout', 'logout')->name('logout');
});

// OAUTH
Route::controller(OAuthController::class)->name('oauth.')->prefix('oauth')->group(function () {
    Route::get('/connect/{service}', 'connect')
        ->name('connect')
        ->whereIn('service', OAuthProviders::supported());
    Route::get('/callback/{service}', 'callback')
        ->name('callback')
        ->whereIn('service', OAuthProviders::supported());
    // A POST with the CSRF token: a link on another site cannot unlink a member's social sign-in.
    Route::post('/unlink/{service}', 'unlink')
        ->name('unlink')
        ->middleware('auth')
        ->whereIn('service', OAuthProviders::supported());
});

// REGISTER
Route::controller(RegistrationController::class)->middleware('guest')->group(function () {
    Route::get('/register', 'show')->name('registration');
    Route::post('/register', 'register')->name('registration.perform')->middleware('throttle:register');
});

// PASSWORD RESET
Route::controller(PasswordController::class)->middleware('guest')->group(function () {
    Route::get('/forgot-password', 'forgot')->name('password.forgot');
    Route::post('/forgot-password', 'email')->name('password.email')->middleware('throttle:password-email');
    Route::get('/reset-password/{token}', 'reset')->name('password.reset');
    Route::post('/reset-password', 'update')->name('password.update')->middleware('throttle:password-reset');
});

// EMAIL VERIFICATION
Route::controller(EmailVerification::class)
    ->middleware('auth')
    ->name('verification.')
    ->group(function () {
        Route::get('/email/verify', 'notice')->name('notice');
        Route::get('/email/verify/{id}/{hash}', 'verify')->name('verify')->middleware('signed');
        Route::post('/email/verification-notification', 'send')->name('send')->middleware('rate:1');
        Route::get('/email/verify-update/{id}/{hash}', 'verifyUpdate')->name('verify.update')->middleware('signed');
        Route::post('/email/verification-notification-update', 'sendUpdate')->name('send.update')->middleware('rate:1');
        Route::post('/email/verification-notification-update-cancel', 'cancelUpdate')->name('verify.update.cancel');
    });

// PHONE VERIFICATION
Route::controller(PhoneVerification::class)
    ->middleware('auth')
    ->name('phone.')
    ->prefix('phone')
    ->group(function () {
        Route::post('/verification-notification', 'send')->name('send')->middleware('rate:1');
        // Its own named limit (RouteServiceProvider "phone-verify"): about 10 checks a minute per member, answered under the
        // code field in the member's language (R2A-030). Each code allows 5 wrong tries in all (R1-164).
        Route::post('/verify', 'verify')->name('verify')->middleware('throttle:phone-verify');
    });
