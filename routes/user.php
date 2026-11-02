<?php

use App\Http\Controllers\User\PageController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\ActivityController;
use App\Http\Controllers\User\PlaylistController;
use App\Http\Controllers\User\CommentController;
use App\Http\Controllers\User\ReportController;
use App\Http\Controllers\User\NotificationController;
use App\Http\Controllers\User\InvoiceController;
use App\Http\Controllers\User\VideoController;

Route::prefix('profile')->name('user.')->middleware(['auth'])->group(function () {

    // Pages
    Route::controller(PageController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/subscribers', 'subscribers')->name('subscribers');
    });

    // Profile
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/edit', 'edit')->name('edit');
        Route::put('/update', 'update')->name('update');
        Route::post('/update-password', 'updatePassword')->name('update.password')->middleware(['password.confirm', 'throttle:member-sessions']);
        Route::delete('/delete', 'delete')->name('delete')->middleware('password.confirm');;
    });

    // Videos
    Route::controller(VideoController::class)->prefix('videos')->name('videos.')->group(function () {
        Route::post('{video}/pin', 'pin')
            ->name('pin')
            ->can('pin', 'video');
        Route::post('{video}/unpin', 'unpin')
            ->name('unpin')
            ->can('unpin', 'video');
        Route::get('{video}/create', 'create')
            ->name('create')
            ->can('update', 'video');
        Route::post('{video}/store', 'store')
            ->name('store')
            ->can('update', 'video');
        Route::delete('mass-delete', 'massDelete')
            ->name('mass-delete');
    });

    Route::resource('videos', VideoController::class)->except([
        'create', 'store'
    ]);

    // Comments
    Route::delete('comments/mass-delete', [CommentController::class, 'massDelete'])->name('comments.mass-delete');
    Route::resource('comments', CommentController::class)->only(['index', 'destroy']);

    // Playlists
    Route::delete('playlists/mass-delete', [PlaylistController::class, 'massDelete'])->name('playlists.mass-delete');
    Route::resource('playlists', PlaylistController::class)->except(['show'])->middlewareFor(['create', 'store'], 'verified');
    Route::post('playlists/{playlist}/favorite', [PlaylistController::class, 'favorite'])->name('playlists.favorite')->can('favorite', 'playlist');

    // Activity
    Route::controller(ActivityController::class)->prefix('activity')->name('activity.')->group(function () {
        Route::get('/', 'index')->name('index');
    });

    // Reports
    Route::controller(ReportController::class)->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{report}/cancel', 'cancel')->name('cancel');
    });

    // Invoices
    Route::controller(InvoiceController::class)->prefix('invoice')->name('invoices.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{transaction}', 'show')
            ->name('show')
            ->can('show', 'transaction');
    });

    // Notifications
    Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
        Route::get('{notification}/click', 'click')
            ->name('click')
            ->can('click', 'notification');
    });

    // Member redesign (member design 9.1 step 4): the routes of the rebuilt member pages: the bulk visibility of My
    // videos and the replies sheet of Comments, "Sign out of the other devices" and "Download my data", the
    // notifications page and its settings.
    // The bulk visibility and "Sign out of the other devices" have a limit per member (R1B-026: RouteServiceProvider).
    Route::post('videos/mass-visibility', [VideoController::class, 'massVisibility'])->name('videos.mass-visibility')
        ->middleware('throttle:member-bulk-visibility');
    Route::get('comments/{comment}/replies', [CommentController::class, 'replies'])->name('comments.replies');
    Route::post('devices/sign-out-others', [ProfileController::class, 'signOutOtherDevices'])
        ->name('devices.sign-out-others')
        ->middleware(['password.confirm', 'throttle:member-sessions']);
    Route::get('export', [ProfileController::class, 'export'])->name('export')->middleware('throttle:member-export');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::put('notifications/settings', [NotificationController::class, 'settings'])->name('notifications.settings');
});



