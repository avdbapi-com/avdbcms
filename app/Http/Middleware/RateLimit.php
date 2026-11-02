<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * "rate:N": N tries a minute per member and route (the "Send the link again" and "Send code" buttons: rate:1). A refused
 * try goes back to its page with "Too many tries. Wait 42 seconds, then try again." (it was a bare "Too many attempts" with
 * no wait); a script gets the same sentence as JSON with 429 and Retry-After.
 */
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
            $seconds = max(1, RateLimiter::availableIn($key));
            $message = RouteServiceProvider::waitMessage($seconds);

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'retry_after' => $seconds], 429, ['Retry-After' => $seconds]);
            }

            return back()->with('error', $message);
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

