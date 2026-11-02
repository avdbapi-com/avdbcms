<?php

namespace App\Notifications\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The notification indexes of 1.2.3, built where they can never hold up an update (judge R1-008; measured on a table of
 * 3,800,155 notifications on MariaDB 10.11 and MySQL 8.0):
 *
 *  - notifications_unread_created_id_idx (notifiable_type, notifiable_id, read_at, created_at, id): a member's unread
 *    notifications in list order, so a page of the notifications page's Unread tab is one short range read at any depth
 *    (0.5-1.6 ms, where RC1 took 9-61 s on MariaDB 10.11);
 *  - notifications_created_id_idx and notifications_read_created_id_idx (migration 2026_10_25_120000): the admin's list
 *    and the daily tidy-up.
 *  - "Finish update" builds them at once on a table of up to $buildNowRows rows (0.5 s for all three on 100,000 rows
 *    here). A bigger table gets them from the scheduler (notifications:index, every minute while one is missing, in the
 *    background), never inside the update's request: building them reads the whole table (23 s for the two and 25-35 s
 *    for the unread list index on 3,800,155 rows; more on a slow disk) and Finish runs under the host's 30-60 s limit.
 *    Until they exist the site works without them (UnreadKeys reads the Unread tab by the member's own indexes; the admin
 *    list and the tidy-up check for theirs).
 *  - On MySQL / MariaDB they are built online (ALGORITHM=INPLACE, LOCK=NONE: the site reads and writes the table while it
 *    runs; 0 errors in 370 writes during a 25-35 s build), waiting at most $lockWait seconds for the table's metadata
 *    lock (a long transaction on the table makes the build give up and try again later, instead of making every query on
 *    the table wait behind it: MySQL's own wait is a year). A server that cannot build them online builds them the plain
 *    way only on a small table; on a big one they stay out (the site works without them).
 *  - One builder at a time (a cache lock); after a failure the next try waits 1, 2, 4 ... up to 60 minutes.
 */
final class NotificationIndexes
{
    public const UNREAD_LIST = 'notifications_unread_created_id_idx';

    /** The migration of the unread list index (the newest one that asks for an index here). */
    public const MIGRATION = '2026_10_27_120000_add_notifications_unread_list_index';

    /** The migration of the admin list's two indexes. */
    public const LIST_MIGRATION = '2026_10_25_120000_add_notification_tasks_and_list_indexes';

    /**
     * The indexes this class builds: name => its columns and the migration that asks for it (the scheduler builds an
     * index only after "Finish update" ran its migration).
     *
     * @var array<string, array{columns: list<string>, migration: string}>
     */
    public const WANTED = [
        self::UNREAD_LIST => ['columns' => ['notifiable_type', 'notifiable_id', 'read_at', 'created_at', 'id'], 'migration' => self::MIGRATION],
        'notifications_created_id_idx' => ['columns' => ['created_at', 'id'], 'migration' => self::LIST_MIGRATION],
        'notifications_read_created_id_idx' => ['columns' => ['read_at', 'created_at', 'id'], 'migration' => self::LIST_MIGRATION],
    ];

    /** Built inside "Finish update" only on a table of at most this many rows; a bigger one gets them from the scheduler. */
    public static int $buildNowRows = 100000;

    /** The longest the online build waits for the table's metadata lock (seconds). */
    public static int $lockWait = 5;

    private const STATE_KEY = 'notifications.indexes.state.v1';

    private const PROBE_KEY = 'notifications.indexes.probe.v1';

    private const LOCK_KEY = 'notifications.indexes.build';

    private const PROBE_SECONDS = 60;

    /** @var array<string, bool> this process's answers (kept PROBE_SECONDS: a queue worker or daemon lives long) */
    private static array $known = [];

    private static float $knownSince = 0.0;

    /**
     * Whether the index exists. Once it was seen (or built here) that is kept in the cache, so a page asks the database
     * at most once a minute while it is missing and never again once it is there.
     */
    public static function has(string $name = self::UNREAD_LIST): bool
    {
        if (microtime(true) - self::$knownSince > self::PROBE_SECONDS) {
            self::$known = [];
            self::$knownSince = microtime(true);
        }

        if (array_key_exists($name, self::$known)) {
            return self::$known[$name];
        }

        if (in_array($name, self::state()['built'], true)) {
            return self::$known[$name] = true;
        }

        try {
            $probe = Cache::get(self::PROBE_KEY);
        } catch (Throwable) {
            $probe = null;
        }

        if (is_array($probe) && array_key_exists($name, $probe)) {
            return self::$known[$name] = (bool) $probe[$name];
        }

        $exists = self::exists($name);
        self::$known[$name] = $exists;
        rescue(fn () => Cache::put(self::PROBE_KEY, array_merge(is_array($probe) ? $probe : [], [$name => $exists]), self::PROBE_SECONDS), null, false);

        if ($exists) {
            self::remember($name);
        }

        return $exists;
    }

    /**
     * The wanted indexes that are not there yet (name => columns), for a table that has their columns; only $names when
     * given.
     *
     * @param  list<string>|null  $names
     * @return array<string, list<string>>
     */
    public static function missing(?array $names = null): array
    {
        try {
            if (! Schema::hasTable('notifications')) {
                return [];
            }

            $missing = [];

            foreach (self::WANTED as $name => $wanted) {
                if ($names !== null && ! in_array($name, $names, true)) {
                    continue;
                }

                if (self::exists($name)) {
                    self::remember($name);
                } elseif (Schema::hasColumns('notifications', $wanted['columns'])) {
                    $missing[$name] = $wanted['columns'];
                }
            }

            return $missing;
        } catch (Throwable) {
            return [];
        }
    }

    /** Whether the notifications table is small enough to build the indexes inside "Finish update" (a capped count). */
    public static function smallEnough(): bool
    {
        try {
            $rows = DB::table('notifications')->select('id')->limit(self::$buildNowRows + 1);

            return (int) DB::query()->fromSub($rows, 'counted')->count() <= self::$buildNowRows;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the scheduler should start notifications:index (cheap: the cache; while something is missing, the
     * migrations table and the table's indexes): an index is missing whose migration "Finish update" ran, and no pause
     * after a failure is running.
     */
    public static function due(): bool
    {
        $state = self::state();

        if (count(array_intersect(array_keys(self::WANTED), $state['built'])) === count(self::WANTED) || $state['next_try'] > time()) {
            return false;
        }

        return self::missing(self::asked()) !== [];
    }

    /**
     * Builds the missing indexes (all in one pass over the table): $names, or every wanted one whose migration ran.
     * $plainOnBigTable: a server that cannot build them online builds them the plain way (writes wait while it runs) also
     * on a big table; "Finish update" asks that only for a small one.
     *
     * @param  list<string>|null  $names
     * @return array{built: list<string>, skipped: ?string, seconds: float}
     */
    public static function build(?array $names = null, bool $plainOnBigTable = false): array
    {
        $started = microtime(true);
        $missing = self::missing($names ?? self::asked());

        if ($missing === []) {
            return ['built' => [], 'skipped' => null, 'seconds' => 0.0];
        }

        $lock = null;

        try {
            $lock = Cache::lock(self::LOCK_KEY, 3600);

            if (! $lock->get()) {
                return ['built' => [], 'skipped' => 'another build is running', 'seconds' => 0.0];
            }
        } catch (Throwable) {
            $lock = null; // a store without locks: the scheduler's withoutOverlapping() still keeps one run at a time
        }

        try {
            self::create($missing, $plainOnBigTable);
            self::forget();
            $built = array_values(array_filter(array_keys($missing), fn (string $name): bool => self::exists($name)));

            foreach ($built as $name) {
                self::remember($name);
            }

            self::saveState(['failures' => 0, 'next_try' => 0]);

            return ['built' => $built, 'skipped' => null, 'seconds' => round(microtime(true) - $started, 3)];
        } catch (Throwable $e) {
            $failures = self::state()['failures'] + 1;
            self::saveState(['failures' => $failures, 'next_try' => time() + 60 * min(60, 2 ** min(6, $failures - 1))]);
            Log::warning('Notifications: an index could not be built yet; the scheduler tries again later.', [
                'indexes' => array_keys($missing),
                'failures' => $failures,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['built' => [], 'skipped' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300), 'seconds' => round(microtime(true) - $started, 3)];
        } finally {
            rescue(fn () => $lock?->release(), null, false);
        }
    }

    /**
     * When the next try is due after a failure (null: no pause runs), in the application's time zone like now() and the
     * lines the scheduler writes around it (Carbon 3's createFromTimestamp() gives UTC: hours off on a site in another
     * zone, the next try even "before" the line's own time east of Greenwich).
     */
    public static function nextTry(): ?\Illuminate\Support\Carbon
    {
        $next = self::state()['next_try'];

        return $next > time() ? now()->setTimestamp($next) : null;
    }

    /** Removes these indexes (a migration's down()). */
    public static function drop(array $names): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        foreach ($names as $name) {
            if (self::exists($name)) {
                Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }

        self::forget();
        $state = self::state();
        self::saveState(['built' => array_values(array_diff($state['built'], $names)), 'failures' => 0, 'next_try' => 0]);
    }

    /** Forgets what this process and the cache know about the indexes (they are looked up again). */
    public static function forget(): void
    {
        self::$known = [];
        self::$knownSince = microtime(true);
        rescue(fn () => Cache::forget(self::PROBE_KEY), null, false);
    }

    /**
     * A statement that named an index was refused: an index seen once is gone (dropped by hand, a restored backup).
     * Everything known is forgotten, so the pages look again and the scheduler builds what is missing.
     */
    public static function lost(): void
    {
        self::forget();
        self::saveState(['built' => []]);
    }

    /** Whether the database is MySQL or MariaDB (index hints, online builds). */
    public static function mysql(): bool
    {
        try {
            return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The wanted indexes whose migration "Finish update" ran.
     *
     * @return list<string>
     */
    private static function asked(): array
    {
        try {
            $ran = array_flip(DB::table('migrations')->whereIn('migration', array_unique(array_column(self::WANTED, 'migration')))->pluck('migration')->all());
        } catch (Throwable) {
            return [];
        }

        return array_keys(array_filter(self::WANTED, fn (array $wanted): bool => isset($ran[$wanted['migration']])));
    }

    /**
     * @param  array<string, list<string>>  $missing
     */
    private static function create(array $missing, bool $plainOnBigTable): void
    {
        if (! self::mysql()) {
            Schema::table('notifications', function (Blueprint $table) use ($missing): void {
                foreach ($missing as $name => $columns) {
                    $table->index($columns, $name);
                }
            });

            return;
        }

        $connection = DB::connection();
        $table = $connection->getTablePrefix().'notifications';
        $adds = [];

        foreach ($missing as $name => $columns) {
            $adds[] = 'ADD INDEX `'.$name.'` (`'.implode('`, `', $columns).'`)';
        }

        $previous = null;

        try {
            $previous = (int) ($connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS wait')->wait ?? 0);
            $connection->statement('SET SESSION lock_wait_timeout = '.max(1, self::$lockWait));
        } catch (Throwable) {
            $previous = null;
        }

        try {
            try {
                // one pass over the table for all of them, without blocking reads or writes
                $connection->statement('ALTER TABLE `'.$table.'` '.implode(', ', $adds).', ALGORITHM=INPLACE, LOCK=NONE');
            } catch (Throwable $e) {
                if (! self::onlineRefused($e)) {
                    throw $e;
                }

                // a server (or table engine) that cannot build an index online: the plain way, only where that is short
                if (! $plainOnBigTable && ! self::smallEnough()) {
                    throw $e;
                }

                $connection->statement('ALTER TABLE `'.$table.'` '.implode(', ', $adds));
            }
        } finally {
            if ($previous !== null && $previous > 0) {
                rescue(fn () => $connection->statement('SET SESSION lock_wait_timeout = '.$previous), null, false);
            }
        }
    }

    /** The server cannot build it online (ER_ALTER_OPERATION_NOT_SUPPORTED / _REASON: 1845, 1846). */
    private static function onlineRefused(Throwable $e): bool
    {
        $code = (int) (($e->errorInfo[1] ?? null) ?? 0);

        return in_array($code, [1845, 1846], true)
            || preg_match('/ALGORITHM=INPLACE is not supported|LOCK=NONE is not supported|is not supported for this operation/i', $e->getMessage()) === 1;
    }

    private static function exists(string $name): bool
    {
        try {
            return Schema::hasIndex('notifications', $name);
        } catch (Throwable) {
            return false;
        }
    }

    private static function remember(string $name): void
    {
        $state = self::state();

        if (! in_array($name, $state['built'], true)) {
            self::saveState(['built' => array_values(array_unique(array_merge($state['built'], [$name])))]);
        }
    }

    /**
     * @return array{built: list<string>, failures: int, next_try: int}
     */
    private static function state(): array
    {
        try {
            $state = Cache::get(self::STATE_KEY);
        } catch (Throwable) {
            $state = null;
        }

        $state = is_array($state) ? $state : [];

        return [
            'built' => array_values(array_filter((array) ($state['built'] ?? []), 'is_string')),
            'failures' => max(0, (int) ($state['failures'] ?? 0)),
            'next_try' => max(0, (int) ($state['next_try'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function saveState(array $changes): void
    {
        rescue(fn () => Cache::forever(self::STATE_KEY, array_merge(self::state(), $changes)), null, false);
    }
}
