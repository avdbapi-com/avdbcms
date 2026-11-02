<?php

if (!function_exists('site_root')) {
    /**
     * The root of the site's addresses, as Laravel's URL generator starts each one (url(), route(), redirects): the
     * scheme, the host and the folder the site is installed in - "https://example.com" at a domain's root,
     * "https://example.com/site" for a site installed in a folder (round 3 fix pass, R2R-05). APP_URL on an installed
     * site, the request's own root while APP_URL is still a local one (the installer, a copy on this machine). Never with
     * "/index.php": the generator puts that in front of each path where the site's links carry it.
     */
    function site_root(): string
    {
        try {
            $generator = app('url');
            $root = (string) $generator->formatRoot($generator->formatScheme());
        } catch (\Throwable) {
            $root = (string) config('app.url');
        }

        return rtrim((string) preg_replace('#/index\.php/?$#i', '', rtrim($root, '/')), '/');
    }
}

if (!function_exists('site_folder')) {
    /** The folder the site is installed in, as its addresses start with it: "" at a domain's root, "/site" for https://example.com/site. */
    function site_folder(): string
    {
        $folder = trim((string) parse_url(site_root(), PHP_URL_PATH), '/');

        return $folder === '' ? '' : '/'.$folder;
    }
}

if (!function_exists('site_path')) {
    /**
     * An address as the browser has it (the request's own address, a page in ?redirect=, the path of a Referer) as a path
     * of the site: without the folder the site is installed in, which route(), url(), redirect()->to() and locale_url()
     * put in front themselves. At a domain's root, and for a path outside the folder, the path as it is.
     */
    function site_path(string $address): string
    {
        $folder = site_folder();

        if ($folder === '' || preg_match('#^'.preg_quote($folder, '#').'(?=$|[/?\#])#', $address) !== 1) {
            return $address;
        }

        // One slash in front, never two or a backslash: "//evil.example" (from "/site//evil.example") is read as another
        // host by browsers and by the URL generator (cold review, 29 Oct: an open redirect through the https redirect).
        return '/'.ltrim((string) substr($address, strlen($folder)), '/\\');
    }
}

if (!function_exists('site_browser_path')) {
    /**
     * A path of the site ("/trend", "/fr/trend?x=1", a file "/images/x.svg") as the browser addresses it: the folder the
     * site is installed in in front ("/site/fr/trend?x=1"). At a domain's root the path as it is.
     */
    function site_browser_path(string $path): string
    {
        $folder = site_folder();

        return $folder === '' ? $path : $folder.'/'.ltrim($path, '/');
    }
}

if (!function_exists('site_file_url')) {
    /**
     * A file of the site's public folder ("/images/placeholders/no-image.svg") as an address that works wherever it is
     * printed as it is: an <img>, a CSS url(), the API's JSON, og:image and the video sitemap (site_asset_url() keeps a
     * whole address). At a domain's root the path, as up to 1.2.3 RC2; on a site installed in a folder the whole address
     * (asset(): APP_URL with its folder, or ASSET_URL), like the addresses of the uploaded pictures (Storage::url(),
     * route()). A path with the folder ("/site/images/...") got the folder a second time from site_asset_url() and asset()
     * (round 3 review SB-RV-01: /site/site/images/default_banner.jpg in og:image, the video sitemap and the JSON-LD).
     */
    function site_file_url(string $path): string
    {
        return site_folder() === '' ? $path : asset(ltrim($path, '/'));
    }
}

if (!function_exists('index_php_links')) {
    /**
     * Whether the site's links carry /index.php/... (EnvironmentServiceProvider decides with App\Support\CleanUrls and
     * applies it to url() and route(): locale_url() and locale_switch_url() follow the same answer, so one page never
     * mixes both forms). Before that decision (a console process, a failed boot) as up to 1.2.2: /index.php/... for a
     * request that came in through /index.php.
     */
    function index_php_links(): bool
    {
        if (config('app.clean_url_reason') !== null) {
            return (bool) config('app.clean_url_fallback', false);
        }

        return (bool) config('app.clean_url_fallback', false)
            || !empty($_SERVER['AVDBCLIP_USED_INDEX_PHP'])
            || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/index.php');
    }
}

if (!function_exists('locale_path_parts')) {
    /**
     * An address as a page of THIS site, read the way a browser reads a link: the one rule of locale_url() and
     * locale_switch_url() (round 2a R2A-005; the language switch applies the same reading to its return page,
     * App\Http\Controllers\LangController, R1B-013).
     *
     * - Of an absolute address only the path, the query and the fragment count: the scheme and the host go, so what is
     *   built from it is always an address of this site.
     * - Browsers drop tabs and line breaks anywhere in an address and the spaces and control characters around it, and
     *   read a backslash as a slash: the same here. Empty segments go. "/vi//evil.example", "/\evil.example" and
     *   "/<tab>/evil.example" each became "//evil.example", a link to another site ("Try again" of the error pages,
     *   built from the address the visitor opened); now they are "/evil.example", a page of this site.
     * - "/index.php" and the language segment in front are taken off, in either order.
     * - An absolute address carries the folder the site is installed in (https://example.com/site/fr/trend): the page is
     *   what follows it (site_path()). A path is a path of the site already ("/fr/trend": what route(..., false), the
     *   request's getPathInfo() and the site's own literals give); one as the browser has it goes through site_path()
     *   first (round 3 fix pass, R2R-05).
     *
     * @return array{path: string, query: ?string, fragment: ?string, index_php: bool, locale: ?string}
     *         path: "/" or "/a/b", never an empty segment, a backslash, a tab, a line break or a trailing slash
     */
    function locale_path_parts(string $address): array
    {
        $address = trim(str_replace(["\t", "\n", "\r"], '', $address), "\x00..\x20");
        $parsed = parse_url($address);

        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $path = site_path((string) ($parsed['path'] ?? '/'));
            $query = $parsed['query'] ?? null;
            $fragment = $parsed['fragment'] ?? null;
        } else {
            $query = null;
            $fragment = null;
            $hash = strpos($address, '#');

            if ($hash !== false) {
                $fragment = substr($address, $hash + 1);
                $address = substr($address, 0, $hash);
            }

            $mark = strpos($address, '?');

            if ($mark !== false) {
                $query = substr($address, $mark + 1);
                $address = substr($address, 0, $mark);
            }

            $path = $address;
        }

        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== ''
        ));
        $languages = config('languages', []);
        $indexPhp = false;
        $locale = null;

        while ($segments !== []) {
            if (!$indexPhp && strtolower($segments[0]) === 'index.php') {
                $indexPhp = true;
                array_shift($segments);

                continue;
            }

            if ($locale === null && is_array($languages) && isset($languages[$segments[0]])) {
                $locale = $segments[0];
                array_shift($segments);

                continue;
            }

            break;
        }

        return [
            'path' => '/' . implode('/', $segments),
            'query' => $query,
            'fragment' => $fragment,
            'index_php' => $indexPhp,
            'locale' => $locale,
        ];
    }
}

if (!function_exists('locale_url')) {
    /**
     * $path (a path or an address of this site) in the current language: "/fr/trend", "/trend" in the site's default
     * language, "/index.php/fr/trend" on a site whose links carry /index.php. Always a page of this site: never "//...",
     * never another host (locale_path_parts()).
     *
     * A site installed in a folder (APP_URL https://example.com/site) gets the whole address:
     * "https://example.com/site/fr/trend". A path with the folder ("/site/fr/trend") would get it a second time from
     * redirect()->to(), url() and every other helper that starts a path at the site's root (the 1.2.3 RC2 sign-in round
     * trip: /site/site/login), a path without it would leave the folder in a link. One rule for both: the address works
     * as a link, a form's action, a redirect and an e-mail link, and reads back the same (R2R-05). At a domain's root the
     * path, as up to 1.2.3 RC2.
     */
    function locale_url(string $path = '/'): string
    {
        $locale = app()->getLocale();
        $defaultLocale = site_default_locale();
        $parts = locale_path_parts($path);
        $page = $parts['path'] === '/' ? '' : $parts['path'];
        $prefix = ($parts['index_php'] || index_php_links()) ? '/index.php' : '';

        if ($locale === $defaultLocale) {
            $url = $page === '' ? ($prefix ?: '/') : $prefix . $page;
        } else {
            $url = $prefix . '/' . $locale . $page;
        }

        $root = site_root();

        if (trim((string) parse_url($root, PHP_URL_PATH), '/') !== '') {
            $url = $root . $url;
        }

        if ($parts['query'] !== null && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        if ($parts['fragment'] !== null && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}

if (!function_exists('locale_switch_url')) {
    /**
     * The language switch's address for $targetLocale, coming back to $path (default: this page) without its language,
     * read with the same rule as locale_url() (locale_path_parts()); the switch itself is never the page to come back to.
     * On a site installed in a folder the switch's own address is a whole one (as locale_url() writes it) and the page
     * to come back to is the path as the browser has it, with the folder (App\Http\Controllers\LangController reads it so).
     */
    function locale_switch_url(string $targetLocale, ?string $path = null): string
    {
        $languages = config('languages', []);
        $defaultLocale = site_default_locale();

        if (!isset($languages[$targetLocale])) {
            $targetLocale = $defaultLocale;
        }

        $parts = locale_path_parts($path ?? site_path((string) ($_SERVER['REQUEST_URI'] ?? '/')));
        $pathOnly = $parts['path'];
        $query = $parts['query'];

        if ($pathOnly === '/update-locale' || str_starts_with($pathOnly, '/update-locale/')) {
            $pathOnly = '/';
            $query = null;
        }

        $prefix = ($parts['index_php'] || index_php_links()) ? '/index.php' : '';
        $redirect = ($prefix . ($pathOnly === '/' ? ($prefix ? '' : '/') : $pathOnly))
            . ($query !== null && $query !== '' ? '?' . $query : '');

        $endpoint = $prefix . '/update-locale';

        if (site_folder() !== '') {
            $redirect = site_browser_path($redirect ?: '/');
            $endpoint = site_root() . $endpoint;
        }

        return $endpoint . '?locale=' . rawurlencode($targetLocale)
            . '&redirect=' . rawurlencode($redirect ?: '/');
    }
}

if (!function_exists('locale_current_url')) {
    /**
     * This page (the request's own address, with its query) in the current language, as locale_url() writes it: the
     * "Try again" of the error pages, the page a sign-in link comes back to.
     */
    function locale_current_url(?\Illuminate\Http\Request $request = null): string
    {
        $uri = (string) ($request ?? request())->getRequestUri();

        return locale_url(site_path($uri !== '' ? $uri : '/'));
    }
}

if (!function_exists('locale_route')) {
    function locale_route(string $name, array $parameters = []): string
    {
        return locale_url(route($name, $parameters));
    }
}

if (!function_exists('current_locale')) {
    function current_locale(): string
    {
        return app()->getLocale();
    }
}

if (!function_exists('site_default_locale')) {
    /**
     * The site's default language, the one whose pages have no language prefix. Web requests set it from the settings
     * (AppServiceProvider::applyDbSettings(): site.default_locale). A console process (the queue worker, the scheduler's
     * commands: the trial-end and card-expiry mails, the queued phone code) skips those settings, and its app.locale is
     * no answer there: Laravel's setLocale() writes app.locale while each mail is rendered in its member's language, so
     * locale_url() took the mail's own language for the site's and left the prefix out (judge R1-051: /profile/edit
     * instead of /fr/profile/edit). There it is read on the process's first link the way applyDbSettings() reads it (the
     * default of the languages table, else the "Site language" setting, else the configured language) and kept as a
     * value of the process's application (the container's "site_default_locale.console": every test's application starts
     * without it); never in site.default_locale, which the request middleware and the controllers read as "the web's
     * setting, else app.locale", nor elsewhere in the site.* config, which mirrors the admin settings.
     *
     * It only READS the caches the web fills (the settings, the languages, "has a languages table"), else asks the
     * tables: a console process may run as another system user (a cron job of root) and a cache file it wrote could not
     * be replaced or forgotten by the web afterwards, and a process that starts during an install must not leave an
     * empty answer in the cache.
     */
    function site_default_locale(): string
    {
        $configured = config('site.default_locale');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $console = app()->bound('site_default_locale.console') ? app('site_default_locale.console') : null;

        if (is_string($console) && $console !== '') {
            return $console;
        }

        $cached = static fn (string $key): mixed => rescue(fn () => \Illuminate\Support\Facades\Cache::get($key), null, false);
        $default = null;

        try {
            // the "Site language" setting: Setting::getAllGrouped()'s cache, else its row
            $grouped = $cached('settings.__all_grouped');
            $setting = is_array($grouped)
                ? ($grouped['localization']['default_locale'] ?? null)
                : \App\Models\Setting::query()->where('group', 'localization')->where('key', 'default_locale')->value('value');

            if (is_string($setting) && $setting !== '') {
                $default = $setting;
            }
        } catch (\Throwable) {
            // no settings table yet (the installer, a first migration)
        }

        try {
            // the languages table's default wins, as on the web: Language::getAllCached()'s cache, else the table (the
            // active languages in their order, as getAllCached() lists them)
            $code = null;
            $languages = $cached('languages_all_collection');

            if ($languages instanceof \Illuminate\Support\Collection) {
                $code = $languages->firstWhere('is_default', true)?->code;
            } elseif ($cached('schema.has_languages_table') ?? \Illuminate\Support\Facades\Schema::hasTable('languages')) {
                $code = \App\Models\Language::query()->active()->ordered()->where('is_default', true)->value('code');
            }

            if (is_string($code) && $code !== '') {
                $default = $code;
            }
        } catch (\Throwable) {
            // no languages table yet
        }

        // app.fallback_locale: the configured language (setLocale() never changes it)
        $default ??= (string) config('app.fallback_locale', 'en');
        app()->instance('site_default_locale.console', $default);

        return $default;
    }
}

if (!function_exists('site_asset_url')) {
    function site_asset_url(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^(?:https?:)?//#i', $value)) {
            return $value;
        }

        return asset(ltrim($value, '/'));
    }
}

if (!function_exists('decode_html_entities')) {
    function decode_html_entities(?string $value): string
    {
        $decoded = (string) $value;

        do {
            $previous = $decoded;
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } while ($decoded !== $previous);

        return $decoded;
    }
}
