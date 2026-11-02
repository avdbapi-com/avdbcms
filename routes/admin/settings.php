<?php

// Redesigned Settings and Money pages. Loaded by routes/admin.php inside the admin group, so every route here
// already has the /admin prefix, the 'admin.' name prefix and the admin middleware.

use App\Http\Controllers\Admin\MoneyController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SettingsPageController;

// One save for pages that mix settings groups. The legacy PUT /admin/settings/update/{group}
// stays as it is for fleet scripts and the old settings page.
Route::put('/settings/save', [SettingsController::class, 'save'])->name('settings.save');

// The page script is the same for every admin and language (its words come from the page), so it skips the
// Language middleware, which would mark it no-store and vary it on the (re-encrypted on every response) cookie.
// No ".js" at the end of the address: web servers that answer *.js addresses from disk would never reach PHP.
// It reads the session as the picture routes do (ReadOnlySession): a <script> tag cannot mark itself as a background
// request, and the script's address became "the page you came from": a page opened without a Referer that goes back
// (the view-only demo's closed pages) showed the script's text.
Route::get('/settings/page-script', [SettingsPageController::class, 'script'])
    ->withoutMiddleware([\App\Http\Middleware\Language::class, ...\App\Http\Middleware\ReadOnlySession::REPLACES])
    ->middleware(\App\Http\Middleware\ReadOnlySession::class)
    ->name('settings.script');
Route::get('/settings/values', [SettingsPageController::class, 'values'])->name('settings.values');
Route::post('/settings/upload', [SettingsPageController::class, 'upload'])->name('settings.upload');

Route::post('/settings/maintenance/reveal', [SettingsPageController::class, 'revealMaintenanceSecret'])
    ->middleware('throttle:admin-secret-reveal')
    ->name('settings.maintenance.reveal');

Route::get('/settings/{page}', [SettingsPageController::class, 'show'])
    ->whereIn('page', array_keys(SettingsPageController::PAGES))
    ->name('settings.page');

Route::controller(MoneyController::class)->prefix('money')->name('money.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/ads', 'ads')->name('ads');
    Route::get('/setup', 'setup')->name('setup');
    Route::put('/setup/keys', 'updateKeys')->name('setup.keys');
    Route::get('/premium-page', 'premiumPage')->name('premium-page');
});
