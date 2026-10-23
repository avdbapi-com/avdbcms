<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

final class AdminCache
{
    private const string VERSION_KEY = 'admin.dashboard.cache_version';

    public static function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, '0');
    }

    public static function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, (string) now()->getTimestamp());
    }

    public static function remember(string $key, DateTimeInterface|int $ttl, Closure $callback): mixed
    {
        return SafeCache::remember(self::buildKey($key), $ttl, $callback);
    }

    public static function rememberForever(string $key, Closure $callback): mixed
    {
        return SafeCache::rememberForever(self::buildKey($key), $callback);
    }

    public static function forget(string $key): void
    {
        Cache::forget(self::buildKey($key));
    }

    public static function fingerprintQuery(array $query): string
    {
        ksort($query);
        return sha1(http_build_query($query));
    }

    public static function buildKey(string $key): string
    {
        return 'admin.cache.' . self::version() . '.' . $key;
    }
}
