<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SafeCache
{
    public static function remember(string $key, mixed $ttl, Closure $resolver): mixed
    {
        self::ensureFileStoreDirectory();

        try {
            return Cache::remember($key, $ttl, $resolver);
        } catch (Throwable) {
            return $resolver();
        }
    }

    public static function rememberForever(string $key, Closure $resolver): mixed
    {
        self::ensureFileStoreDirectory();

        try {
            return Cache::rememberForever($key, $resolver);
        } catch (Throwable) {
            return $resolver();
        }
    }

    private static function ensureFileStoreDirectory(): void
    {
        if ((string) config('cache.default') !== 'file') {
            return;
        }

        $path = config('cache.stores.file.path');
        if (! is_string($path) || $path === '' || is_dir($path)) {
            return;
        }

        @mkdir($path, 0775, true);
    }
}
