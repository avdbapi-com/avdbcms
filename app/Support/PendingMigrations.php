<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Database changes a newly uploaded version still waits for, and the one-click "Finish update" on Home that applies
 * them. Uploading a release over an older install leaves its migrations pending; the site keeps working (new code
 * checks for new tables and columns) until an administrator finishes the update.
 *
 * run() takes them one at a time, in order, while the request's deadline allows (App\Support\TimeBudget: Finish sets
 * one; the console sets none and runs them all), and says when some are left for the next request ("more"). A host
 * that ends a request half-way (a time limit that cannot be raised, PHP-FPM's request_terminate_timeout) never holds
 * the next request up: the lock (App\Support\FinishLock) is released by the operating system and the database when the
 * request ends, however it ends, and the next request goes on at once. A migration cut off half-way is not recorded as
 * run, so the next request runs it again (every migration is safe to run twice).
 */
final class PendingMigrations
{
    public const CACHE_KEY = 'admin.pending_migrations';

    /** The cache lock of the database step (FinishLock::NAME, the same name as 1.2.2's). */
    public const LOCK = FinishLock::NAME;

    /**
     * How long the cache lock holds when nobody releases it and no request can tell that its holder is gone (see
     * FinishLock): longer than a request of Finish works (Updater::BUDGET) plus one slow migration.
     */
    public const LOCK_SECONDS = FinishLock::SECONDS;

    /** A new migration starts only with this many seconds left before the deadline (the first one of a request always does). */
    public const RESERVE = 5.0;

    /** PHP's time limit for the database step, where the host lets set_time_limit() raise it. */
    private const TIME_LIMIT = 300;

    private const CACHE_SECONDS = 600;

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
     * Run the pending migrations (under a lock, so two clicks or two administrators never migrate at once), one at a
     * time and in order, then clear the caches the new version must not read stale. With a deadline (TimeBudget) a new
     * migration starts only while RESERVE seconds are left, the first one of the request always: "more" then says that
     * some wait for the next request ("left": their names). Safe to call again: with nothing pending it changes nothing.
     * "took_over": the lock was left by a request the host ended, and this one went on from there.
     *
     * @return array{ok: bool, busy: bool, more: bool, ran: array<int, string>, left: array<int, string>, message: string, took_over: bool}
     */
    public static function run(): array
    {
        $files = self::files();
        $before = self::find($files) ?? [];

        if ($before === []) {
            self::forget();

            return ['ok' => true, 'busy' => false, 'more' => false, 'ran' => [], 'left' => [], 'message' => __('Your site is up to date.'), 'took_over' => false];
        }

        $lock = FinishLock::acquire();

        if ($lock === null) {
            return ['ok' => false, 'busy' => true, 'more' => false, 'ran' => [], 'left' => $before, 'message' => __('The update is already being finished. Wait a minute, then reload this page.'), 'took_over' => false];
        }

        // The lock never depends on this (a process killed from outside runs no PHP at all, see FinishLock); where PHP
        // itself ends the request (its time limit, memory), the shutdown also lets the cache lock go at once.
        $held = new \stdClass();
        $held->lock = $lock;
        register_shutdown_function(static function () use ($held): void {
            if ($held->lock instanceof FinishLock) {
                $held->lock->release();
                $held->lock = null;
            }
        });

        TimeBudget::raise(self::TIME_LIMIT);
        $failure = null;
        $stopped = false;

        try {
            foreach ($before as $i => $name) {
                if ($i > 0 && TimeBudget::left() < self::RESERVE) {
                    $stopped = true;

                    break;
                }

                if (! isset($files[$name])) {
                    continue;
                }

                Artisan::call('migrate', ['--force' => true, '--path' => [$files[$name]], '--realpath' => true]);
            }
        } catch (Throwable $e) {
            report($e);
            $failure = $e;
        } finally {
            $lock->release();
            $held->lock = null;
        }

        self::clearCaches();
        $left = self::find(self::files()) ?? $before;
        $ran = array_values(array_diff($before, $left));
        $left = array_values(array_intersect($before, $left));
        $tookOver = $lock->tookOver();

        if ($failure !== null) {
            return [
                'ok' => false,
                'busy' => false,
                'more' => false,
                'ran' => $ran,
                'left' => $left,
                'message' => __('The update could not finish: :reason Your site keeps working. Try again, or ask your host to run "php artisan migrate".', [
                    'reason' => Str::limit(trim(class_basename($failure).': '.$failure->getMessage()), 180),
                ]),
                'took_over' => $tookOver,
            ];
        }

        // "more" only when the deadline stopped the run: a migration that ran and still counts as pending (one that skips
        // itself, Laravel's shouldRun() false, is never recorded) must not make the page ask again and again.
        if ($stopped && $left !== []) {
            return ['ok' => true, 'busy' => false, 'more' => true, 'ran' => $ran, 'left' => $left,
                'message' => __('Some of the database changes are done. Click Finish update again for the rest.'), 'took_over' => $tookOver];
        }

        return ['ok' => true, 'busy' => false, 'more' => false, 'ran' => $ran, 'left' => $left, 'message' => __('The update is finished.'), 'took_over' => $tookOver];
    }

    /**
     * The names of the migrations that have not run yet, asked now (not the cached answer of pending()), in the order
     * they run; null when the database cannot tell.
     *
     * @return array<int, string>|null
     */
    public static function pendingNow(): ?array
    {
        return self::find(self::files());
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
