<?php

namespace App\Support;

use Dotenv\Dotenv;

class RuntimeEnv
{
    /**
     * Read a value from the process environment, then from the project .env file.
     * This keeps admin/runtime diagnostics usable after `config:cache`.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        $values = self::all();

        return array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== ''
            ? $values[$key]
            : $default;
    }

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        static $values = null;

        if (is_array($values)) {
            return $values;
        }

        $path = base_path('.env');
        if (! is_file($path) || ! is_readable($path)) {
            return $values = [];
        }

        try {
            $content = file_get_contents($path);

            return $values = is_string($content) ? Dotenv::parse($content) : [];
        } catch (\Throwable) {
            return $values = [];
        }
    }
}
