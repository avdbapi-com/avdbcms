<?php

// Home, Finish update and the status probes of the redesigned admin. Loaded by routes/admin.php inside the admin
// group, so every route here already has the /admin prefix, the 'admin.' name prefix and the admin middleware.

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\StatusProbeController;
use App\Http\Controllers\Admin\UpdateController;
use Illuminate\Support\Facades\Route;

Route::post('/home/dismiss', [AdminController::class, 'dismiss'])->name('home.dismiss');

// One click on Home after a new version is uploaded: the database changes, then the look the upgrade moved.
Route::controller(UpdateController::class)->prefix('update')->name('update.')->group(function () {
    Route::post('/finish', 'finish')->name('finish');
    Route::post('/restore-look', 'restoreLook')->name('restore-look');
    Route::post('/new-look', 'newLook')->name('new-look');
    Route::post('/keep-look', 'keepLook')->name('keep-look');
});

// Slow server checks, asked by the pages after they appear (cached; see StatusProbeController::PROBES).
Route::get('/status/{probe}', [StatusProbeController::class, 'show'])
    ->whereIn('probe', array_keys(StatusProbeController::PROBES))
    ->name('status.probe');
