<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SafeCache
{
    /** readOnly() scopes open now: the remember methods then only read. */
    private static int $readOnly = 0;

    public static function remember(string $key, mixed $ttl, Closure $resolver): mixed
    {
        if (self::$readOnly > 0) {
            return self::readWithoutWriting($key, $resolver);
        }

        self::ensureFileStoreDirectory();

        try {
            return Cache::remember($key, $ttl, $resolver);
        } catch (Throwable) {
            return $resolver();
        }
    }

    public static function rememberForever(string $key, Closure $resolver): mixed
    {
        if (self::$readOnly > 0) {
            return self::readWithoutWriting($key, $resolver);
        }

        self::ensureFileStoreDirectory();

        try {
            return Cache::rememberForever($key, $resolver);
        } catch (Throwable) {
            return $resolver();
        }
    }

    /**
     * Run $callback with the cache read but never written through this class (and App\Support\AdminCache, which uses
     * it): a value the cache has is used, a missing one is worked out and returned without being stored, and no cache
     * folder is made. What a console process does while it applies the admin's settings (App\Support\ConsoleSiteSettings):
     * a cron job may run as another system user (root), and a cache file it wrote could not be replaced or forgotten by
     * the web afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function readOnly(Closure $callback): mixed
    {
        self::$readOnly++;

        try {
            return $callback();
        } finally {
            self::$readOnly--;
        }
    }

    /** Whether a readOnly() scope is open now: whoever would write the cache directly leaves it alone then. */
    public static function readingOnly(): bool
    {
        return self::$readOnly > 0;
    }

    /**
     * Cache::get() that never fails the caller: null when the store is down or has no value.
     */
    public static function get(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cache::put() that never fails the caller and writes nothing inside readOnly() or from a console process of another
     * system user than the site's (writesAsTheSiteUser()): such a process leaves no cache file behind.
     */
    public static function put(string $key, mixed $value, mixed $ttl): void
    {
        if (self::$readOnly > 0 || ! self::writesAsTheSiteUser()) {
            return;
        }

        self::ensureFileStoreDirectory();

        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable) {
            // nothing is kept; the next read works it out again
        }
    }

    /**
     * Cache::forget() that never fails the caller (a model event, a delete): with a store that is down, remember() above
     * already serves every read from its resolver.
     */
    public static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // nothing to undo
        }
    }

    /**
     * Whether put() may write the file store from this process: always, except a console process of another system
     * user than the one the site's storage folder belongs to (a cron job of root on a panel that runs cron as root):
     * a cache file or folder it made could not be replaced or forgotten by the web afterwards (the same reason as
     * readOnly()). Such a process works its values out and keeps them in memory only (App\Support\PhpBinary).
     */
    private static function writesAsTheSiteUser(): bool
    {
        if ((string) config('cache.default') !== 'file' || ! function_exists('posix_geteuid') || ! app()->runningInConsole()) {
            return true;
        }

        $owner = @fileowner(storage_path('framework'));

        return $owner === false || $owner === posix_geteuid();
    }

    private static function readWithoutWriting(string $key, Closure $resolver): mixed
    {
        try {
            $value = Cache::get($key);
        } catch (Throwable) {
            $value = null;
        }

        // Like remember(): a null is never kept, so it is worked out again.
        return $value !== null ? $value : $resolver();
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
