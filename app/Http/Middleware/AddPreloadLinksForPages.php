<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

/**
 * The page's first Vite preloads as a Link header (which also lets Cloudflare send 103 Early Hints), for page views only.
 * A request the page sends by itself (a part of an admin page filled in after it appears: modules/list-probes.ts fetches
 * the page again; an XHR or fetch() for JSON) gets none: Chrome preloads the header's files for any answer, so each such
 * request loaded the admin CSS a second time and the console said "preloaded but not used" (R1-163).
 */
class AddPreloadLinksForPages extends AddLinkHeadersForPreloadedAssets
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int|null  $limit
     * @return \Illuminate\Http\Response
     */
    public function handle($request, $next, $limit = null)
    {
        if (self::isOwnRequest($request)) {
            return $next($request);
        }

        return parent::handle($request, $next, $limit);
    }

    /** A background or script request: marked by the site's own helpers, an XHR, or a fetch that is no navigation. */
    public static function isOwnRequest(Request $request): bool
    {
        if (BackgroundRequest::is($request) || $request->headers->get(BackgroundRequest::HEADER) === '1') {
            return true;
        }

        if (strtolower((string) $request->headers->get('X-Requested-With')) === 'xmlhttprequest') {
            return true;
        }

        // Browsers say how a request was made: anything but a page navigation (or an iframe's) needs no preloads.
        $mode = strtolower((string) $request->headers->get('Sec-Fetch-Mode'));

        return $mode !== '' && ! in_array($mode, ['navigate', 'nested-navigate'], true);
    }
}
