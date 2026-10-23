<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\CommentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VideoController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\SpamController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SearchHistoryController;
use App\Http\Controllers\Admin\PlaylistController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ViewAnalyticsController;
use App\Http\Controllers\Admin\InteractionController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\ThumbnailController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\IpBanController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\BulkActionController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\EmailTestController;
use App\Http\Controllers\Admin\CronController;
use App\Http\Controllers\Admin\RedirectController;
use App\Http\Controllers\Admin\MediaLibraryController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\PremiumController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\FeaturedController;
use App\Http\Controllers\Admin\EnvEditorController;
use App\Http\Controllers\Admin\LogViewerController;
use App\Http\Controllers\Admin\QueueMonitorController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\PreflightController;
use App\Http\Controllers\Admin\ArtisanController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\PhpInfoController;
use App\Http\Controllers\Admin\StorageController;
use App\Http\Controllers\Admin\UserVerificationController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\AvdbImportController;
use App\Http\Controllers\Admin\XvidImportController;
use Illuminate\Support\Facades\Redis;

Route::prefix('admin')->name('admin.')->middleware(['admin', 'admin.cache'])->group(function () {

    // Dashboard
    Route::get('/', [AdminController::class, 'index'])->name('index');

    // Settings
    Route::controller(SettingsController::class)->prefix('settings')->name('settings.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/update', 'update')->name('update');
        Route::put('/update/{group}', 'updateGroup')->name('update.group');
        Route::put('/update-env', 'updateEnv')->name('update.env');
        Route::post('/clear-cache/{type?}', 'clearCache')->name('clear.cache');
        Route::post('/test-email', 'sendTestEmail')->name('test-email');
        Route::post('/test-stream', 'testVideoStream')->name('test-stream');
        Route::post('/apply-theme', 'applyThemePreset')->name('apply-theme');
        Route::post('/apply-experience', 'applyMasterExperience')->name('apply-experience');
        Route::post('/reset-theme', 'resetDefaultTheme')->name('reset-theme');
    });

    // AVDB Import
    Route::controller(AvdbImportController::class)->prefix('avdb-import')->name('avdb-import.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/test-connection', 'testConnection')->name('test');
        Route::post('/start', 'startImport')->name('start');
        Route::post('/schedules', 'storeSchedule')->name('schedules.store');
        Route::post('/schedules/run-due', 'runDueSchedules')->name('schedules.run-due');
        Route::post('/schedules/{schedule}/toggle', 'toggleSchedule')->name('schedules.toggle');
        Route::post('/schedules/{schedule}/run-now', 'runScheduleNow')->name('schedules.run-now');
        Route::delete('/schedules/{schedule}', 'destroySchedule')->name('schedules.destroy');
        Route::get('/runs/clean-finished', 'cleanFinishedRunsHelp')->name('runs.clean-finished.help');
        Route::delete('/runs/clean-finished', 'cleanFinishedRuns')->name('runs.clean-finished');
        Route::post('/runs/force-stop', 'forceStop')->name('force-stop');
        Route::get('/runs/{run}', 'progress')->name('progress');
        Route::post('/runs/{run}/stop', 'stop')->name('stop');
        Route::delete('/runs/{run}', 'destroyRun')->name('runs.destroy');
        Route::get('/runs/{run}/issues/{type}', 'exportIssues')->name('issues.export');
        Route::get('/stats', 'stats')->name('stats');
        Route::post('/clean', 'clean')->name('clean');
    });

    // XVIDAPI Import
    Route::controller(XvidImportController::class)->prefix('xvid-import')->name('xvid-import.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/test-connection', 'testConnection')->name('test');
        Route::post('/start', 'startImport')->name('start');
        Route::post('/schedules', 'storeSchedule')->name('schedules.store');
        Route::post('/schedules/{schedule}/toggle', 'toggleSchedule')->name('schedules.toggle');
        Route::post('/schedules/{schedule}/run-now', 'runScheduleNow')->name('schedules.run-now');
        Route::delete('/schedules/{schedule}', 'destroySchedule')->name('schedules.destroy');
        Route::post('/runs/force-stop', 'forceStop')->name('force-stop');
        Route::post('/runs/clean-finished', 'cleanFinishedRuns')->name('runs.clean-finished');
        Route::get('/runs/{run}', 'progress')->name('progress');
        Route::post('/runs/{run}/stop', 'stop')->name('stop');
        Route::delete('/runs/{run}', 'destroyRun')->name('runs.destroy');
        Route::get('/stats', 'stats')->name('stats');
        Route::post('/clean', 'clean')->name('clean');
    });

    // Users
    Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{user}/show', 'show')->name('show');
        Route::get('/{user}/edit', 'edit')->name('edit');
        Route::put('/{user}/update', 'update')->name('update');
        Route::post('/{user}/ban', 'ban')
            ->name('ban')
            ->can('ban', 'user');
        Route::post('/{user}/unban', 'unban')->name('unban');
        Route::post('/{user}/toggle-admin', 'toggleAdmin')->name('toggle-admin');
        Route::post('/{user}/confirm', 'confirm')->name('confirm');
        Route::post('/{user}/unverify', 'unverify')->name('unverify');
        Route::delete('/{user}/delete', 'delete')->name('delete');
        Route::get('/export', 'export')->name('export');
    });

    // Videos
    Route::controller(VideoController::class)->prefix('videos')->name('videos.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{video}/edit', 'edit')->name('edit');
        Route::put('/{video}/update', 'update')->name('update');
        Route::post('/{video}/ban', 'ban')->name('ban');
        Route::post('/{video}/unban', 'unban')->name('unban');
        Route::delete('/{video}/delete', 'destroy')->name('delete');
    });

    // Comments
    Route::controller(CommentController::class)->prefix('comments')->name('comments.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{comment}/ban', 'ban')->name('ban');
        Route::post('/{comment}/unban', 'unban')->name('unban');
        Route::delete('/{comment}/delete', 'destroy')->name('delete');
    });

    // Reports
    Route::controller(ReportController::class)->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{type}/{reportable}/block', 'block')->name('block');
        Route::post('/{type}/{reportable}/cancel', 'cancel')->name('cancel');
    });

    // Categories
    Route::controller(CategoryController::class)->prefix('categories')->name('categories.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{category}/edit', 'edit')->name('edit');
        Route::put('/{category}', 'update')->name('update');
        Route::delete('/{category}', 'destroy')->name('destroy');
        Route::post('/organize', 'organize')->name('organize');
    });

    // Plans
    Route::controller(PlanController::class)->prefix('plans')->name('plans.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{plan}/edit', 'edit')->name('edit');
        Route::put('/{plan}', 'update')->name('update');
        Route::delete('/{plan}', 'destroy')->name('destroy');
    });

    // Invoices
    Route::controller(InvoiceController::class)->prefix('invoice')->name('invoices.')->group(function () {
        Route::get('/', 'index')->name('index');
    });

    // Exports
    Route::controller(ExportController::class)->prefix('exports')->name('exports.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{export}/download', 'download')
            ->name('download')
            ->can('download', 'export');
    });

    // Spam
    Route::controller(SpamController::class)->prefix('spam')->name('spams.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/update', 'update')->name('update');
    });

    // Subscriptions
    Route::controller(SubscriptionController::class)->prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{subscription}/cancel', 'cancel')->name('cancel');
        Route::post('/{subscription}/activate', 'activate')->name('activate');
        Route::delete('/{subscription}/delete', 'destroy')->name('delete');
    });

    // Playlists
    Route::controller(PlaylistController::class)->prefix('playlists')->name('playlists.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/{playlist}/delete', 'destroy')->name('delete');
    });

    // Search History
    Route::controller(SearchHistoryController::class)->prefix('searches')->name('searches.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/clear', 'clear')->name('clear');
        Route::delete('/{search}/delete', 'destroy')->name('delete');
    });

    // Activities
    Route::controller(ActivityController::class)->prefix('activities')->name('activities.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/clear', 'clear')->name('clear');
        Route::delete('/{activity}/delete', 'destroy')->name('delete');
    });

    // Notifications
    Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/broadcast', 'broadcast')->name('broadcast');
        Route::delete('/{notification}/delete', 'destroy')->name('delete');
        Route::delete('/clear', 'clear')->name('clear');
    });


    // View Analytics
    Route::controller(ViewAnalyticsController::class)->prefix('views')->name('views.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/clear', 'clear')->name('clear');
        Route::delete('/{id}/delete', 'destroy')->name('delete');
    });

    // Interactions (Likes/Dislikes)
    Route::controller(InteractionController::class)->prefix('interactions')->name('interactions.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/clear', 'clear')->name('clear');
        Route::delete('/{id}/delete', 'destroy')->name('delete');
    });

    // Devices / Sessions
    Route::controller(DeviceController::class)->prefix('devices')->name('devices.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/clear-expired', 'clearExpired')->name('clear-expired');
        Route::delete('/{device}/delete', 'destroy')->name('delete');
        Route::delete('/user/{userId}/revoke', 'revokeUser')->name('revoke-user');
    });

    // Failed Queue Jobs
    Route::controller(FailedJobController::class)->prefix('failed-jobs')->name('failed-jobs.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/retry-all', 'retryAll')->name('retry-all');
        Route::post('/{uuid}/retry', 'retry')->name('retry');
        Route::delete('/{uuid}/delete', 'destroy')->name('delete');
        Route::delete('/clear', 'clear')->name('clear');
    });

    // Thumbnails
    Route::controller(ThumbnailController::class)->prefix('thumbnails')->name('thumbnails.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{id}/set-active', 'setActive')->name('set-active');
        Route::delete('/{id}/delete', 'destroy')->name('delete');
    });

    // Password Resets
    Route::controller(PasswordResetController::class)->prefix('password-resets')->name('password-resets.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/clear', 'clear')->name('clear');
        Route::delete('/{email}/delete', 'destroy')->name('delete');
    });

    // Database
    Route::controller(DatabaseController::class)->prefix('database')->name('database.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/optimize', 'optimize')->name('optimize');
        Route::post('/migrate', 'migrate')->name('migrate');
        Route::delete('/{table}/truncate', 'truncate')->name('truncate');
    });

    // Transactions
    Route::controller(TransactionController::class)->prefix('transactions')->name('transactions.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/{id}/delete', 'destroy')->name('delete');
    });

    // Logs
    Route::get('/logs', fn() => redirect('logs'))->middleware('admin.ops')->name('logs');

    // IP Bans
    Route::controller(IpBanController::class)->prefix('ip-bans')->name('ip-bans.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('/{id}', 'destroy')->name('delete');
        Route::post('/clear-expired', 'clearExpired')->name('clear-expired');
    });

    // Backups
    Route::controller(BackupController::class)->prefix('backups')->name('backups.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/create', 'createDatabase')->name('create');
        Route::get('/download/{filename}', 'download')->name('download');
        Route::delete('/{filename}', 'destroy')->name('delete');
    });

    // Contact Messages
    Route::controller(ContactMessageController::class)->prefix('contacts')->name('contacts.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{id}', 'show')->name('show');
        Route::delete('/{id}', 'destroy')->name('delete');
        Route::post('/mark-read', 'markAllRead')->name('mark-read');
    });

    // Bulk Actions
    Route::prefix('bulk')->name('bulk.')->group(function () {
        Route::post('/videos', [BulkActionController::class, 'videos'])->name('videos');
        Route::post('/users', [BulkActionController::class, 'users'])->name('users');
        Route::post('/comments', [BulkActionController::class, 'comments'])->name('comments');
        Route::post('/playlists', [BulkActionController::class, 'playlists'])->name('playlists');
        Route::post('/notifications', [BulkActionController::class, 'notifications'])->name('notifications');
        Route::post('/activities', [BulkActionController::class, 'activities'])->name('activities');
        Route::post('/interactions', [BulkActionController::class, 'interactions'])->name('interactions');
        Route::post('/devices', [BulkActionController::class, 'devices'])->name('devices');
        Route::post('/failed-jobs', [BulkActionController::class, 'failedJobs'])->name('failed-jobs');
        Route::post('/contacts', [BulkActionController::class, 'contacts'])->name('contacts');
        Route::post('/reports', [BulkActionController::class, 'reports'])->name('reports');
    });

    // System Info
    Route::controller(SystemController::class)->prefix('system')->name('system.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'phpinfo')->name('index');
        Route::post('/clear/{type}', 'clearCache')->name('clear');
    });

    // Preflight / environment diagnostics
    Route::controller(PreflightController::class)->prefix('preflight')->name('preflight.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/json', 'json')->name('json');
        Route::post('/refresh', 'refresh')->name('refresh');
        Route::post('/fix', 'fix')->name('fix');
    });

    // Audit Log
    Route::controller(AuditLogController::class)->prefix('audit')->name('audit.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/clear', 'clear')->name('clear');
    });

    // Sessions
    Route::controller(SessionController::class)->prefix('sessions')->name('sessions.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/{id}', 'destroy')->name('destroy');
        Route::post('/clear-all', 'clearAll')->name('clear-all');
        Route::post('/clear-guests', 'clearGuests')->name('clear-guests');
    });

    // Announcements
    Route::controller(AnnouncementController::class)->prefix('announcements')->name('announcements.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/{id}/toggle', 'toggle')->name('toggle');
        Route::delete('/{id}', 'destroy')->name('destroy');
    });

    // Email Test
    Route::controller(EmailTestController::class)->prefix('email-test')->name('email-test.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/send', 'send')->name('send');
    });

    // Cron / Scheduled Tasks
    Route::controller(CronController::class)->prefix('cron')->name('cron.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/run', 'runScheduler')->name('run');
        Route::post('/interrupt', 'interruptScheduler')->name('interrupt');
        Route::post('/task/run', 'runTask')->name('task.run');
        Route::post('/task/pause', 'pauseTask')->name('task.pause');
        Route::post('/task/resume', 'resumeTask')->name('task.resume');
        Route::post('/duration/start', 'startDurationBackfill')->name('duration.start');
        Route::post('/duration/reset-retries', 'resetDurationRetries')->name('duration.reset-retries');
        Route::get('/duration/status', 'durationStatus')->name('duration.status');
        Route::post('/duration/runner/start', 'startDurationRunner')->name('duration.runner.start');
        Route::post('/duration/runner/batch', 'runDurationBatch')->name('duration.runner.batch');
        Route::post('/logs/clear', 'clearLogs')->name('logs.clear');
    });

    // Redirects
    Route::controller(RedirectController::class)->prefix('redirects')->name('redirects.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('/{id}', 'destroy')->name('delete');
        Route::post('/{id}/toggle', 'toggle')->name('toggle');
    });

    // Media Library
    Route::controller(MediaLibraryController::class)->prefix('media')->name('media.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/delete', 'destroy')->name('delete');
    });

    // API Tokens
    Route::controller(ApiTokenController::class)->prefix('api-tokens')->name('api-tokens.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::delete('/{id}', 'destroy')->name('destroy');
        Route::post('/revoke-all', 'revokeAll')->name('revoke-all');
    });

    // Premium Records
    Route::controller(PremiumController::class)->prefix('premiums')->name('premiums.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{id}/extend', 'extend')->name('extend');
        Route::delete('/{id}', 'destroy')->name('destroy');
    });

    // Mass Broadcast
    Route::controller(BroadcastController::class)->prefix('broadcast')->name('broadcast.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/send', 'sendNotification')->name('send');
    });

    // Featured Content
    Route::controller(FeaturedController::class)->prefix('featured')->name('featured.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/{id}/toggle', 'toggle')->name('toggle');
        Route::delete('/{id}', 'destroy')->name('destroy');
    });

    // .env Editor
    Route::controller(EnvEditorController::class)->prefix('env-editor')->name('env-editor.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/', 'update')->name('update');
    });

    // Log Viewer
    Route::controller(LogViewerController::class)->prefix('logs-viewer')->name('logs.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/download/{filename}', 'download')->name('download');
        Route::delete('/delete/{filename}', 'delete')->name('delete');
        Route::post('/clear-all', 'clearAll')->name('clear-all');
    });

    // Queue Monitor
    Route::controller(QueueMonitorController::class)->prefix('queue')->name('queue.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/worker/start', 'startWorker')->name('worker.start');
        Route::post('/worker/restart', 'restartWorkers')->name('worker.restart');
        Route::post('/cleanup-stale', 'cleanupStale')->name('cleanup-stale');
        Route::post('/jobs/pause-all', 'pauseAll')->name('jobs.pause-all');
        Route::post('/jobs/resume-all', 'resumeAll')->name('jobs.resume-all');
        Route::delete('/jobs/delete-all', 'deleteAllQueued')->name('jobs.delete-all');
        Route::post('/jobs/{id}/pause', 'pauseJob')->whereNumber('id')->name('jobs.pause');
        Route::post('/jobs/{id}/resume', 'resumeJob')->whereNumber('id')->name('jobs.resume');
        Route::post('/jobs/{id}/retry', 'retryQueuedJob')->whereNumber('id')->name('jobs.retry');
        Route::delete('/jobs/{id}', 'deleteQueuedJob')->whereNumber('id')->name('jobs.delete');
        Route::post('/retry/{uuid}', 'retryFailed')->name('retry');
        Route::post('/retry-all', 'retryAllFailed')->name('retry-all');
        Route::delete('/failed/{uuid}', 'deleteFailed')->name('failed.delete');
        Route::post('/flush', 'flushFailed')->name('flush');
        Route::delete('/batch/{id}', 'deleteBatch')->name('delete-batch');
    });

    // Custom Pages (CMS)
    Route::controller(PageController::class)->prefix('pages')->name('pages.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{id}/edit', 'edit')->name('edit');
        Route::put('/{id}', 'update')->name('update');
        Route::delete('/{id}', 'destroy')->name('destroy');
        Route::post('/{id}/toggle', 'togglePublish')->name('toggle');
    });

    // Artisan Commands
    Route::controller(ArtisanController::class)->prefix('artisan')->name('artisan.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/run', 'run')->name('run');
    });

    // Tags
    Route::controller(TagController::class)->prefix('tags')->name('tags.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{id}', 'update')->name('update');
        Route::delete('/{id}', 'destroy')->name('destroy');
        Route::post('/merge', 'merge')->name('merge');
        Route::post('/delete-unused', 'deleteUnused')->name('delete-unused');
    });

    // PHP Info
    Route::get('/phpinfo', [PhpInfoController::class, 'index'])->middleware('admin.ops')->name('phpinfo.index');

    // Storage Manager
    Route::controller(StorageController::class)->prefix('storage')->name('storage.')->middleware('admin.ops')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/clear/{type}', 'clearDirectory')->name('clear');
    });

    // User Verification
    Route::controller(UserVerificationController::class)->prefix('verification')->name('verification.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{id}/verify', 'verify')->name('verify');
        Route::post('/{id}/unverify', 'unverify')->name('unverify');
        Route::post('/verify-all', 'verifyAll')->name('verify-all');
    });

    // Languages — full CRUD
    Route::controller(LanguageController::class)->prefix('languages')->name('languages.')->group(function () {
        // Language list & overview
        Route::get('/',                          'index')->name('index');
        // Add new language
        Route::get('/create',                    'create')->name('create');
        Route::post('/',                         'store')->name('store');
        // Edit language metadata (label, emoji, direction, active)
        Route::get('/{language}/meta/edit',      'editMeta')->name('edit-meta');
        Route::put('/{language}/meta',           'updateMeta')->name('update-meta');
        // Actions
        Route::post('/{language}/set-default',   'setDefault')->name('set-default');
        Route::post('/{language}/toggle-active', 'toggleActive')->name('toggle-active');
        Route::delete('/{language}',             'destroy')->name('destroy');
        // Translation editing (JSON files)
        Route::get('/{locale}/edit',             'edit')->name('edit');
        Route::put('/{locale}/translations',     'update')->name('update');
        Route::get('/{locale}/export',           'export')->name('export');
        Route::post('/import',                   'import')->name('import');
    });

    // Horizon shortcut. Horizon itself is mounted at /admin/horizon by the
    // package; keep the menu route separate so it does not shadow the dashboard.
    Route::get('/horizon-link', function () {
        $connection = (string) config('horizon.use', 'default');

        try {
            Redis::connection($connection)->command('PING');
        } catch (Throwable $e) {
            return response()->view('admin.horizon-unavailable', [
                'connection' => $connection,
                'queueDriver' => config('queue.default'),
                'error' => class_basename($e).': '.$e->getMessage(),
                'message' => __('Horizon needs a working Redis connection. This installation can still use the database queue and the built-in Queue Monitor.'),
            ]);
        }

        return redirect('/'.trim((string) config('horizon.path', 'admin/horizon'), '/'));
    })->middleware('admin.ops')->name('horizon');

    // IMPERSONATE
    Route::get('/impersonate/take/{id}/{guardName?}', '\Lab404\Impersonate\Controllers\ImpersonateController@take')
        ->middleware('admin.ops')
        ->name('impersonate');

    // Admin redesign: each package keeps its routes in routes/admin/<name>.php (shell, settings,
    // appearance, pages). They load last, inside this group, so they inherit the 'admin.' name
    // prefix, the /admin URL prefix and the admin middleware. They only ADD addresses and names: a
    // route with the method and address of one above would replace it, and the old route name would
    // stop working (AdminSettingsRedirectsTest fails on that).
    foreach (glob(__DIR__.'/admin/*.php') as $f) { require $f; }

});

Route::prefix('admin')->name('admin.')->middleware(['auth'])->group(function () {
    Route::get('/impersonate/leave', '\Lab404\Impersonate\Controllers\ImpersonateController@leave')
        ->name('impersonate.leave');
});
