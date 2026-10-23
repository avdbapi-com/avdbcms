<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Database changes a newly uploaded version still waits for, and the one-click "Finish update" on Home that applies
 * them. Uploading a release over an older install leaves its migrations pending; the site keeps working (new code
 * checks for new tables and columns) until an administrator finishes the update.
 */
final class PendingMigrations
{
    public const CACHE_KEY = 'admin.pending_migrations';

    public const LOCK = 'avd-finish-update';

    private const CACHE_SECONDS = 600;

    private const LOCK_SECONDS = 300;

    /**
     * Names of the migration files that have not run yet. The answer is cached for 10 minutes per set of migration
     * files, so uploading a version with new migrations shows them at once; an answer that could not be found out
     * (database down) is not cached and counts as nothing pending.
     *
     * @return array<int, string>
     */
    public static function pending(): array
    {
        $files = self::files();

        if ($files === []) {
            return [];
        }

        // Per database too: a copy of the site (staging, a restored backup elsewhere) may share this cache.
        $key = self::CACHE_KEY.'.'.md5(self::database().'|'.implode(',', array_keys($files)));

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $pending = self::find($files);

        if ($pending !== null) {
            try {
                Cache::put($key, $pending, self::CACHE_SECONDS);
                Cache::put(self::CACHE_KEY, $key, self::CACHE_SECONDS);
            } catch (Throwable) {
            }
        }

        return $pending ?? [];
    }

    public static function count(): int
    {
        return count(self::pending());
    }

    public static function forget(): void
    {
        try {
            Cache::forget((string) Cache::get(self::CACHE_KEY, ''));
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * Run every pending migration (under a lock, so two clicks or two administrators never migrate at once), then clear
     * the caches the new version must not read stale. Safe to call again: with nothing pending it changes nothing.
     *
     * @return array{ok: bool, busy: bool, ran: array<int, string>, message: string}
     */
    public static function run(): array
    {
        $before = self::find(self::files()) ?? [];

        if ($before === []) {
            self::forget();

            return ['ok' => true, 'busy' => false, 'ran' => [], 'message' => __('Your site is up to date.')];
        }

        $lock = self::acquire();

        if ($lock === false) {
            return ['ok' => false, 'busy' => true, 'ran' => [], 'message' => __('The update is already being finished. Wait a minute, then reload this page.')];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::LOCK_SECONDS);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $failure = null;
        } catch (Throwable $e) {
            report($e);
            $failure = $e;
        } finally {
            self::release($lock);
        }

        self::clearCaches();
        $ran = array_values(array_diff($before, self::find(self::files()) ?? $before));

        if ($failure !== null) {
            return [
                'ok' => false,
                'busy' => false,
                'ran' => $ran,
                'message' => __('The update could not finish: :reason Your site keeps working. Try again, or ask your host to run "php artisan migrate".', [
                    'reason' => Str::limit(trim(class_basename($failure).': '.$failure->getMessage()), 180),
                ]),
            ];
        }

        return ['ok' => true, 'busy' => false, 'ran' => $ran, 'message' => __('The update is finished.')];
    }

    private static function database(): string
    {
        try {
            $connection = DB::connection();

            return $connection->getName().'/'.$connection->getDatabaseName();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The migration files of this version: name => path (database/migrations and every path a package registered).
     *
     * @return array<string, string>
     */
    private static function files(): array
    {
        try {
            $migrator = app('migrator');

            return $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The files of $files that have not run, or null when the database cannot tell.
     *
     * @param  array<string, string>  $files
     * @return array<int, string>|null
     */
    private static function find(array $files): ?array
    {
        try {
            $repository = app('migrator')->getRepository();

            // No migrations table: not an installed site (the installer migrates), nothing to finish here.
            if (! $repository->repositoryExists()) {
                return [];
            }

            return array_values(array_diff(array_keys($files), $repository->getRan()));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The cache store's lock, or a file lock when the store cannot lock (e.g. the "null" store).
     *
     * @return Lock|resource|false
     */
    private static function acquire(): mixed
    {
        try {
            $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

            return $lock->get() ? $lock : false;
        } catch (Throwable) {
            $handle = @fopen(storage_path('framework/'.self::LOCK.'.lock'), 'c');

            if ($handle === false) {
                return false;
            }

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                return false;
            }

            return $handle;
        }
    }

    private static function release(mixed $lock): void
    {
        try {
            if ($lock instanceof Lock) {
                $lock->release();
            } elseif (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable) {
        }
    }

    private static function clearCaches(): void
    {
        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (Throwable) {
            }
        }

        try {
            Setting::clearCache();
        } catch (Throwable) {
        }

        try {
            AdminCache::bumpVersion();
        } catch (Throwable) {
        }

        self::forget();
    }
}
