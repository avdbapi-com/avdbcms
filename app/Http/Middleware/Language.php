<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class Language
{
    /**
     * Paths that should NEVER trigger a locale redirect.
     * These are either API routes, admin routes, or infrastructure paths.
     */
    private const SKIP_REDIRECT_PATHS = [
        'api/*', 'admin', 'admin/*', 'update-locale', 'update-locale/*',
        '_debugbar/*', 'storage/*', 'horizon/*', 'logs/*',
        'manifest.json', 'robots.txt', 'sitemap.xml', 'sitemap.xsl', 'sitemap-*.xml', 'video-sitemap.xml',
        'video/file/*', 'video/*/thumbnail', 'video/*/thumbnails/*', 'video/*/embed',
        'users/*/avatar', 'users/*/banner',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Admin-configured default locale — populated from DB by AppServiceProvider.
        // This is the "bare URL" locale (no prefix needed), always 'en' unless admin changed it.
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        $languages     = config('languages', []);

        if ($request->is('api/*')) {
            $locale = $this->resolveApiLocale($request, $languages, $defaultLocale);
            App::setLocale($locale);
            Carbon::setLocale($locale);

            $response = $next($request);
            $response->headers->set('Content-Language', $locale);
            $this->addVaryHeaders($response, ['X-Locale', 'Accept-Language', 'Referer', 'Cookie']);

            return $response;
        }

        // index.php already stripped the locale prefix from REQUEST_URI and stored it here.
        $detectedLocale = $_SERVER['DETECTED_LOCALE'] ?? null;

        if ($detectedLocale && isset($languages[$detectedLocale])) {
            // ── URL had a /{locale} prefix ────────────────────────────────────
            // Use it as the active locale and persist to session.
            App::setLocale($detectedLocale);
            Carbon::setLocale($detectedLocale);

            try {
                session()->put('app_locale', $detectedLocale);
            } catch (\Throwable) {}

        } else {
            // ── No /{locale} prefix in URL ────────────────────────────────────
            // Resolve locale from: session → user preference → site default.
            $locale = $defaultLocale;

            try {
                $sessionLocale = session('app_locale');
                if ($sessionLocale && isset($languages[$sessionLocale])) {
                    $locale = $sessionLocale;
                }
            } catch (\Throwable) {}

            // If the resolved locale is NOT the default, redirect to the
            // canonical locale-prefixed URL (e.g. / → /vi, /profile → /vi/profile).
            //
            // Rules:
            //  - Only redirect GET / HEAD requests (never POST, PUT, DELETE etc.)
            //  - Never redirect API, admin, or infrastructure paths
            //  - Never redirect AJAX / JSON requests (they handle locale via window.LANG)
            //  - Use 302 (temporary) because this is session-based, not a permanent rule
            if (
                $locale !== $defaultLocale &&
                in_array($request->method(), ['GET', 'HEAD']) &&
                !$request->is(self::SKIP_REDIRECT_PATHS) &&
                !$request->ajax() &&
                !$request->expectsJson()
            ) {
                $path   = $request->getPathInfo();
                $qs     = $request->getQueryString();
                $newUrl = '/' . $locale . ($path === '/' ? '' : $path) . ($qs ? '?' . $qs : '');

                return redirect($newUrl, 302);
            }

            App::setLocale($locale);
            Carbon::setLocale($locale);
        }

        $response = $next($request);
        $locale = App::currentLocale();

        $response->headers->set('Content-Language', $locale);
        $this->addVaryHeaders($response, ['Cookie', 'Accept-Language']);

        $cacheControl = (string) $response->headers->get('Cache-Control', '');
        $hasPublicEdgeCache = (str_contains($cacheControl, 'public') && (str_contains($cacheControl, 'max-age') || str_contains($cacheControl, 's-maxage')))
            || ($response->headers->has('Cloudflare-CDN-Cache-Control') && $response->headers->get('Cloudflare-CDN-Cache-Control') !== 'no-store');

        if (! $hasPublicEdgeCache && ($this->isPrivateOrSessionSensitivePath($request) || $this->isDynamicHtmlResponse($request, $response))) {
            $this->addNoStoreHeaders($response);
        }

        return $response;
    }

    private function resolveApiLocale(Request $request, array $languages, string $defaultLocale): string
    {
        $candidates = [
            $request->query('locale'),
            $request->headers->get('X-Locale'),
            $this->localeFromReferer($request),
            $request->getPreferredLanguage(array_keys($languages)),
            $this->localeFromSession($request),
            $defaultLocale,
            config('app.locale', 'en'),
        ];

        foreach ($candidates as $candidate) {
            $locale = $this->normalizeLocale($candidate);
            if ($locale !== null && isset($languages[$locale])) {
                return $locale;
            }
        }

        return 'en';
    }

    private function localeFromReferer(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        $path = (string) parse_url($referer, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        return $segments[0] ?? null;
    }

    private function localeFromSession(Request $request): ?string
    {
        try {
            if ($request->hasSession()) {
                return $request->session()->get('app_locale');
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function normalizeLocale(mixed $locale): ?string
    {
        if (! is_scalar($locale)) {
            return null;
        }

        $locale = strtolower(str_replace('_', '-', trim((string) $locale)));

        return preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $locale) ? $locale : null;
    }

    private function addVaryHeaders($response, array $headers): void
    {
        $existing = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
        $seen = array_fill_keys(array_map('strtolower', $existing), true);

        foreach ($headers as $header) {
            if (! isset($seen[strtolower($header)])) {
                $existing[] = $header;
            }
        }

        $response->headers->set('Vary', implode(', ', $existing));
    }

    private function isPrivateOrSessionSensitivePath(Request $request): bool
    {
        if ($request->is('admin', 'admin/*', 'update-locale', 'update-locale/*')) {
            return true;
        }

        if ($request->is(
            'login',
            'logout',
            'register',
            'profile',
            'profile/*',
            'password/*',
            'email/*',
            'playlist/*',
            'watch-later/*'
        )) {
            return true;
        }

        try {
            return $request->user() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isDynamicHtmlResponse(Request $request, $response): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'POST'], true)) {
            return true;
        }

        $cacheControl = (string) $response->headers->get('Cache-Control', '');
        if (str_contains($cacheControl, 'public') && (str_contains($cacheControl, 's-maxage') || str_contains($cacheControl, 'max-age'))) {
            return false;
        }

        if ($response->headers->has('Cloudflare-CDN-Cache-Control') && $response->headers->get('Cloudflare-CDN-Cache-Control') !== 'no-store') {
            return false;
        }

        if ($request->is(
            'api/*',
            'robots.txt',
            'sitemap.xml',
            'sitemap-*.xml',
            'video-sitemap.xml',
            'manifest.json',
            'storage/*',
            'video/file/*',
            'video/*/thumbnail',
            'video/*/thumbnails/*',
            'video/*/embed',
            'users/*/avatar',
            'users/*/banner'
        )) {
            return false;
        }

        $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
        if ($status >= 300 && $status < 400) {
            return true;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        return str_contains($contentType, 'text/html');
    }

    private function addNoStoreHeaders($response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
    }
}
