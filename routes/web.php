<?php

use App\Http\Controllers\VideoController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\User\CustomPlaylistController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StorageFallbackController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\PremiumController;
use App\Http\Controllers\InstallController;

use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\LangController;

use App\Enums\CustomPlaylistType;

use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Support\Installer;

Route::controller(InstallController::class)->prefix('install')->name('install.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{step}', 'store')->name('store');
});

Route::controller(InstallController::class)->prefix('index.php/install')->group(function () {
    Route::get('/', 'index');
    Route::post('/{step}', 'store');
});

// PAGES
Route::name('pages.')->group(function () {
    Route::get('/', function (Request $request, Installer $installer) {
        if (! $installer->isInstalled()) {
            $installPath = !empty($_SERVER['AVDBCLIP_ROOT_FALLBACK']) ? '/index.php/install' : '/install';
            return redirect()->to($installPath);
        }

        return app()->call([app(PageController::class), 'home'], ['request' => $request]);
    })->name('home');

    Route::controller(PageController::class)->group(function () {
        Route::get('/trend', 'trend')->name('trend');
        Route::get('/trending', fn () => redirect()->route('pages.trend', [], 301));
        Route::get('/categories', 'categories')->name('categories');
        Route::get('/categories/{slug}', 'category')->name('category');
        Route::get('/terms', 'terms')->name('terms');
        Route::middleware('auth')->group(function () {
            Route::get('/history', 'history')->name('history');
        });
        Route::get('/premium', 'premium')->name('premium');
    });
});

// CUSTOM PLAYLISTS
Route::middleware('auth')->name('custom-playlist.')->controller(CustomPlaylistController::class)->group(function () {
    Route::get('/playlist/{type}', 'show')
        ->whereIn('type', CustomPlaylistType::cases())
        ->name('show');
    Route::post('/watch-later/clear', 'clearWatchedVideos')->name('clear-watched-videos');
    Route::post('/user/playlists/watch-later/{videoId}', 'toggleWatchLater')->name('watch-later.toggle');
    Route::get('/user/playlists/watch-later-ids', 'getWatchLaterIds')->name('watch-later.ids');
});

// PREMIUM
Route::post('stripe-webhook', [StripeWebhookController::class, 'index'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('stripe_webhook');

Route::post('paypal-webhook', [PayPalWebhookController::class, 'index'])
    ->name('paypal_webhook');

Route::name('premium.')
    ->middleware(['auth', 'can:premiumSubscribe,App\Models\User', 'verified'])
    ->controller(PremiumController::class)->group(function () {
        Route::get('/subscribe/{plan}', 'subscribe')
            ->name('subscribe');
        Route::get('/subscribe/{plan}/paypal', 'subscribePaypal')
            ->name('subscribe.paypal');
});

Route::get('/paypal/subscription/return', [PremiumController::class, 'paypalReturn'])
    ->middleware(['auth', 'verified'])
    ->name('premium.paypal.return');

// LANG
Route::match(['GET', 'POST'], 'update-locale', [LangController::class, 'update'])->name('lang.update');

// SEO
Route::controller(SeoController::class)->group(function () {
    Route::get('/robots.txt', 'robots')->name('seo.robots');
    Route::get('/sitemap.xml', 'sitemap')->name('seo.sitemap');
    Route::get('/sitemap.xsl', 'sitemapStylesheet')->name('seo.sitemap.stylesheet');
    Route::get('/sitemap-pages-{locale}.xml', 'pagesSitemap')
        ->where('locale', '[a-z]{2}(?:-[A-Z]{2})?')
        ->name('seo.sitemap.pages.locale');
    Route::get('/sitemap-pages.xml', 'pagesSitemap')->name('seo.sitemap.pages');
    Route::get('/sitemap-videos-{locale}-{page}.xml', 'videoSitemap')
        ->where(['locale' => '[a-z]{2}(?:-[A-Z]{2})?', 'page' => '[0-9]+'])
        ->name('seo.sitemap.videos.locale.page');
    Route::get('/sitemap-profiles-{locale}-{page}.xml', 'profilesSitemap')
        ->where(['locale' => '[a-z]{2}(?:-[A-Z]{2})?', 'page' => '[0-9]+'])
        ->name('seo.sitemap.profiles.locale.page');
    Route::get('/sitemap-videos-{page}.xml', 'videoSitemap')
        ->whereNumber('page')
        ->name('seo.sitemap.videos.page');
    Route::get('/sitemap-videos.xml', 'videoSitemap')->name('seo.sitemap.videos');
    Route::redirect('/video-sitemap.xml', '/sitemap-videos.xml', 301)->name('seo.sitemap.videos.legacy');
    Route::get('/{key}.txt', 'indexNowKey')->where('key', '[A-Za-z0-9_-]{8,128}')->name('seo.indexnow.key');
});

// VIDEOS
Route::get('/random', [VideoController::class, 'random'])->name('random');

Route::controller(VideoController::class)->name('video.')->prefix('video')->group(function () {
    Route::get('random', 'random')->name('random');
    // Resolved and authorized in VideoController::show(): under a custom video_url_pattern a canonical URL can
    // also end in '-' + 10 digits that are not a video id (e.g. {id}-{slug} with a numeric slug tail).
    Route::get('{slug}-{uuid}', 'show')
        ->name('show')
        ->where('slug', '[a-z0-9A-Z\-]+')
        ->where('uuid', \App\Models\Video::UUID_PATTERN);
    Route::get('file/{video:file}', 'file')
        ->name('file')
        ->can('file', 'video')
        ->withTrashed();
    Route::get('{video:uuid}/thumbnail', 'thumbnail')
        ->name('thumbnail')
        ->can('thumbnail', 'video')
        ->withTrashed();
    Route::get('{video:uuid}/thumbnails/{thumbnail}', 'thumbnails')
        ->scopeBindings()
        ->name('thumbnails')
        ->can('thumbnails', 'video');
    Route::get('{video:uuid}/embed', 'embed')
        ->name('embed')
        ->missing(fn (Request $request) => response()->view('videos.embed.missing'));
});

// SHORTS
/*
Route::controller(\App\Http\Controllers\ShortController::class)->name('short.')->group(function () {
    Route::get('/shorts', 'index')
        ->name('index');
    Route::get('/shorts/{video:uuid}', 'show')
        ->name('show')
        ->can('show', 'video');
});
*/


// PLAYLISTS
Route::controller(PlaylistController::class)->name('playlist.')->group(function () {
    Route::get('/playlist/{playlist:uuid}', 'show')
        ->name('show')
        ->can('show', 'playlist');
    Route::get('/playlists/manage', 'manage')->name('manage')->middleware('auth');
});

// USERS
Route::controller(UserController::class)->name('user.')->group(function () {
    Route::get('/@{user}', 'show')
        ->name('show')
        ->can('show', 'user');
    Route::prefix('users')->group(function () {
        Route::get('{user}/avatar', 'avatar')
            ->name('avatar')
            ->can('avatar', 'user');
        Route::get('{user}/banner', 'banner')
            ->name('banner')
            ->can('banner', 'user');
    });
});

// SEARCH
Route::controller(SearchController::class)->name('search.')->group(function () {
    Route::get('/search', 'index')->name('index');
});

// SUBSCRIPTION
Route::controller(SubscriptionController::class)->name('subscription.')->group(function () {
    Route::get('/subscriptions', 'index')->name('index');
    Route::get('/subscriptions/manage', 'manage')->name('manage')->middleware('auth');
    Route::get('/discover', 'discover')->name('discover');
});

// CONTACT
Route::controller(ContactController::class)->name('contact.')->group(function () {
    Route::get('/contact', 'show')->name('show');
    Route::post('/contact', 'contact')->name('contact')->middleware('throttle:contact');
});

require __DIR__.'/auth.php';
require __DIR__.'/user.php';
require __DIR__.'/admin.php';

// Public-disk fallback: streams storage/app/public/* through PHP when the
// public/storage symlink is missing (symlink() is disabled on many panels and
// shared hosts). With the symlink present the web server serves these files
// directly and this route is never reached. Must stay above the SEO catch-all.
Route::get('/storage/{path}', [StorageFallbackController::class, 'show'])
    ->where('path', '.*')
    ->name('storage.fallback');

Route::get('/{seoVideoPath}', [VideoController::class, 'seoShow'])
    ->where('seoVideoPath', '.*')
    ->name('video.seo-show');
