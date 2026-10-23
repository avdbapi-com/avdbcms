<?php

if (!function_exists('locale_url')) {
    function locale_url(string $path = '/'): string
    {
        $locale = app()->getLocale();
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        $parsed = parse_url($path);

        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $path = $parsed['path'] ?? '/';

            if (!empty($parsed['query'])) {
                $path .= '?' . $parsed['query'];
            }

            if (!empty($parsed['fragment'])) {
                $path .= '#' . $parsed['fragment'];
            }
        }

        $path = '/' . ltrim($path, '/');
        $hasIndexPhp = str_starts_with($path, '/index.php');
        if ($hasIndexPhp) {
            $path = substr($path, 10);
            if ($path === '' || !str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
        }

        $languages = config('languages', []);
        $segments = explode('/', ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/'));

        if (!empty($segments[0]) && isset($languages[$segments[0]])) {
            array_shift($segments);
            $pathOnly = '/' . implode('/', $segments);
            $query = parse_url($path, PHP_URL_QUERY);
            $fragment = parse_url($path, PHP_URL_FRAGMENT);
            $path = $pathOnly === '/' ? '/' : rtrim($pathOnly, '/');

            if ($query) {
                $path .= '?' . $query;
            }

            if ($fragment) {
                $path .= '#' . $fragment;
            }
        }

        if ($path === '/') {
            $path = '';
        }

        $needsFallback = (bool) config('app.clean_url_fallback', false)
            || !empty($_SERVER['AVDBCLIP_USED_INDEX_PHP'])
            || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/index.php');
        $prefix = ($hasIndexPhp || $needsFallback) ? '/index.php' : '';

        if ($locale === $defaultLocale) {
            if ($path === '') {
                return $prefix ?: '/';
            }
            return $prefix . $path;
        }

        return $prefix . '/' . $locale . ($path ?: '');
    }
}

if (!function_exists('locale_switch_url')) {
    function locale_switch_url(string $targetLocale, ?string $path = null): string
    {
        $languages = config('languages', []);
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));

        if (!isset($languages[$targetLocale])) {
            $targetLocale = $defaultLocale;
        }

        $path = $path ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $parsed = parse_url($path);

        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $path = $parsed['path'] ?? '/';

            if (!empty($parsed['query'])) {
                $path .= '?' . $parsed['query'];
            }
        }

        $hasIndexPhp = str_starts_with($path, '/index.php');
        if ($hasIndexPhp) {
            $path = substr($path, 10);
            if ($path === '' || !str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
        }

        $pathOnly = parse_url($path, PHP_URL_PATH) ?: '/';
        $query = parse_url($path, PHP_URL_QUERY);
        $pathOnly = '/' . ltrim($pathOnly, '/');

        $segments = explode('/', ltrim($pathOnly, '/'));
        if (!empty($segments[0]) && isset($languages[$segments[0]])) {
            array_shift($segments);
            $pathOnly = '/' . implode('/', $segments);
        }

        if ($pathOnly === '/update-locale' || str_starts_with($pathOnly, '/update-locale/')) {
            $pathOnly = '/';
            $query = null;
        }

        $pathOnly = $pathOnly === '/' ? '/' : rtrim($pathOnly, '/');
        $needsFallback = (bool) config('app.clean_url_fallback', false)
            || !empty($_SERVER['AVDBCLIP_USED_INDEX_PHP'])
            || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/index.php');
        $prefix = ($hasIndexPhp || $needsFallback) ? '/index.php' : '';
        $redirect = ($prefix . ($pathOnly === '/' ? ($prefix ? '' : '/') : $pathOnly)) . ($query ? '?' . $query : '');

        $endpoint = $prefix . '/update-locale';
        return $endpoint . '?locale=' . rawurlencode($targetLocale)
            . '&redirect=' . rawurlencode($redirect ?: '/');
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
    function site_default_locale(): string
    {
        return config('site.default_locale', config('app.locale', 'en'));
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
