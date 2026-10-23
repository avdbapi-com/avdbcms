<?php

namespace App\Http\Middleware;

use App\Support\AdminCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BustAdminCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $response->getStatusCode() < 400
            && !$request->isMethodSafe()
        ) {
            AdminCache::bumpVersion();
        }

        return $response;
    }
}
