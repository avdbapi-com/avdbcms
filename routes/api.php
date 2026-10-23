<?php

use App\Http\Controllers\Api\Admin\SearchController as SearchAdminController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\Private\DeviceController;
use App\Http\Controllers\Api\Private\InteractionController;
use App\Http\Controllers\Api\Private\NotificationController;
use App\Http\Controllers\Api\Private\PlaybackProgressController;
use App\Http\Controllers\Api\Private\PlaylistController;
use App\Http\Controllers\Api\Private\ReportController;
use App\Http\Controllers\Api\Private\SearchController;
use App\Http\Controllers\Api\Private\ThumbnailController;
use App\Http\Controllers\Api\Private\UserController;
use App\Http\Controllers\Api\Private\VideoController;
use App\Http\Controllers\Api\Private\SubscribeController;
use App\Http\Controllers\Api\Public\CategoryController;
use App\Http\Controllers\Api\Private\HistoryController;
use App\Http\Controllers\Api\Public\PlaylistController as PublicPlaylistController;
use App\Http\Controllers\Api\Public\SearchController as SearchPublicController;
use App\Http\Controllers\Api\Public\UserController as PublicUserController;
use App\Http\Controllers\Api\Public\VideoController as PublicVideoController;
use App\Models\Video;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


/**
 * -------------------- PRIVATE USER --------------------
 */

Route::middleware('auth:sanctum')->group(function () {

    // SUBSCRIBE
    Route::post("subscribe/{user}", [SubscribeController::class, 'subscribe'])
        ->name('subscribe')
        ->can('subscribe', 'user')
        ->middleware(['throttle:subscribe', 'verified'])
        ->missing(fn() => abort(404, 'User not found'));

    // VIDEOS (Upload18 direct upload for authenticated users; server fallback if enabled)
    Route::controller(VideoController::class)
        ->prefix('videos')
        ->name('videos.')
        ->middleware(['verified', 'throttle:upload'])
        ->group(function () {
            Route::get('/upload18/config', 'getUploadConfig')
                ->name('upload18.config')
                ->can('upload', Video::class);
            Route::get('/upload18/status', 'getUploadStatus')
                ->name('upload18.status')
                ->can('upload', Video::class);
            Route::post('/upload18/chunk', 'uploadChunk')
                ->name('upload18.chunk')
                ->can('upload', Video::class);
            Route::post('/upload18/complete', 'completeUpload')
                ->name('upload18.complete')
                ->can('upload', Video::class);
            Route::post('/{video:id}/generate-thumbnails', 'generateThumbnails')
                ->name('generate-thumbnails')
                ->can('update', 'video');
            Route::post('/{video:id}/generate-server-thumbnails', 'generateServerThumbnails')
                ->name('generate-server-thumbnails')
                ->can('update', 'video');
            Route::get('/{video:id}/create-status', 'createStatus')
                ->name('create-status')
                ->can('update', 'video');
            Route::delete('/{video:uuid}', 'delete')->name('delete')->can('delete', 'video');
    });

    Route::middleware('throttle:api')->group(function () {

        // INTERACTIONS
        Route::name('interactions.')
            ->controller(InteractionController::class)
            ->middleware('verified')
            ->group(function () {
                Route::get('/videos/{video:id}/interactions', 'list')
                    ->name('list')
                    ->withTrashed()
                    ->can('interactions', 'video');
                Route::post('/like', 'like')->name('like');
                Route::post('/dislike', 'dislike')->name('dislike');
        });

        // REPORT
        Route::post('/report', [ReportController::class, 'report'])->name('report')->middleware(['verified', 'throttle:reports']);

        // PLAYLIST
        Route::controller(PlaylistController::class)->prefix('playlists')->name('playlists.')->group(function () {
            Route::post('/', 'store')->name('store')->middleware('verified');
            Route::post('/save', 'save')->name('save');
            Route::delete('/{playlist:uuid}', 'delete')->name('delete')->can('delete', 'playlist');
        });

        // NOTIFICATIONS
        Route::controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', 'index')
                ->name('index');
            Route::get('/{notification}/read', 'read')
                ->name('read')
                ->missing(fn() => abort(404, 'Notification not found'))
                ->can('read', 'notification');
            Route::get('/{notification}/unread', 'unread')->name('unread')
                ->missing(fn() => abort(404, 'Notification not found'))
                ->can('unread', 'notification');
            Route::delete('/{notification}/delete', 'delete')
                ->name('remove')
                ->missing(fn() => abort(404, 'Notification not found'))
                ->can('delete', 'notification');
            Route::get('/read-all', 'readAll')->name('read-all');
            Route::delete('/delete-all', 'deleteAll')->name('delete-all');
        });

        // SEARCH
        Route::controller(SearchController::class)->prefix('search')->name('search.')->middleware('throttle:search')->group(function () {
            Route::get('/users', 'users')->name('users');
            Route::get('/videos', 'videos')->name('videos');
        });

        // CURRENT USER (for MOBILE)
        Route::prefix('me')
            ->name('me.')
            ->group(function () {
                Route::controller(UserController::class)->group(function () {
                    Route::get('/', 'user')->name('user');
                    Route::get('/videos', 'videos')->name('videos');
                    Route::get('/playlists', 'playlists')->name('playlists');
                    Route::get('/subscriptions-videos', 'subscriptionsVideos')->name('subscriptions-videos');
                    Route::get('/subscriptions-channels', 'subscriptionsChannels')->name('subscriptions-channels');
                    Route::post('/logout', 'logout')->name('logout');
                });
                Route::controller(HistoryController::class)->group(function () {
                    Route::get('/history', 'index')->name('history');
                    Route::delete('/history/{view}', 'clear')->name('clear')->can('clear', 'view');
                    Route::post('/history/clear-day', 'clearDay')->name('clear-day');
                    Route::post('/history/clear-all', 'clearAll')->name('clear-all');
                });
        });


        // DEVICES
        Route::prefix('devices')
            ->name('devices.')
            ->controller(DeviceController::class)
            ->group(function () {
                Route::post('/{device}/update', 'update')->name('update');
                Route::delete('/{device}', 'delete')->name('delete');
        });

        // THUMBNAILS
        Route::name('thumbnails.')
            ->controller(ThumbnailController::class)
            ->group(function () {
                Route::get('/videos/{video:id}/thumbnails', 'index')
                    ->name('list')
                    ->can('thumbnails', 'video');
            });

        Route::post('/videos/{video:uuid}/progress', [PlaybackProgressController::class, 'update'])
            ->name('videos.progress')
            ->can('show', 'video');
    });
});


/**
 * -------------------- PUBLIC --------------------
*/

Route::middleware('throttle:api')->group(function () {

    // AUTH
    Route::controller(LoginController::class)->group(function () {
        Route::post('/login', 'login')->middleware(['throttle:login']);
    });

    Route::post('/register', [RegisterController::class, 'register'])
        ->middleware(['throttle:register']);

    // SEARCH
    Route::get("search", [SearchPublicController::class, 'search'])
        ->name('search')
        ->middleware(['throttle:search', 'cache.headers:public;max_age=60;etag']);

    // VIDEOS
    Route::prefix('videos')->name('videos.')->controller(PublicVideoController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('cache.headers:public;max_age=30;etag');
        Route::get('/trend', 'trend')->name('trend')->middleware('cache.headers:public;max_age=30;etag');
        Route::get('/{video:uuid}', 'show')->name('show')->can('show', 'video');
        Route::post('/{video:uuid}/view', 'view')->name('view')->can('show', 'video')->middleware('throttle:video-view');
        Route::get('/{video:uuid}/player', 'player')->name('player')->can('show', 'video');
    });

    // USERS
    Route::prefix('users')
        ->name('users.')
        ->controller(PublicUserController::class)
        ->middleware('can:show,user')
        ->group(function () {
            Route::get('/{user:id}', 'show')->name('show');
            Route::get('/{user:id}/videos', 'videos')->name('videos');
            Route::get('/{user:id}/playlists', 'playlists')->name('playlists');
    });

    // PLAYLISTS
    Route::prefix('playlists')
        ->name('playlists.')
        ->controller(PublicPlaylistController::class)
        ->middleware('can:show,playlist')
        ->group(function () {
            Route::get('/{playlist:uuid}', 'show')->name('show');
            Route::get('/{playlist:uuid}/videos', 'videos')->name('videos');
    });

    // CATEGORIES
    Route::prefix('categories')->name('categories.')->controller(CategoryController::class)->group(function () {
        Route::get('/{category}/videos', 'videos')->name('videos')->middleware('cache.headers:public;max_age=30;etag');
    });

    // 365-DAY DAILY DESIGN API
    Route::prefix('daily-design')->name('daily-design.')->group(function () {
        Route::get('/today', function () {
            $today = \App\Support\DailyDesignCatalog::today();
            $tomorrow = \App\Support\DailyDesignCatalog::tomorrow($today);

            return response()->json([
                'status' => 'success',
                'today' => $today,
                'tomorrow_teaser' => [
                    'day' => $tomorrow['day'],
                    'name' => $tomorrow['name'],
                    'season_mood' => $tomorrow['season_mood'],
                    'date_label' => $tomorrow['date_label'],
                    'primary' => $tomorrow['primary'],
                    'secondary' => $tomorrow['secondary'],
                    'accent' => $tomorrow['accent'],
                    'home_layout_mode' => $tomorrow['home_layout_mode'],
                ],
                'timestamp' => now()->toIso8601String(),
            ]);
        })->name('today');

        Route::get('/catalog', function () {
            return response()->json([
                'status' => 'success',
                'total_days' => \App\Support\DailyDesignCatalog::daysInYear(),
                'months' => \App\Support\DailyDesignCatalog::months(),
                'designs' => \App\Support\DailyDesignCatalog::all(),
            ]);
        })->name('catalog');

        Route::get('/{day}', function ($day) {
            $dayNum = (int) $day;
            if ($dayNum < 1 || $dayNum > 366) {
                return response()->json(['status' => 'error', 'message' => 'Day must be between 1 and 366'], 422);
            }

            return response()->json([
                'status' => 'success',
                'design' => \App\Support\DailyDesignCatalog::forDay($dayNum),
            ]);
        })->name('show')->where('day', '[0-9]+');
    });
});


// COMMENTS
Route::scopeBindings()
    ->prefix('videos/{video:uuid}/comments')->name('comments.')
    ->controller(CommentController::class)
    ->middleware('throttle:api')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{comment}/replies', 'replies')->name('replies');
        Route::middleware(['auth:sanctum', 'throttle:comments'])->group(function () {
            Route::post('/', 'store')->name('store')->middleware('verified');
            Route::put('/{comment}', 'update')->name('update');
            Route::delete('/{comment}', 'delete')->name('delete');
            Route::post('/{comment}/pin', 'pin')->name('pin');
            Route::post('/{comment}/unpin', 'unpin')->name('unpin');
        });
});

/**
 * -------------------- ADMIN --------------------
 */

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {

        // SEARCH
        Route::controller(SearchAdminController::class)
            ->name('search.')
            ->group(function () {
                Route::get('/users', 'users')->name('users');
                Route::get('/videos', 'videos')->name('videos');
            });
});
