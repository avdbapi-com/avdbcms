<?php

namespace App\Http;

use App\Http\Middleware\EnsureAdminOperationalToolsEnabled;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\IsPremium;
use App\Http\Middleware\BustAdminCache;
use App\Http\Middleware\RequirePassword;
use App\Http\Middleware\RateLimit;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        // A site that is not installed yet: "being set up" (503, Retry-After) outside the installer (round 3 R3-038).
        \App\Http\Middleware\AnswerWhileInstalling::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EdgeCacheVideoHtml::class,
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            // A GET the page sends by itself (X-Background-Request: 1) reads the session but never changes it, so it
            // cannot use up a one-time message or write back an older copy. Right before StartSession.
            \App\Http\Middleware\BackgroundRequest::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // The language the visitor chose (the session's), before the page token check, the limits and the sign-in
            // bounce can answer: they answered a plain address in the site's default language (R1-050).
            \App\Http\Middleware\UseChosenLanguage::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            // A demo site's visitor choices (the look this browser tries, ads turned on or off), only while their
            // settings are on; before the draft preview, so an admin's draft link shows the draft.
            \App\Http\Middleware\ApplyVisitorChoices::class,
            // Draft preview of the Appearance pages (needs the session and the signed-in admin).
            \App\Http\Middleware\ApplyAppearanceDraft::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IsBanned::class,
            \App\Http\Middleware\Security::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\StripEmptyParams::class,
            \App\Http\Middleware\CheckMaintenanceMode::class,
            // A Link header with the page's first Vite preloads (main CSS, the entry
            // and its vendor chunk), which also lets Cloudflare send 103 Early Hints. Capped at 4 entries
            // (AddLinkHeadersForPreloadedAssets::using(4)), about 300 bytes, far below the 4-8 KB
            // fastcgi/proxy header buffers of the HestiaCP and Virtualmin nginx defaults.
            // Page views only: the page's own background fetches get no Link header (R1-163, AddPreloadLinksForPages).
            \App\Http\Middleware\AddPreloadLinksForPages::class.':4',
            // View-only demo accounts (users.is_view_only): refuses their changes, closes the private pages, rolls
            // back what a page writes and masks secrets in what they see. Everybody else passes untouched.
            \App\Http\Middleware\ViewOnlyDemo::class,
        ],

        'api' => [
            // The site's own scripts get a session from Sanctum's stateful middleware: their background GETs leave it untouched.
            \App\Http\Middleware\BackgroundRequest::class,
            // The caller's language (?locale=, X-Locale, the page's /fr, Accept-Language) before anything that can answer:
            // the stateful check of the page's token (its 419), Blocked addresses, and the limits of the routes
            // ("throttle:..." and "auth:sanctum" are sorted ahead of SubstituteBindings, never ahead of this one), so
            // those answers are in the member's language too (judge R1-050: they were in the site's default language).
            \App\Http\Middleware\Language::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            // Blocked addresses (Members > Blocked addresses) get no answers from the API either; signing in stays open.
            \App\Http\Middleware\RefuseBlockedAddresses::class,
            //'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\OptionalAuthSanctum::class,
            // The owner's maintenance mode closes the API as it closes the pages (503 JSON with Retry-After); after the
            // optional sign-in, so administrators (and an app signed in as one) still pass. Signing in, the admin's own
            // API and an upload that is already running stay open (CheckMaintenanceMode).
            \App\Http\Middleware\CheckMaintenanceMode::class,
            // A banned account is refused here too (its token or the session the site's scripts share), not only on pages.
            \App\Http\Middleware\IsBanned::class,
            // After the optional sign-in, so a view-only account that calls with its token is guarded too.
            \App\Http\Middleware\ViewOnlyDemo::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        //'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        // The route limits; a view-only demo account's refused request is left to its guard, which answers it with the
        // "This is a demo" window instead of "Too many tries" (App\Http\Middleware\ThrottleExceptViewOnlyRefusals).
        'throttle' => \App\Http\Middleware\ThrottleExceptViewOnlyRefusals::class,
        'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        'admin' => IsAdmin::class,
        'admin.ops' => EnsureAdminOperationalToolsEnabled::class,
        'admin.cache' => BustAdminCache::class,
        'premium' => IsPremium::class,
        'password.confirm' => RequirePassword::class,
        'rate' => RateLimit::class
    ];
}
