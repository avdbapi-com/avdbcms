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

use App\Http\Middleware\VerifyStripeWebhookSignature;
use App\Http\Middleware\ReadOnlySession;

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
// Signed by Stripe; refused while no signing secret is set (an empty key is a signature anybody can make).
Route::post('stripe-webhook', [StripeWebhookController::class, 'index'])
    ->middleware(VerifyStripeWebhookSignature::class)
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
// The crawler files (robots.txt, the sitemaps and their stylesheet, the IndexNow key) are the same for everybody and the
// sitemaps are marked public for caches: like the site's pictures they read the visitor's session (an administrator
// passes the maintenance page) but never start, write or send one (App\Http\Middleware\ReadOnlySession), so no answer
// sets a session or XSRF cookie and a CDN may keep them (R1-087, R1B-014).
Route::controller(SeoController::class)
    ->withoutMiddleware(ReadOnlySession::REPLACES)
    ->middleware(ReadOnlySession::class)
    ->group(function () {
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
    // Route::redirect() answers every method; GET and HEAD only here, the group has no CSRF check (a sitemap is read).
    Route::get('/video-sitemap.xml', '\Illuminate\Routing\RedirectController')
        ->defaults('destination', '/sitemap-videos.xml')
        ->defaults('status', 301)
        ->name('seo.sitemap.videos.legacy');
    Route::get('/{key}.txt', 'indexNowKey')->where('key', '[A-Za-z0-9_-]{8,128}')->name('seo.indexnow.key');
});

// VIDEOS
Route::get('/random', [VideoController::class, 'random'])->name('random');

// The site's pictures read the visitor's session but never write it and never send a session cookie
// (App\Http\Middleware\ReadOnlySession; watch-26, watch-28): a page of 20 avatars no longer stores the session 20
// times with a picture as "the page you came from", and the answers can be kept by the browser and a CDN.

Route::controller(VideoController::class)->name('video.')->prefix('video')->group(function () {
    Route::get('random', 'random')->name('random');
    // Resolved and authorized in VideoController::show(): under a custom video_url_pattern a canonical URL can
    // also end in '-' + 10 digits that are not a video id (e.g. {id}-{slug} with a numeric slug tail).
    Route::get('{slug}-{uuid}', 'show')
        ->name('show')
        ->where('slug', '[a-z0-9A-Z\-]+')
        ->where('uuid', \App\Models\Video::UUID_PATTERN);
    // The video's own file, as the page's <video> asks for it (a request at load and one per seek or buffer): it reads
    // the session (a private video is its owner's) but never writes it, like the pictures. Its copy of the session,
    // stored when it ended, replaced what the sign-in page had just stored meanwhile: the form's security question
    // "expired" (round 3 review SB-X1); and the file became the session's previous page (back() without a Referer).
    // It still keeps the session alive (KEEP_ALIVE: the stored session's time and the same cookie, nothing written), as
    // each of its requests did before: during a long film it may be the page's only request (round 3 check SB-V1-01).
    Route::get('file/{video:file}', 'file')
        ->name('file')
        ->withoutMiddleware(ReadOnlySession::REPLACES)
        ->middleware(ReadOnlySession::class.':'.ReadOnlySession::KEEP_ALIVE)
        ->can('file', 'video')
        ->withTrashed();
    Route::get('{video:uuid}/thumbnail', 'thumbnail')
        ->name('thumbnail')
        ->withoutMiddleware(ReadOnlySession::REPLACES)
        ->middleware(ReadOnlySession::class)
        ->can('thumbnail', 'video')
        ->withTrashed();
    Route::get('{video:uuid}/thumbnails/{thumbnail}', 'thumbnails')
        ->scopeBindings()
        ->name('thumbnails')
        ->withoutMiddleware(ReadOnlySession::REPLACES)
        ->middleware(ReadOnlySession::class)
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
            ->withoutMiddleware(ReadOnlySession::REPLACES)
            ->middleware(ReadOnlySession::class)
            ->can('avatar', 'user');
        Route::get('{user}/banner', 'banner')
            ->name('banner')
            ->withoutMiddleware(ReadOnlySession::REPLACES)
            ->middleware(ReadOnlySession::class)
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

// LIBRARY (member redesign, member design 6.11 and 9.1 step 4): the member's library page.
Route::get('/library', [\App\Http\Controllers\LibraryController::class, 'index'])
    ->middleware('auth')
    ->name('pages.library');

require __DIR__.'/auth.php';
require __DIR__.'/user.php';
require __DIR__.'/admin.php';

// Public-disk fallback: streams storage/app/public/* through PHP when the
// public/storage symlink is missing (symlink() is disabled on many panels and
// shared hosts). With the symlink present the web server serves these files
// directly and this route is never reached. Must stay above the SEO catch-all.
// Public files are the same for everybody: like the picture routes, it reads no session and sends no cookie, so its
// "public, max-age=86400" answer never carries a visitor's session (R1-087) and stores no session per file.
Route::get('/storage/{path}', [StorageFallbackController::class, 'show'])
    ->where('path', '.*')
    ->withoutMiddleware(ReadOnlySession::REPLACES)
    ->middleware(ReadOnlySession::class)
    ->name('storage.fallback');

Route::get('/{seoVideoPath}', [VideoController::class, 'seoShow'])
    ->where('seoVideoPath', '.*')
    ->name('video.seo-show');
