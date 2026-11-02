<?php

namespace App\Support;

use Throwable;

/**
 * A value as the configuration FILES give it (config/*.php with the .env values), before the admin's settings replace
 * it at boot (AppServiceProvider::applySettings()): what a setting falls back to when the admin's value is empty.
 *
 * Settings > Site basics showed "Default: <the saved name>" under the Site name (R2A-055), and every setting whose
 * default is a configuration value (time zone, the mail server, the plan limits) did the same: config() holds the
 * admin's value at that point, so "Reset" changed nothing. With the configuration cached, the cached file holds the
 * files' values (Tools > Commands caches no setting since R1BR-01); else the file is read.
 */
final class ConfigFiles
{
    /** @var array<string, mixed> config file name => its array (read once per request) */
    private static array $files = [];

    /** @var array<string, mixed>|null the cached configuration, read once per request */
    private static ?array $cached = null;

    public static function value(string $key, mixed $default = null): mixed
    {
        [$file, $path] = array_pad(explode('.', $key, 2), 2, null);

        try {
            $values = self::file((string) $file);
        } catch (Throwable) {
            return config($key, $default);
        }

        if (! is_array($values)) {
            return config($key, $default);
        }

        return $path === null ? $values : data_get($values, $path, $default);
    }

    /** Forget what was read (the tests change the files' values). */
    public static function flush(): void
    {
        self::$files = [];
        self::$cached = null;
    }

    private static function file(string $file): mixed
    {
        if ($file === '' || preg_match('/^[a-z0-9_-]+$/i', $file) !== 1) {
            return null;
        }

        $app = app();

        if ($app->configurationIsCached()) {
            self::$cached ??= (array) require $app->getCachedConfigPath();

            return self::$cached[$file] ?? null;
        }

        if (! array_key_exists($file, self::$files)) {
            $path = $app->configPath($file.'.php');
            self::$files[$file] = is_file($path) ? require $path : null;
        }

        return self::$files[$file];
    }
}
