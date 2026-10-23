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

        // Guest watch pages are edge-cached without session cookies (EdgeCacheVideoHtml), so the CSRF token in
        // their HTML can never match and every guest view-count beacon failed with 419. The beacon only bumps a
        // counter (cooldown per user / IP + user agent in ViewService), and SameSite=lax session cookies keep
        // cross-site requests anonymous. Static list: also covers Sanctum's ValidateCsrfToken on stateful API calls.
        ValidateCsrfToken::except(['api/videos/*/view']);

        $this->routes(function () {
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
            return Limit::perMinute($this->configuredRateLimit('upload_rate_limit', 10, 1000))
                ->by($this->requesterRateLimitKey($request).'|'.(string) $request->get('resumableIdentifier'))
                ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
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

        foreach (['login' => 5, 'contact' => 3, 'register' => 3] as $route => $limit) {
            RateLimiter::for($route, function (Request $request) use ($route, $limit) {
                $configKey = $route === 'login' ? 'login_rate_limit' : null;
                $maxAttempts = $configKey
                    ? $this->configuredRateLimit($configKey, $limit, 1000)
                    : $limit;

                return Limit::perMinute($maxAttempts)->by($request->ip())
                    ->response(fn(Request $request, array $headers) => $this->response($request, $headers));
            });
        }
    }

    private function response (Request $request, array $headers): JsonResponse|RedirectResponse {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('Too many attempts !')
            ], 429, $headers);
        }

        $response = back()->with('error', __('Too many attempts !'));

        if ($request->routeIs('login.perform', 'registration.perform')) {
            // Retain only non-secret fields when an auth form is throttled.
            // Passwords, CAPTCHA responses and tokens must never be flashed.
            $response->withInput($request->only('username', 'email', 'remember', 'cgu'));
        }

        return $response;
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
