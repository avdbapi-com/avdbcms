<?php

namespace App\Support;

/**
 * Where a request reached the site, read before Laravel starts (public/index.php; the project folder's index.php loads
 * this file before the autoloader, so no framework class or helper may be used here).
 *
 * A site lives at a domain's root (https://example.com) or in a folder of it (https://example.com/site: Apache
 * "Alias /site", nginx "location /site", the project folder unpacked into the web root's site/ folder). The web server
 * says which by the address of the script it runs: SCRIPT_NAME "/site/index.php" (public/ or the project folder's
 * index.php), "/site/public/index.php" (the project folder's .htaccess sends every request into public/). Inside that
 * folder an address is read the way it is read at a domain's root: "/index.php" (a server without rewrite rules), then
 * the language ("/fr"), then the page. Laravel gets the folder and the page ("/site/trend", SCRIPT_NAME
 * "/site/index.php"), so its own root of the site (request()->root(), url(), route()) is https://example.com/site, and
 * the language and /index.php are marked as before (DETECTED_LOCALE, LOCALE_PREFIX, AVDBCLIP_INDEX_PHP_PREFIX). A site
 * at a domain's root gets exactly what it got up to 1.2.3 RC2 (round 3 fix pass, R2R-05).
 */
final class SiteAddress
{
    /** The first segments of an address that are never a language. */
    public const RESERVED = ['api', 'admin', '_debugbar', 'logs', 'horizon', 'storage', 'update-locale', 'public', ''];

    /**
     * The folder the site is installed in, from the address of the script the web server runs ($server: $_SERVER):
     * "" at a domain's root, "/site" in a folder. Only when the request's own address is inside that folder: a server
     * that sends the addresses of a domain's root to a script in a folder serves the site at the root.
     *
     * @param  array<string, mixed>  $server
     */
    public static function folder(array $server): string
    {
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $at = strpos($script, '/public/index.php');

        if ($at !== false) {
            $folder = substr($script, 0, $at);
        } elseif (preg_match('#^(.*)/index\.php$#i', $script, $match) === 1) {
            $folder = $match[1];
        } else {
            return '';
        }

        $folder = rtrim($folder, '/');

        // Only a plain folder of the domain: segments of the characters an address path may hold, never "." or "..", an
        // empty segment or a backslash (a redirect that starts with it must stay a path of this site), never a ".php"
        // segment (PHP-FPM's cgi.fix_pathinfo can make SCRIPT_NAME "/index.php/x/index.php" of that address).
        if ($folder === '' || preg_match('#^(/[A-Za-z0-9._~%!$&\'()*+,;=:@-]+)+$#', $folder) !== 1
            || preg_match('#/\.{1,2}(?=/|$)#', $folder) === 1 || preg_match('#\.php(?=/|$)#i', $folder) === 1) {
            return '';
        }

        $path = self::path((string) ($server['REQUEST_URI'] ?? '/'));

        return $path === $folder || str_starts_with($path, $folder.'/') ? $folder : '';
    }

    /**
     * Hand the request to Laravel (see the class): $server changes in place. Returns the address of the redirect that
     * takes a trailing slash off (301, always a path of this site), or null.
     *
     * @param  array<string, mixed>  $server  $_SERVER
     * @param  list<string>  $languages  the site's language codes (config/languages.php)
     * @param  string  $publicDir  public/ (a real folder of it keeps its slash)
     * @param  string  $frontFile  public/index.php (the script Laravel is told it runs behind the project folder's .htaccess)
     */
    public static function prepare(array &$server, array $languages, string $publicDir, string $frontFile): ?string
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = self::path($uri);
        $query = parse_url($uri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : null;
        $folder = self::folder($server);

        // Root/public front-controller fallbacks (Apache/LiteSpeed panels whose project folder's .htaccess rewrites into
        // public/): Laravel is told it runs the site's own index.php, never /public/index.php/... in the links.
        $scriptName = (string) ($server['SCRIPT_NAME'] ?? '');
        $phpSelf = (string) ($server['PHP_SELF'] ?? '');
        if (str_contains($scriptName, '/public/index.php') || str_contains($phpSelf, '/public/index.php')) {
            $server['DOCUMENT_ROOT'] = $publicDir;
            $server['SCRIPT_FILENAME'] = $frontFile;
            $server['SCRIPT_NAME'] = $folder.'/index.php';
            $server['PHP_SELF'] = $folder.'/index.php';
            $server['AVDBCLIP_ROOT_FALLBACK'] = true;
        }

        $rewrite = static function (string $page) use (&$server, $folder, $query): void {
            $server['REQUEST_URI'] = $folder.$page.($query ? '?'.$query : '');
            $server['PATH_INFO'] = $page;
        };

        if ($folder !== '') {
            // The rest of the address is read as at a domain's root. The site's own home is "/site/": Laravel finds the
            // folder in the address only with its slash.
            $page = (string) substr($path, strlen($folder));
            $path = $page === '' ? '/' : $page;

            if ($page === '') {
                $rewrite('/');
            }
        }

        foreach (['/public/index.php', '/index.php'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                if ($prefix === '/index.php') {
                    $server['AVDBCLIP_INDEX_PHP_PREFIX'] = true;
                }

                $path = (string) substr($path, strlen($prefix));
                if ($path === '') {
                    $path = '/';
                }
                $rewrite($path);
                break;
            }
        }

        // The web root is the project folder (OSPanel, some shared hosts): /public/x is x.
        if (str_starts_with($path, '/public/') || $path === '/public') {
            $path = (string) substr($path, strlen('/public'));
            if ($path === '') {
                $path = '/';
            }
            $rewrite($path);
        }

        // The language in front of the page (/fr/trend): kept in DETECTED_LOCALE and LOCALE_PREFIX, taken off the address.
        $segments = explode('/', ltrim($path, '/'));
        $firstSegment = $segments[0] ?? '';
        $detectedLocale = null;
        $pathBeforeStrip = $path;

        if ($firstSegment !== '' && ! in_array($firstSegment, self::RESERVED, true) && in_array($firstSegment, $languages, true)) {
            $detectedLocale = $firstSegment;
            $server['DETECTED_LOCALE'] = $firstSegment;
            $server['LOCALE_PREFIX'] = '/'.$firstSegment;

            $stripped = (string) substr($path, strlen('/'.$firstSegment));
            $path = $stripped !== '' ? $stripped : '/';
            $rewrite($path);
        }

        // Trailing slashes go with a 301 (no Apache rule needed: nginx, LiteSpeed, IIS the same):
        //   A: /vi/trend/ -> /vi/trend   B: /vi/ -> /vi   C: /trend/ -> /trend
        // An address that came in through /index.php keeps it (/index.php/trend/ -> /index.php/trend): on a server without
        // rewrite rules the clean address is the web server's 404 page (round 1b review BWR-06). A real folder of public/
        // (/storage/, /build/) is never redirected: the web server would add the slash again, for ever (BWR-05); Laravel
        // answers it (404). The site's folder stays in front (/site/trend/ -> /site/trend).
        $keptIndexPhp = (! empty($server['AVDBCLIP_INDEX_PHP_PREFIX']) || ! empty($server['AVDBCLIP_USED_INDEX_PHP'])) ? '/index.php' : '';
        $realFolder = $pathBeforeStrip !== '/' && str_ends_with($pathBeforeStrip, '/') && ! str_contains($pathBeforeStrip, "\0")
            && @is_dir($publicDir.rtrim($pathBeforeStrip, '/'));

        if ($realFolder) {
            return null;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            // Always one leading slash: a path that starts with "//" or "/\" (/index.php//evil.example/) would otherwise
            // send the visitor to another site.
            $localeSegment = $detectedLocale !== null ? '/'.$detectedLocale : '';

            return $folder.$keptIndexPhp.'/'.ltrim($localeSegment.rtrim($path, '/'), '/\\').($query ? '?'.$query : '');
        }

        if ($detectedLocale !== null && $pathBeforeStrip !== '/' && str_ends_with($pathBeforeStrip, '/') && $path === '/') {
            return $folder.$keptIndexPhp.'/'.$detectedLocale.($query ? '?'.$query : '');
        }

        return null;
    }

    /** The path of a request's address ("/" when it has none; "" when it cannot be read, as up to 1.2.3 RC2). */
    private static function path(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        return is_string($path) ? $path : '';
    }
}
