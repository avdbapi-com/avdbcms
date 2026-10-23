<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class RateLimit
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60): Response
    {
        $userId = $request->user()?->id ? '|'. $request->user()->id : '';

        // Apply admin settings to override rate limits per route type BEFORE checking
        $routeName = $request->route()?->getName() ?? '';
        if (str_contains($routeName, 'login')) {
            $maxAttempts = (int) config('site.login_rate_limit', $maxAttempts);
        } elseif (str_contains($routeName, 'upload')) {
            $maxAttempts = (int) config('site.upload_rate_limit', $maxAttempts);
        } elseif (str_contains($routeName, 'contact')) {
            $maxAttempts = (int) config('site.api_rate_limit', 3);
        }

        $key = $request->route()->getName() .$userId.':rate';

        if (RateLimiter::tooManyAttempts($key, $perMinute = $maxAttempts)) {
            return back()->with("error", __("Too many attempts !"));
        }

        RateLimiter::increment($key);

        if (RateLimiter::remaining($key, $maxAttempts) === 0) {
            $seconds = RateLimiter::availableIn($key);
            $sessionKey = $request->route()->getName().$userId.':next';
            Session::put($sessionKey, now()->addSeconds($seconds));
        }

        return $next($request);
    }
}

