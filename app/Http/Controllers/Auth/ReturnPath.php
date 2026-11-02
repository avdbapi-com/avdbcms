<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;

/**
 * Where a member goes back to after signing in (member design 6.12, auth-8): the page they were on.
 *
 * Every sign-in entry link carries that page as ?redirect=/path (header, bottom bar, guest prompt, the watch page's
 * sign-in links: ReturnPath::loginUrl()), and the sign-in page also reads the same-site page the browser says it came
 * from (Referer). Only a path of THIS site is ever kept: a relative path ("/vi/video/x?t=3") or an http(s) address whose
 * host is the site's own, never another host, a protocol-relative "//host", a backslash or control character (which
 * browsers read differently from parse_url()), an API address, or a sign-in page (that would loop). The page is stored
 * as Laravel's own "intended" address, so redirect()->intended() after the sign-in uses it; a page the auth middleware
 * bounced to the sign-in page (it stored its own address first) is never replaced by the Referer.
 */
final class ReturnPath
{
    /** The query parameter of the entry links. */
    public const PARAM = 'redirect';

    /** The longest address kept (a longer one is not a page of this site anyone clicked). */
    private const MAX_LENGTH = 2000;

    /** Pages a member never goes back to after signing in (a loop or a one-time page), with or without a language. */
    private const SKIP = [
        'login', 'login/*', 'logout', 'register', 'register/*', 'forgot-password', 'reset-password', 'reset-password/*',
        'email/*', 'oauth/*', 'phone/*', 'api', 'api/*', 'update-locale', 'update-locale/*', 'broadcasting/*',
        'sanctum/*', 'livewire/*',
    ];

    /**
     * Remembers the page to go back to, from ?redirect= first (an entry link the visitor just clicked: the freshest),
     * else from the Referer when no page is remembered yet (a bounce by the auth middleware stored its page already).
     */
    public static function rememberFrom(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $asked = self::safePath($request->query(self::PARAM), $request);

        if ($asked !== null) {
            $request->session()->put('url.intended', url($asked));

            return;
        }

        if ($request->session()->has('url.intended')) {
            // A page the auth middleware bounced here was remembered as the request saw it, without the /index.php that
            // public/index.php takes off. On a site whose links keep /index.php (a web server without rewrite rules)
            // that clean address is the web server's 404 page after the sign-in: it is kept in the site's own form.
            $intended = $request->session()->get('url.intended');
            $path = function_exists('index_php_links') && index_php_links() ? self::safePath($intended, $request) : null;

            if ($path !== null && ! str_starts_with($path, '/index.php')) {
                $request->session()->put('url.intended', url($path));
            }

            return;
        }

        $came = self::safePath($request->headers->get('referer'), $request);

        if ($came !== null) {
            $request->session()->put('url.intended', url($came));
        }
    }

    /**
     * The path (with its query, without a fragment) when $candidate is a page of this site a member may go back to,
     * else null.
     */
    public static function safePath(mixed $candidate, ?Request $request = null): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $candidate) === 1) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));

            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($host, self::ownHosts($request), true)) {
                return null;
            }
        } elseif (preg_match('#^/(?![/\\\\])#', $candidate) !== 1) {
            return null; // "x", "//evil.example", "/\evil.example"
        }

        // A site installed in a folder (https://example.com/site): the page is what follows the folder, which the browser's
        // address has and route(), url() and locale_url() put in front themselves (R2R-05: /site/site/... after signing in).
        $path = site_path('/'.ltrim((string) ($parts['path'] ?? '/'), '/'));

        if (str_starts_with($path, '//') || self::skipped($path)) {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    /**
     * The sign-in address for an entry link: the localized /login with ?redirect= the page shown now (or $path), so the
     * member comes back to it. On the sign-in pages themselves (and pages that cannot be a return address) the plain
     * sign-in address.
     */
    public static function loginUrl(?string $path = null, ?Request $request = null): string
    {
        $request ??= request();
        $path ??= self::currentPath($request);
        $back = self::safePath($path, $request);
        // ?redirect= is the page as the browser addresses it, like the sign-in links the pages' scripts build (with the
        // folder of a site installed in one).
        $parameters = $back !== null && $back !== '/' ? [self::PARAM => site_browser_path($back)] : [];

        return locale_url(route('login', $parameters));
    }

    /** The page shown now, as the visitor's address bar has it (with its language). */
    public static function currentPath(?Request $request = null): string
    {
        return locale_current_url($request ?? request());
    }

    /** Whether the path (with or without a language segment) is a page a member is never sent back to. */
    private static function skipped(string $path): bool
    {
        $relative = trim($path, '/');
        $segments = $relative === '' ? [] : explode('/', $relative);
        $languages = (array) config('languages', []);

        if ($segments !== [] && isset($languages[$segments[0]])) {
            array_shift($segments);
        }

        if ($segments !== [] && $segments[0] === 'index.php') {
            array_shift($segments);
        }

        $relative = implode('/', $segments);

        if ($relative === '') {
            return false; // the home page is a fine place to go back to
        }

        foreach (self::SKIP as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the site's own host names: APP_URL's and the one this request came to */
    private static function ownHosts(?Request $request): array
    {
        $hosts = [strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))];

        if ($request !== null) {
            $hosts[] = strtolower($request->getHost());
        }

        return array_values(array_unique(array_filter($hosts)));
    }
}
