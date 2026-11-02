<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;

class StripEmptyParams
{
    protected array $except = [
        //'admin/telescope/*'
    ];

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure(Request): (Response|RedirectResponse)  $next
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $query = $request->query();

        if (!$query || $this->inExceptArray($request)) {
            return $next($request);
        }

        $cleanQuery = array_filter($query, fn($value) => !is_null($value));

        if (count($query) === count($cleanQuery)) {
            return $next($request);
        }

        $queryString = http_build_query($cleanQuery, '', '&', PHP_QUERY_RFC3986);

        // The same page in a form that reaches the site: public/index.php took /index.php off the request's address, so
        // on a server without rewrite rules a search or a list filter with an empty field went to the web server's 404
        // page (round 1b, R1B-007's "nothing 404s under /index.php/...").
        return redirect()->to(\App\Support\CleanUrls::requestForm($request->url()).($queryString !== '' ? '?'.$queryString : ''));
    }

    /**
     * Determine if the request has a URI that should pass through StripEmptyParams.
     *
     * @param Request $request
     * @return bool
     */
    protected function inExceptArray(Request $request): bool
    {
        foreach ($this->except as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->fullUrlIs($except) || $request->is($except)) {
                return true;
            }
        }

        return false;
    }
}
