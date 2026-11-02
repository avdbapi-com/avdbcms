<?php

namespace App\Http\Middleware;

use App\Support\AppearanceDraft;
use App\Support\VisitorChoices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EdgeCacheVideoHtml
{
    /**
     * Handle an incoming request.
     *
     * Ensures anonymous public page responses (Home, Trending, Categories, Search, Watch)
     * have proper public Edge/Cloudflare cache headers and strips session cookies so edge
     * proxies can cache the HTML and serve sub-50ms responses to mobile visitors.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return self::neverSharedWithCookies($this->edgeCache($request, $next));
    }

    /**
     * The last word on every answer of the web group (this middleware runs first, so it sees the cookies the session,
     * the CSRF token and "remember me" added on the way out): an answer that sets a cookie is that visitor's alone
     * (R1-087). A sitemap, a public-disk file or any other answer marked public that a member's browser fetched carried
     * the member's live session cookie, and a shared cache that keeps Set-Cookie would hand it to the next visitor. Such
     * an answer becomes private; the browser may still keep it (max-age stays), CDNs never. Answers without cookies
     * (a guest's public pages, whose session cookies edgeCache() removes, and the cookie-less picture routes) keep
     * their public headers.
     */
    public static function neverSharedWithCookies(Response $response): Response
    {
        if ($response->headers->getCookies() === []) {
            return $response;
        }

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control', ''));
        $shared = str_contains($cacheControl, 'public') || str_contains($cacheControl, 's-maxage');

        foreach (['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $header) {
            $value = strtolower(trim((string) $response->headers->get($header, '')));
            $shared = $shared || ($value !== '' && $value !== 'no-store' && ! str_contains($value, 'private'));
        }

        if (! $shared) {
            return $response;
        }

        PublicCacheForGuests::makePrivate($response);

        return $response;
    }

    private function edgeCache(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A preview for one visitor (an admin's draft, ?theme=, ?theme_mode=, ?daily_design=, ...) is never public:
        // a CDN would hand it to everybody who opens the same address.
        if (AppearanceDraft::isPreviewRequest($request)) {
            return AppearanceDraft::markPrivate($response);
        }

        // A demo site's visitor choices (App\Support\VisitorChoices): while "Let visitors try the looks" or the demo ads
        // switch is on, a page shows the look and the ads of the visitor's own cookies, so no page is shared by a CDN or
        // another visitor's cache (a guest's watch page included, which its controller makes public). Both off: as before.
        if (VisitorChoices::anyOn() && str_contains(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')) {
            return VisitorChoices::markPrivate($response);
        }

        // Only apply to safe GET and HEAD requests
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        // A page that showed one visitor's flashed message (a toast such as "Your account is closed", a status line, form
        // errors) is that visitor's, never the public copy of the address, even when its controller made it public (the
        // watch page does for guests). The flash data is gone from the session by now (StartSession ages it when it
        // saves, inside this middleware), so the layout marks such a page while it renders (App\Support\OneTimePage).
        if (class_exists(\App\Support\OneTimePage::class) && \App\Support\OneTimePage::isMarked($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('CDN-Cache-Control', 'no-store');
            $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
            $response->headers->remove('Expires');

            return $response;
        }

        // Never edge-cache for authenticated users or if session has flash messages/errors
        try {
            if (auth()->check() || (request()->hasSession() && (session()->has('errors') || session()->has('status')))) {
                return $response;
            }
        } catch (\Throwable) {
            // If session or auth is not initialized, continue
        }

        // Check if this response is a candidate for Edge Caching:
        // 1. Explicitly has Cloudflare-CDN-Cache-Control from controller
        // 2. Or is a public content route returning 200 text/html for a guest
        $hasExplicitEdgeCache = $response->headers->has('Cloudflare-CDN-Cache-Control')
            && $response->headers->get('Cloudflare-CDN-Cache-Control') !== 'no-store';

        $isVideoWatchRoute = $request->is('video/*')
            && ! $request->is('video/file/*', 'video/*/thumbnail', 'video/*/thumbnails/*', 'video/*/embed', 'video/random');

        $isPublicContentRoute = $request->is(
            '/',
            'trend',
            'categories',
            'categories/*',
            'search',
            'terms'
        );

        if (! $hasExplicitEdgeCache && ! $isVideoWatchRoute && ! $isPublicContentRoute) {
            return $response;
        }

        $statusCode = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
        if ($statusCode !== 200) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        // Apply Edge Caching TTLs:
        // Video watch pages: 120s browser, 600s edge
        // Feeds/Discovery (Home, Trend, Categories, Search): 60s browser, 300s edge
        $isWatch = $isVideoWatchRoute;
        $maxAge = $isWatch ? 120 : 60;
        $sMaxAge = $isWatch ? 600 : 300;
        $staleWhileRevalidate = $isWatch ? 1200 : 600;

        $response->headers->set('Cache-Control', "public, max-age={$maxAge}, s-maxage={$sMaxAge}, stale-while-revalidate={$staleWhileRevalidate}");
        $response->headers->set('Cloudflare-CDN-Cache-Control', "max-age={$sMaxAge}");
        $response->headers->set('CDN-Cache-Control', "max-age={$sMaxAge}");

        // Add Vary headers for content negotiation & cookies
        $existingVary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
        $varySeen = array_fill_keys(array_map('strtolower', $existingVary), true);
        foreach (['Accept-Encoding', 'Cookie', 'Accept-Language'] as $v) {
            if (! isset($varySeen[strtolower($v)])) {
                $existingVary[] = $v;
            }
        }
        $response->headers->set('Vary', implode(', ', $existingVary));

        // Remove legacy anti-cache headers & set future expiration
        $response->headers->remove('Pragma');
        $response->headers->set('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + $sMaxAge));

        // Strip session/csrf cookies for guest requests so Cloudflare can cache the page
        // without associating or distributing a session cookie to other visitors
        $sessionCookie = config('session.cookie');
        foreach ($response->headers->getCookies() as $cookie) {
            $name = $cookie->getName();
            if ($name === $sessionCookie || $name === 'XSRF-TOKEN' || str_contains(strtolower($name), 'session')) {
                $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
            }
        }
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
