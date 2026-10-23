<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdminOperationalToolsEnabled
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ((bool) config('admin.operational_tools_enabled', false)) {
            return $next($request);
        }

        abort(404);
    }
}
