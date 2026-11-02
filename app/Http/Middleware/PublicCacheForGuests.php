<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cache headers of the API's public lists (search, the video lists, a category's videos), for anybody who may share
 * them: the options of Laravel's cache.headers ("public;max_age=30;etag"), but public only for a visitor whose answer
 * sets no cookie and depends on nobody.
 *
 * The site's own pages call these lists with the visitor's session (Sanctum's stateful requests): such an answer sets
 * the session and XSRF-TOKEN cookies, and for a signed-in member the session cookie is the member's live sign-in. With
 * "public, max-age=30" a shared cache that keeps Set-Cookie would hand it to the next visitor (R1-087; Cloudflare
 * strips it, other caches may not). An app signed in with a token gets lists made for it. Both answers are private
 * (the browser may still keep them for max-age); a request without a session or an account (another site's script, a
 * crawler, a guest app) keeps the public answer a CDN can share.
 *
 * Used by class name in routes/api.php: ->middleware(PublicCacheForGuests::class.':public;max_age=30;etag').
 */
final class PublicCacheForGuests
{
    public function handle(Request $request, Closure $next, string $options = ''): Response
    {
        $response = (new SetCacheHeaders())->handle($request, $next, $options);

        if ($request->hasSession() || $request->user() !== null) {
            self::makePrivate($response);
        }

        return $response;
    }

    /** "public, max-age=30" becomes "private, max-age=30": the browser may keep it, a shared cache never. */
    public static function makePrivate(Response $response): void
    {
        $response->headers->removeCacheControlDirective('public');
        $response->headers->removeCacheControlDirective('s-maxage');
        $response->headers->addCacheControlDirective('private');

        foreach (['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $header) {
            if ($response->headers->has($header)) {
                $response->headers->set($header, 'no-store');
            }
        }
    }
}
