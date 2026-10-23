<?php

namespace App\Http\Middleware;

use App\Support\AdminIpLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \never
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->is_admin) {

            // Enforce the admin IP whitelist (IPv4, IPv6 and CIDR entries) against the real visitor address:
            // forwarded headers only count when they come from Cloudflare, this machine, a private network or a
            // listed proxy. The page explains how the owner gets back in; it never shows the list.
            $whitelist = (string) config('site.admin_ip_whitelist', '');
            if (! AdminIpLock::allows($request, $whitelist)) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => __('Admin access denied from this IP.')], 403);
                }

                return response()
                    ->view('admin.ip-lock', ['ip' => AdminIpLock::clientIp($request)], 403)
                    ->header('Cache-Control', 'no-store, private')
                    ->header('X-Robots-Tag', 'noindex, nofollow');
            }

            return $next($request);
        }

        return abort(404);
    }
}

