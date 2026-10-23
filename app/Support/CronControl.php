<?php

namespace App\Support;

final class CronControl
{
    private const FILE = 'framework/cron-control.json';

    public static function isPaused(string $command): bool
    {
        return in_array(self::key($command), self::paused(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function paused(): array
    {
        $path = self::path();
        if (! is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $data['paused'] ?? []))));
    }

    public static function pause(string $command): void
    {
        $paused = self::paused();
        $paused[] = self::key($command);

        self::write($paused);
    }

    public static function resume(string $command): void
    {
        $key = self::key($command);

        self::write(array_values(array_filter(
            self::paused(),
            static fn (string $paused): bool => $paused !== $key
        )));
    }

    public static function key(string $command): string
    {
        $command = trim($command);
        $command = preg_replace('/^php(?:\.exe)?\s+artisan\s+/i', '', $command) ?? $command;
        $command = str_replace(['"', "'"], '', $command);
        $command = preg_replace('/\s+/', ' ', $command) ?? $command;

        return trim($command);
    }

    /**
     * @param  array<int, string>  $paused
     */
    private static function write(array $paused): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $payload = [
            'paused' => array_values(array_unique(array_filter(array_map([self::class, 'key'], $paused)))),
            'updated_at' => now()->toDateTimeString(),
        ];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function path(): string
    {
        return storage_path(self::FILE);
    }
}
