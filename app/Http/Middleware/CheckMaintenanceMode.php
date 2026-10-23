<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     * If admin has enabled maintenance mode in Settings, block non-admin users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if maintenance mode is active
        if (!config('site.maintenance_mode', false)) {
            return $next($request);
        }

        // Always allow login and admin routes
        if ($request->is('admin/*') || $request->is('login') || $request->is('password/*')) {
            return $next($request);
        }

        // Allow admin users through (if setting permits)
        if (config('site.maintenance_allow_admins', true) && auth()->check() && auth()->user()->is_admin) {
            return $next($request);
        }

        // Allow secret key bypass: /secret-key appended to any URL
        $secret = config('site.maintenance_secret', '');
        if ($secret && $request->is($secret)) {
            session()->put('maintenance_bypass', true);
            return redirect('/');
        }
        if ($secret && session('maintenance_bypass')) {
            return $next($request);
        }

        // Allow whitelisted IPs
        $allowedIps = config('site.maintenance_allowed_ips', '');
        if ($allowedIps) {
            $ips = array_map('trim', preg_split('/[\n,]+/', $allowedIps));
            if (in_array($request->ip(), $ips)) {
                return $next($request);
            }
        }

        // Block with maintenance page
        $message = config('site.maintenance_message', 'We are currently performing maintenance. Please check back soon.');
        $retryAfter = config('site.maintenance_retry_after', 3600);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'maintenance' => true], 503)
                ->header('Retry-After', $retryAfter);
        }

        // Try dedicated maintenance view, fall back to 503 error
        $view = view()->exists('maintenance') ? 'maintenance' : 'errors.503';

        return response()->view($view, [
            'message' => $message,
            'title'   => 'Site Under Maintenance',
        ], 503)->header('Retry-After', $retryAfter);
    }
}
