<?php

namespace App\Http\Middleware;

use App\Support\BlockedAddresses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site's API (/api/*) for the addresses of Members > Blocked addresses (AVDBCMS 1.2.3): the rule the pages follow
 * through App\Http\Middleware\Security (App\Support\BlockedAddresses), answered in JSON, so a blocked address cannot
 * post comments, reports or likes through the API either. Signing in stays open (POST /api/login), and a signed-in
 * administrator (session or token, not a view-only demo account) is never blocked.
 */
class RefuseBlockedAddresses
{
    public function handle(Request $request, Closure $next): Response
    {
        if (BlockedAddresses::refuses($request)) {
            return response()->json(['message' => __('Access denied.')], 403);
        }

        return $next($request);
    }
}
