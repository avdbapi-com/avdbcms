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
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            // Draft preview of the Appearance pages (needs the session and the signed-in admin).
            \App\Http\Middleware\ApplyAppearanceDraft::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IsBanned::class,
            \App\Http\Middleware\Security::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\StripEmptyParams::class,
            \App\Http\Middleware\CheckMaintenanceMode::class,
            // Perf plan step 22.6: a Link header with the page's first Vite preloads (main CSS, the entry
            // and its vendor chunk), which also lets Cloudflare send 103 Early Hints. Capped at 4 entries
            // (AddLinkHeadersForPreloadedAssets::using(4)), about 300 bytes, far below the 4-8 KB
            // fastcgi/proxy header buffers of the HestiaCP and Virtualmin nginx defaults.
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class.':4',
        ],

        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            //'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\OptionalAuthSanctum::class,
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
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'admin' => IsAdmin::class,
        'admin.ops' => EnsureAdminOperationalToolsEnabled::class,
        'admin.cache' => BustAdminCache::class,
        'premium' => IsPremium::class,
        'password.confirm' => RequirePassword::class,
        'rate' => RateLimit::class
    ];
}
