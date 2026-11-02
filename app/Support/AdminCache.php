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

    /**
     * The admin's cached lists show e-mail addresses and the like to administrators, but no cache file holds them: the
     * cache keeps a copy whose users rows have only their public columns (App\Support\CachedUsers::forAdminCache()), and
     * a list read from the cache gets the rest of each row back from the database in one query (restoreAdminRows()).
     * The request that computes a list prints its own, whole value.
     */
    public static function remember(string $key, DateTimeInterface|int $ttl, Closure $callback): mixed
    {
        $computed = false;
        $value = null;
        $cached = SafeCache::remember(self::buildKey($key), $ttl, static function () use ($callback, &$computed, &$value): mixed {
            $computed = true;
            $value = $callback();

            return CachedUsers::forAdminCache($value);
        });

        return $computed ? $value : CachedUsers::restoreAdminRows($cached);
    }

    public static function rememberForever(string $key, Closure $callback): mixed
    {
        $computed = false;
        $value = null;
        $cached = SafeCache::rememberForever(self::buildKey($key), static function () use ($callback, &$computed, &$value): mixed {
            $computed = true;
            $value = $callback();

            return CachedUsers::forAdminCache($value);
        });

        return $computed ? $value : CachedUsers::restoreAdminRows($cached);
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
