<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

class CorsConfig
{
    public static function applyFromSettings(array $api): void
    {
        $supportsCredentials = self::enabled($api['cors_supports_credentials'] ?? config('cors.supports_credentials', true));

        Config::set('cors.supports_credentials', $supportsCredentials);

        if (self::hasValue($api, 'cors_allowed_origins')) {
            Config::set('cors.allowed_origins', self::origins((string) $api['cors_allowed_origins'], $supportsCredentials));
        } elseif ($supportsCredentials && self::containsWildcard((array) config('cors.allowed_origins', []))) {
            Config::set('cors.allowed_origins', self::defaultCredentialedOrigins());
        }

        if (self::hasValue($api, 'cors_allowed_methods')) {
            $methods = self::tokens((string) $api['cors_allowed_methods']);
            Config::set('cors.allowed_methods', in_array('*', $methods, true) ? ['*'] : $methods);
        }

        if (self::hasValue($api, 'cors_max_age')) {
            Config::set('cors.max_age', (int) $api['cors_max_age']);
        }
    }

    public static function origins(string $value, bool $supportsCredentials): array
    {
        $origins = self::tokens($value);

        if ($origins === []) {
            return [];
        }

        if (in_array('*', $origins, true)) {
            return $supportsCredentials ? self::defaultCredentialedOrigins() : ['*'];
        }

        return $origins;
    }

    public static function defaultCredentialedOrigins(): array
    {
        $origin = self::originFromUrl((string) config('app.url'));

        return $origin ? [$origin] : [];
    }

    private static function tokens(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', $value) ?: [])
        )));
    }

    private static function containsWildcard(array $origins): bool
    {
        return in_array('*', $origins, true);
    }

    private static function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    private static function hasValue(array $settings, string $key): bool
    {
        return array_key_exists($key, $settings)
            && $settings[$key] !== null
            && trim((string) $settings[$key]) !== '';
    }

    private static function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (!$scheme || !$host) {
            return null;
        }

        $origin = $scheme.'://'.$host;

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
