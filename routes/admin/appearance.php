<?php

// The Appearance section (Themes + nine pages, draft preview, publish, history). Loaded by routes/admin.php inside
// the admin group, so every route here already has the /admin prefix, the 'admin.' name prefix and the admin
// middleware. Custom code (page "code") needs Developer mode: routes/admin/shell.php attaches that gate.

use App\Http\Controllers\Admin\AppearanceController;
use App\Support\AppearancePages;

Route::controller(AppearanceController::class)->prefix('appearance')->name('appearance.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/gallery', 'gallery')->name('gallery');
    Route::get('/calendar', 'calendar')->name('calendar');
    Route::get('/history', 'history')->name('history');
    Route::post('/history/{revision}/restore', 'restore')->whereNumber('revision')->name('restore');
    Route::post('/draft', 'draft')->name('draft');
    Route::delete('/draft', 'discard')->name('draft.discard');
    Route::post('/publish', 'publish')->name('publish');
    Route::get('/{page}', 'page')->whereIn('page', array_keys(AppearancePages::PAGES))->name('page');
});
