<?php

namespace App\Http\Middleware;

use App\Support\AdminIpLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IsAdmin
{
    /**
     * The only query keys of an admin GET that hold a list: the log viewer's own calls (Tools > Logs leaves levels and
     * file types out: "?exclude_levels[]=debug"). Every other key of an admin page or tool holds one value: a search, a
     * filter, a sort, a page, a file, a folder, a tab (keepQueryShapes()).
     */
    public const LIST_QUERY = ['exclude_levels', 'exclude_file_types'];

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
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                self::keepQueryShapes($request);
            }

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

    /**
     * A crafted address that gives a list where an admin page or tool reads one value ("?search[]=x", "?usage[]=x",
     * "?path[]=x", "?file[]=x") reached code and views that expect text and answered 500 with a stack trace in the log
     * (R1-075). On a GET such a list is dropped, so the page answers as it does without it. A list key (LIST_QUERY)
     * keeps a flat list, gets a list of one when it is given one value, and is dropped when its list holds lists.
     * Forms that send lists (the bulk bars' ids[], the import categories) post them: a body is never touched here.
     */
    private static function keepQueryShapes(Request $request): void
    {
        foreach ($request->query->all() as $key => $value) {
            if (! in_array((string) $key, self::LIST_QUERY, true)) {
                if (is_array($value)) {
                    $request->query->remove((string) $key);
                }

                continue;
            }

            if (! is_array($value)) {
                $request->query->set((string) $key, [(string) $value]);
            } elseif (array_filter($value, static fn ($item): bool => ! is_scalar($item)) !== []) {
                $request->query->remove((string) $key);
            }
        }
    }
}

