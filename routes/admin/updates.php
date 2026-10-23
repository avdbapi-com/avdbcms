<?php

// Tools > Updates: the one-click update from the GitHub releases of AVDBCMS (App\Support\Updater). Loaded by
// routes/admin.php inside the admin group, so every route here already has the /admin prefix, the 'admin.' name prefix
// and the admin middleware. The page, its status and "Check now" are for every administrator; installing, continuing,
// cancelling, undoing and deleting a backup also need the operational tools (admin.ops), like Backups: the update makes
// a database backup. All of those are POST (CSRF like every admin form); nothing here installs on a GET.

use App\Http\Controllers\Admin\UpdatesController;
use Illuminate\Support\Facades\Route;

Route::controller(UpdatesController::class)->prefix('updates')->name('updates.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/status', 'status')->name('status');
    Route::post('/check', 'check')->name('check');

    Route::middleware('admin.ops')->group(function () {
        Route::post('/start', 'start')->name('start');
        Route::post('/step', 'step')->name('step');
        // While the swap runs, public/index.php hands this address to the maintenance hook (no Laravel); once the hook
        // is gone, the same POST reaches this route.
        Route::post('/swap', 'swap')->name('swap');
        Route::post('/finish', 'finish')->name('finish');
        Route::post('/cancel', 'cancel')->name('cancel');
        Route::post('/undo', 'undo')->name('undo');
        Route::post('/backups/{id}/delete', 'deleteBackup')
            ->where('id', '\d+\.\d+\.\d+-to-\d+\.\d+\.\d+-\d{14}')
            ->name('backups.delete');
    });
});
