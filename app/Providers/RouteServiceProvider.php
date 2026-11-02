<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Route as RouteAlias;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const string HOME = '/profile';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot() : void
    {
        $this->configureRateLimiting();

        // The sign-up confirmation and password-reset mails in the member mail layout and words (member design
        // 6.21): Laravel's notifications keep their classes, their mail is made by MemberMail.
        \App\Notifications\Support\MemberMail::registerAuthMails();

        // Guest watch pages are edge-cached without session cookies (EdgeCacheVideoHtml), so the CSRF token in
        // their HTML can never match and every guest view-count beacon failed with 419. The beacon only bumps a
        // counter (cooldown per user / IP + user agent in ViewService), and SameSite=lax session cookies keep
        // cross-site requests anonymous. Static list: also covers Sanctum's ValidateCsrfToken on stateful API calls.
        ValidateCsrfToken::except(['api/videos/*/view']);

        // The routes of a video that answer only its channel find another member's video nowhere, like an id that does
        // not exist: same status, body and headers (round 2a R2A-062, App\Support\OwnerOnlyVideoRoutes). The same for
        // the other routes that name something by its number or link (round 3 R3-081, R3-082, R2R-07,
        // App\Support\HiddenRouteModels).
        Route::substituteImplicitBindingsUsing(static function ($container, $route, \Closure $default) {
            \App\Support\HiddenRouteModels::lookUpAsker($route);
            $bound = $default();
            \App\Support\OwnerOnlyVideoRoutes::check($route);
            \App\Support\HiddenRouteModels::check($route);

            return $bound;
        });

        $this->routes(function () {
            // The site's checks of its own web server (App\Support\ServerChecks: clean addresses, nginx's internal
            // location for the videos). No "web" middleware: no session, no cookie; registered first, before any
            // catch-all page route.
            Route::get(\App\Support\ServerChecks::PATH.'/{check}/{name}', \App\Http\Controllers\ServerCheckController::class)
                ->where(['check' => 'clean-urls|stream', 'name' => '[a-f0-9]{32}'])
                ->name('server-check');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });

        Route::bind('reportable', function (string $value, RouteAlias $route) {

            $type = $route->parameter('type');

            // Strict allowlist of reportable model types to prevent arbitrary model access
            $allowedTypes = ['video', 'comment', 'user', 'playlist'];
            if (!in_array(strtolower($type), $allowedTypes)) {
                abort(404, "Invalid reportable type.");
            }

            $modelClass = 'App\\Models\\' . Str::studly($type);

            if (!class_exists($modelClass)) {
                abort(404, "Model class $modelClass does not exist.");
            }

            return $modelClass::findOrFail($value);
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting() : void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute($this->configuredRateLimit('api_rate_limit', 60, 10000))
                ->by($this->requesterRateLimitKey($request))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        RateLimiter::for('subscribe', function (Request $request) {
            return Limit::perMinute($this->configuredRateLimit('subscribe_rate_limit', 5, 1000))
                ->by($this->requesterRateLimitKey($request).'|'.$this->routeParameterRateLimitKey($request, 'user'))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        RateLimiter::for('upload', function (Request $request) {
            $requester = $this->requesterRateLimitKey($request);
            $respond = fn (Request $request, array $headers) => $this->response($request, $headers);

            // One bucket per member (security plan S28). Only the pieces of a file stored on this server keep one bucket
            // per file, as before (a file sends many pieces under one identifier); a made-up identifier on any other
            // route no longer opens a fresh bucket.
            $key = $request->routeIs('videos.upload18.chunk')
                ? $requester.'|'.hash('sha256', (string) $request->input('resumableIdentifier'))
                : $requester;

            $limits = [Limit::perMinute($this->configuredRateLimit('upload_rate_limit', 10, 1000))->by($key)->response($respond)];

            if ($request->routeIs('videos.generate-server-thumbnails')) {
                // ffmpeg on this server: the create page asks once per video.
                $limits[] = Limit::perMinute(2)->by($requester.'|server-thumbnails')->response($respond);
            }

            return $limits;
        });

        RateLimiter::for('comments', function (Request $request) {
            return Limit::perMinute($this->configuredRateLimit('comment_rate_limit', 15, 1000))
                ->by($this->requesterRateLimitKey($request))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        RateLimiter::for('reports', function (Request $request) {
            return Limit::perMinute($this->configuredRateLimit('report_rate_limit', 5, 1000))
                ->by($this->requesterRateLimitKey($request))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute($this->configuredRateLimit('search_rate_limit', 30, 1000))
                ->by($this->requesterRateLimitKey($request))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        RateLimiter::for('video-view', function (Request $request) {
            return Limit::perMinute($this->configuredRateLimit('video_view_rate_limit', 120, 10000))
                ->by($this->requesterRateLimitKey($request))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        // Member actions that are heavy for the site or reach another service, per member (round 1b judge R1B-026). A
        // person never needs more; a script repeating them in a loop is stopped with the usual "Too many tries" answer.
        // The bulk visibility of My videos (up to 500 videos a request, "Make public" announced to IndexNow): 10 a minute.
        RateLimiter::for('member-bulk-visibility', function (Request $request) {
            return Limit::perMinute(10)
                ->by($this->requesterRateLimitKey($request))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        // Ending the member's other sessions ("Sign out of the other devices", or a password change that asks for it)
        // reads the whole session folder: 5 in 10 minutes. Only the requests that really ended them count, so a wrong
        // password keeps the password check's own answers (R1-085) and a password change without it is never held up.
        RateLimiter::for('member-sessions', function (Request $request) {
            if ($request->routeIs('user.update.password') && ! $request->boolean('sign_out_others')) {
                return Limit::none();
            }

            return Limit::perMinutes(10, 5)
                ->by($this->requesterRateLimitKey($request))
                ->after(fn (): bool => $request->attributes->get(\App\Http\Controllers\User\ProfileController::ENDED_OTHER_SESSIONS) === true)
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
        });

        // Limits that were written inline ("throttle:10,1,phone-verify", "throttle:3,10", "throttle:10,1"): their answer
        // was Laravel's bare "Too Many Attempts." in English (the bare 429 page for a form), and the ones without a
        // prefix shared one counter per member across routes (round 2a R2A-030). Same numbers, own counters, the site's
        // answer in the member's language.
        // The phone code check (Account settings, R1-164): about 10 checks a minute per member (each code allows 5 wrong
        // tries in all: PhoneVerification); the answer is the code field's error, as for a refused code.
        RateLimiter::for('phone-verify', function (Request $request) {
            return Limit::perMinute(10)
                ->by($this->requesterRateLimitKey($request))
                ->response(fn (Request $request, array $headers) => $this->phoneCodeResponse($request, $headers));
        });

        // "Download my data": 3 in 10 minutes per member (the export reads all of the member's data). A redirect is not
        // counted: the menu's link has no language in it, so for a member who reads the site in another language the
        // Language middleware's hop to /<language>/profile/export used a second try of every download.
        RateLimiter::for('member-export', function (Request $request) {
            return Limit::perMinutes(10, 3)
                ->by($this->requesterRateLimitKey($request))
                ->after(fn (\Symfony\Component\HttpFoundation\Response $response): bool => ! $response->isRedirection())
                ->response(fn (Request $request, array $headers) => $this->exportResponse($request, $headers));
        });

        // The administrator's "Show the secret link" (Settings > Maintenance): 10 a minute; the password check of that
        // dialog also stops after 5 wrong passwords (SettingsPageController::checkPassword()).
        RateLimiter::for('admin-secret-reveal', function (Request $request) {
            return Limit::perMinute(10)
                ->by($this->requesterRateLimitKey($request))
                ->response(fn (Request $request, array $headers) => $this->response($request, $headers));
        });

        // The forms a person fills in (sign-in, sign-up, "Forgot password", "Choose a new password", contact): one bucket
        // per address. Their answer goes back to the form with the wait in seconds and what was typed (never a password),
        // not a bare 429 page (member design 6.12, auth-16). "password-email" was the generic throttle:5,1 before 1.2.3;
        // "password-reset" (the new-password form) had no limit.
        foreach (['login' => 5, 'contact' => 3, 'register' => 3, 'password-email' => 5, 'password-reset' => 5] as $route => $limit) {
            RateLimiter::for($route, function (Request $request) use ($route, $limit) {
                $configKey = $route === 'login' ? 'login_rate_limit' : null;
                $maxAttempts = $configKey
                    ? $this->configuredRateLimit($configKey, $limit, 1000)
                    : $limit;

                return Limit::perMinute($maxAttempts)->by($request->ip())
                    ->response(fn(Request $request, array $headers) => $this->formResponse($request, $headers));
            });
        }
    }

    /**
     * The other limits (the API, subscribing, uploads, comments, reports, search, view counts): "Too many tries. Wait 42
     * seconds, then try again." with the Retry-After header, in the member's language (it was a bare "Too many attempts"
     * with no wait).
     */
    private function response (Request $request, array $headers): JsonResponse|RedirectResponse {
        $this->usePageLanguage($request);
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = self::waitMessage($seconds);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'retry_after' => $seconds,
            ], 429, $headers);
        }

        return back()->with('error', $message);
    }

    /**
     * A throttled form (sign-in, sign-up, "Forgot password", contact): the same page again with "Too many tries. Wait
     * 42 seconds, then try again." and the fields a person typed that are not secret (passwords, captcha answers and
     * tokens are never flashed); a script or app gets the same sentence as JSON with the Retry-After header.
     */
    private function formResponse(Request $request, array $headers): JsonResponse|RedirectResponse
    {
        $this->usePageLanguage($request);
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = self::waitMessage($seconds);

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
        }

        return back()
            ->with('error', $message)
            ->with('auth_throttled', $seconds)
            ->withInput($request->only('username', 'email', 'remember', 'cgu', 'name', 'message'));
    }

    /**
     * The phone code check's limit (round 2a R2A-030): the same answer as a refused code (PhoneVerification, R1-115):
     * "Too many tries. Wait 42 seconds, then try again." under the code field, which is marked invalid and gets the
     * focus; a script or an app gets the sentence as JSON with the Retry-After header.
     */
    private function phoneCodeResponse(Request $request, array $headers): JsonResponse|RedirectResponse
    {
        $this->usePageLanguage($request);
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = self::waitMessage($seconds);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
        }

        return back()->withErrors(['code' => $message])->with('phone_verification', ['kind' => 'error', 'retry_after' => $seconds]);
    }

    /**
     * "Download my data" over its limit: the same sentence, back on the page the member came from, but never on the
     * download itself. Laravel keeps every page opened as the page to go back to, a download too, so a browser that
     * sends no Referer (a privacy extension, a strict Referrer-Policy) went back to the download, was refused again, and
     * so on until the browser gave up. Then Account settings, in the section that has the button: "Delete account"
     * while members may delete their account, else "Sign-in and security" (users/profile/sections/delete and security).
     */
    private function exportResponse(Request $request, array $headers): JsonResponse|RedirectResponse
    {
        $this->usePageLanguage($request);
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = self::waitMessage($seconds);

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
        }

        $previous = url()->previous();
        $back = $this->isTheDownload($request, $previous)
            ? self::accountSettingsAddress(config('site.allow_account_deletion', true) ? 'delete' : 'security')
            : $previous;

        return redirect()->to($back)->with('error', $message);
    }

    /**
     * Whether the page back() goes to is "Download my data" itself (round 2 review R2CX-REV-01). Without a Referer it is
     * the session's page, which Laravel stores with its route's name. Every address is also read as a page of this site
     * in each form the site writes: with or without the host, the site's folder, /index.php, the language. A site in a
     * sub folder (APP_URL https://example.com/site) kept the folder in the address: the download was not recognised and
     * the browser was sent back to it again and again.
     */
    private function isTheDownload(Request $request, string $previous): bool
    {
        $session = $request->hasSession() ? $request->session() : null;

        if (! $request->headers->get('referer') && $session !== null && method_exists($session, 'previousRoute')
            && $session->previousRoute() === 'user.export') {
            return true;
        }

        $download = Route::getRoutes()->getByName('user.export');

        return $download !== null && self::sitePage($request, $previous) === '/'.trim($download->uri(), '/');
    }

    /** The page of this site an address names, without the site's folder, /index.php and the language; null: another site. */
    private static function sitePage(Request $request, string $address): ?string
    {
        $host = parse_url($address, PHP_URL_HOST);
        $ownHosts = array_filter([strtolower($request->getHost()), strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))]);

        if (is_string($host) && $host !== '' && ! in_array(strtolower($host), $ownHosts, true)) {
            return null;
        }

        $path = locale_path_parts($address)['path'];

        // A whole address: locale_path_parts() took the site's folder off already (R2R-05); only a path still has it.
        if (is_string($host) && $host !== '') {
            return $path;
        }

        foreach ([$request->getBasePath(), (string) parse_url((string) config('app.url'), PHP_URL_PATH)] as $folder) {
            $folder = '/'.trim($folder, '/');

            if ($folder !== '/' && ($path === $folder || str_starts_with($path, $folder.'/'))) {
                return locale_path_parts(substr($path, strlen($folder)) ?: '/')['path'];
            }
        }

        return $path;
    }

    /**
     * Account settings > $section in the page's language, completed by the URL generator with the site's own start (its
     * folder, /index.php where the site needs it): an address of this site in every layout. A link from locale_url()
     * already holds the folder, which a redirect would add a second time.
     */
    private static function accountSettingsAddress(string $section): string
    {
        $locale = app()->getLocale();
        $language = $locale !== site_default_locale() ? '/'.$locale : '';
        $edit = Route::getRoutes()->getByName('user.edit');

        return url($language.'/'.trim($edit?->uri() ?? 'profile/edit', '/')).'?section='.rawurlencode($section);
    }

    /**
     * A limit of the site's pages answers before App\Http\Middleware\Language has run (Laravel sorts its limiter in
     * front of the web group's later middleware), so its sentence came in the site's default language for a member who
     * reads the site in another one and sends a form to an address without the language (Account settings, My videos;
     * round 2a R2A-030). The answer takes the language the page would have had, as that middleware decides it: the
     * address's language (public/index.php has applied it already), else the one this browser chose (the session). The
     * API resolves its language in its own group (App\Http\Kernel, round 2a R1-050).
     */
    private function usePageLanguage(Request $request): void
    {
        if ($request->is('api', 'api/*') || ! empty($_SERVER['DETECTED_LOCALE'])) {
            return;
        }

        try {
            $locale = $request->hasSession() ? $request->session()->get('app_locale') : null;
        } catch (\Throwable) {
            return;
        }

        if (is_string($locale) && isset(config('languages', [])[$locale])) {
            app()->setLocale($locale);
            \Carbon\Carbon::setLocale($locale);
        }
    }

    /**
     * "Too many tries. Wait 42 seconds, then try again." up to a minute, then in whole minutes, rounded up ("Wait 10
     * minutes", not "Wait 594 seconds": the sign-up limit's window is ten minutes). One / many in every language.
     */
    public static function waitMessage(int $seconds): string
    {
        $seconds = max(1, $seconds);

        if ($seconds > 60) {
            $minutes = (int) ceil($seconds / 60);

            return trans_choice('{1} Too many tries. Wait :count minute, then try again.|[2,*] Too many tries. Wait :count minutes, then try again.', $minutes, ['count' => $minutes]);
        }

        return trans_choice('{1} Too many tries. Wait :seconds second, then try again.|[2,*] Too many tries. Wait :seconds seconds, then try again.', $seconds, ['seconds' => $seconds]);
    }

    private function configuredRateLimit(string $key, int $default, int $max): int
    {
        return max(1, min($max, (int) config("site.{$key}", $default)));
    }

    private function requesterRateLimitKey(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?: $request->ip());
    }

    private function routeParameterRateLimitKey(Request $request, string $parameter): string
    {
        $value = $request->route($parameter);

        if (is_object($value) && method_exists($value, 'getKey')) {
            return (string) $value->getKey();
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
