<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Three indexes of videos that the two install modes made differently (judge R3-009): the bundled database
 * (database/install/example.sql, "Import SQL backup", the default) defined videos_user_active_count_idx as (user_id,
 * deleted_at, status, language) and videos_avdb_category_metric_idx as (avdb_id, deleted_at, category_id), and had no
 * videos_xvid_release_date_index; the migrations ("Run migrations and seeders") make them (user_id, status, deleted_at,
 * uploaded_at, published_at, scheduled_at), (deleted_at, category_id, avdb_id) and (xvid_release_date), the definitions
 * the code is written for (Discover's creator counts name the first, the AVDBAPI and duration pages the second). A
 * migration skips an index whose name exists, so a bundled site kept the other shapes for good.
 *
 * And one index 1.2.3 adds: videos_views_published_id_idx (views, published_at, id), the order of the trending and most
 * viewed lists (PublicVideoFeedService::trending()), so these feeds are read in that order up to their LIMIT instead of
 * sorting every active video for each page (at 300,000 videos a page took about 4 s on MySQL 8.4 and 0.8 s on MariaDB
 * 10.11 without it, 2-8 ms with it). Two more for the cold home page (look-around DB-X2, DB-X1):
 * videos_category_active_count_idx (1.2.0's count index) gets the columns of the language, the creator and the imports
 * after its own, so the side menu's counts are read from the index alone (App\Support\CategoryVideoCounts: at 300,000
 * videos 0.1-0.4 s instead of 0.6-2 s); videos_comments_count_published_id_idx (comments_count, published_at, id), the
 * order of the most commented list (PublicVideoFeedService::mostCommented()), which every site has had since 1.2.0, is
 * kept the same way. And videos_category_published_id_idx (category_id, published_at, id), the order of a category's
 * list (PublicVideoFeedService::categoryId()): its earlier index (category_id, status, published_at, id) gives no order
 * across the two statuses a public video can have, so each page sorted every video of the category (at 300,000 videos
 * 0.1-0.3 s with the rows in memory, 1.5-3.4 s on MySQL 8.4 reading them from disk; a few ms with it). And
 * videos_language_active_idx (language, status, deleted_at, user_id, scheduled_at, published_at, uploaded_at): after the
 * language, every column of the rule a visitor is shown a video by (Video::scopeActive()), in an order whose ranges leave
 * out what nobody is shown (another status, the bin, a creator who is not active, a video scheduled for later), so the
 * public lists count and read a language's videos a visitor can be shown by it alone, whatever the language's hidden
 * videos (PublicVideoFeedService::LANGUAGE_SHOWN_INDEX; the third check's DB-V3-1: a language whose many videos are all in
 * the bin, of a banned creator or scheduled for later made a page read every one of them to show nothing).
 *
 * 1.2.3 (migration 2026_10_29_110000_align_video_index_definitions) gives every site these definitions under the same
 * names:
 *  - only an index that is missing or has other columns is (re)built; a site that has them already does nothing;
 *  - on MySQL / MariaDB online (ALGORITHM=INPLACE, LOCK=NONE: the site keeps reading and writing videos), waiting at most
 *    $lockWait seconds for the table's metadata lock (a long transaction makes it give up and try again later);
 *  - inside "Finish update" only on a table of up to $buildNowRows videos (all of them online in one pass, measured at
 *    300,000 videos: 2.5 s on MariaDB 10.11, 4.1 s on MySQL 8.4); a bigger table gets them from the scheduler
 *    (videos:index, every minute while one is not right, in the background), so the update's request never waits for
 *    a whole-table read; until then the site works with the indexes it has;
 *  - one at a time (a cache lock); after a failure the next try waits 1, 2, 4 ... up to 60 minutes; never an error for
 *    the update: a failure is logged and retried by the scheduler.
 */
final class VideoIndexes
{
    /** name => the columns 1.2.3 wants (the migrations' definitions, the trending order's index, the cold home page's two, a category's list's, a language's videos a visitor can be shown). */
    public const WANTED = [
        'videos_user_active_count_idx' => ['user_id', 'status', 'deleted_at', 'uploaded_at', 'published_at', 'scheduled_at'],
        'videos_avdb_category_metric_idx' => ['deleted_at', 'category_id', 'avdb_id'],
        'videos_xvid_release_date_index' => ['xvid_release_date'],
        'videos_views_published_id_idx' => ['views', 'published_at', 'id'],
        'videos_category_active_count_idx' => ['category_id', 'status', 'deleted_at', 'uploaded_at', 'published_at', 'scheduled_at', 'language', 'user_id', 'avdb_id', 'xvid_id'],
        'videos_comments_count_published_id_idx' => ['comments_count', 'published_at', 'id'],
        'videos_category_published_id_idx' => ['category_id', 'published_at', 'id'],
        'videos_language_active_idx' => ['language', 'status', 'deleted_at', 'user_id', 'scheduled_at', 'published_at', 'uploaded_at'],
    ];

    /** The update step that asks for them (the scheduler acts only after "Finish update" ran it). */
    public const MIGRATION = '2026_10_29_110000_align_video_index_definitions';

    /** Aligned inside "Finish update" only on a table of at most this many videos; a bigger one by the scheduler. */
    public static int $buildNowRows = 200000;

    /** The longest the online build waits for the table's metadata lock (seconds). */
    public static int $lockWait = 5;

    // v3: the second pass of 1.2.3 wanted three more, the fifth one more (a test site an earlier build marked done looks again)
    private const STATE_KEY = 'videos.indexes.state.v3';

    private const LOCK_KEY = 'videos.indexes.align';

    /** @var array<string, list<string>>|null this process's index list of videos (MySQL / MariaDB): name => its columns */
    private static ?array $present = null;

    /**
     * The columns of the videos index $name, in order, on MySQL / MariaDB (the indexes are read once a process: one
     * SHOW INDEX); null when the table has no such index or the database is another one (the public lists name an index
     * on MySQL / MariaDB only).
     *
     * @return list<string>|null
     */
    public static function columns(string $name): ?array
    {
        try {
            if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                return null;
            }

            if (self::$present === null) {
                $present = [];

                foreach (DB::select('SHOW INDEX FROM `'.DB::connection()->getTablePrefix().'videos`') as $row) {
                    $row = array_change_key_case((array) $row, CASE_LOWER);
                    $present[(string) $row['key_name']][(int) $row['seq_in_index']] = (string) $row['column_name'];
                }

                foreach ($present as $index => $columns) {
                    ksort($columns);
                    $present[$index] = array_values($columns);
                }

                self::$present = $present;
            }
        } catch (Throwable) {
            self::$present = [];
        }

        return self::$present[$name] ?? null;
    }

    /** Whether the videos index $name has the columns 1.2.3 wants (columns(): MySQL / MariaDB only). */
    public static function ready(string $name): bool
    {
        return isset(self::WANTED[$name]) && self::columns($name) === self::WANTED[$name];
    }

    /** Forgets this process's index list (after a build; tests). */
    public static function forgetColumns(): void
    {
        self::$present = null;
    }

    /**
     * The wanted indexes that are missing or have other columns, for a table that has their columns:
     * name => ['now' => current columns or null, 'wanted' => columns].
     *
     * @return array<string, array{now: ?list<string>, wanted: list<string>}>
     */
    public static function pending(): array
    {
        try {
            if (! Schema::hasTable('videos')) {
                return [];
            }

            $current = [];

            foreach (Schema::getIndexes('videos') as $index) {
                $current[(string) $index['name']] = array_map('strval', (array) $index['columns']);
            }

            $pending = [];

            foreach (self::WANTED as $name => $columns) {
                $now = $current[$name] ?? null;

                if ($now !== $columns && Schema::hasColumns('videos', $columns)) {
                    $pending[$name] = ['now' => $now, 'wanted' => $columns];
                }
            }

            return $pending;
        } catch (Throwable) {
            return [];
        }
    }

    /** Whether the videos table is small enough to align them inside "Finish update" (a capped count). */
    public static function smallEnough(): bool
    {
        try {
            $rows = DB::table('videos')->select('id')->limit(self::$buildNowRows + 1);

            return (int) DB::query()->fromSub($rows, 'counted')->count() <= self::$buildNowRows;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the scheduler should start videos:index (cheap: the cache; while something is not right yet, the
     * migrations table and the table's indexes): "Finish update" ran the step, an index is not right, no pause runs.
     */
    public static function due(): bool
    {
        $state = self::state();

        if ($state['done'] || $state['next_try'] > time()) {
            return false;
        }

        try {
            if (! DB::table('migrations')->where('migration', self::MIGRATION)->exists()) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        if (self::pending() === []) {
            self::saveState(['done' => true]);

            return false;
        }

        return true;
    }

    /**
     * Builds what pending() lists, in one pass over the table. $plainOnBigTable: a server that cannot build them online
     * builds them the plain way (writes wait while it runs) also on a big table; the update step asks that only for a
     * small one. Never throws.
     *
     * @return array{built: list<string>, skipped: ?string, seconds: float}
     */
    public static function align(bool $plainOnBigTable = false): array
    {
        $started = microtime(true);
        $pending = self::pending();

        if ($pending === []) {
            self::saveState(['done' => true, 'failures' => 0, 'next_try' => 0]);

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
            self::create($pending, $plainOnBigTable);
            self::forgetColumns();
            $left = self::pending();
            $built = array_values(array_diff(array_keys($pending), array_keys($left)));
            self::saveState(['done' => $left === [], 'failures' => 0, 'next_try' => 0]);

            return ['built' => $built, 'skipped' => $left === [] ? null : 'not built: '.implode(', ', array_keys($left)), 'seconds' => round(microtime(true) - $started, 3)];
        } catch (Throwable $e) {
            $failures = self::state()['failures'] + 1;
            self::saveState(['failures' => $failures, 'next_try' => time() + 60 * min(60, 2 ** min(6, $failures - 1))]);
            Log::warning('Videos: an index could not be given its 1.2.3 definition yet; the scheduler tries again later.', [
                'indexes' => array_keys($pending),
                'failures' => $failures,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['built' => [], 'skipped' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300), 'seconds' => round(microtime(true) - $started, 3)];
        } finally {
            rescue(fn () => $lock?->release(), null, false);
        }
    }

    /** When the next try is due after a failure (null: no pause runs), in the application's time zone. */
    public static function nextTry(): ?\Illuminate\Support\Carbon
    {
        $next = self::state()['next_try'];

        return $next > time() ? now()->setTimestamp($next) : null;
    }

    /** Forgets what is known (tests, a restored database). */
    public static function forget(): void
    {
        rescue(fn () => Cache::forget(self::STATE_KEY), null, false);
    }

    /**
     * @param  array<string, array{now: ?list<string>, wanted: list<string>}>  $pending
     */
    private static function create(array $pending, bool $plainOnBigTable): void
    {
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('videos', function (Blueprint $table) use ($pending): void {
                foreach ($pending as $name => $index) {
                    if ($index['now'] !== null) {
                        $table->dropIndex($name);
                    }
                }
            });
            Schema::table('videos', function (Blueprint $table) use ($pending): void {
                foreach ($pending as $name => $index) {
                    $table->index($index['wanted'], $name);
                }
            });

            return;
        }

        $table = $connection->getTablePrefix().'videos';
        $changes = [];

        foreach ($pending as $name => $index) {
            if ($index['now'] !== null) {
                $changes[] = 'DROP INDEX `'.$name.'`';
            }

            $changes[] = 'ADD INDEX `'.$name.'` (`'.implode('`, `', $index['wanted']).'`)';
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
                $connection->statement('ALTER TABLE `'.$table.'` '.implode(', ', $changes).', ALGORITHM=INPLACE, LOCK=NONE');
            } catch (Throwable $e) {
                $code = (int) (($e->errorInfo[1] ?? null) ?? 0);
                $online = in_array($code, [1845, 1846], true)
                    || preg_match('/ALGORITHM=INPLACE is not supported|LOCK=NONE is not supported|is not supported for this operation/i', $e->getMessage()) === 1;

                // a server (or table engine) that cannot do it online: the plain way, only where that is short
                if (! $online || (! $plainOnBigTable && ! self::smallEnough())) {
                    throw $e;
                }

                $connection->statement('ALTER TABLE `'.$table.'` '.implode(', ', $changes));
            }
        } finally {
            if ($previous !== null && $previous > 0) {
                rescue(fn () => $connection->statement('SET SESSION lock_wait_timeout = '.$previous), null, false);
            }
        }
    }

    /** @return array{done: bool, failures: int, next_try: int} */
    private static function state(): array
    {
        try {
            $state = Cache::get(self::STATE_KEY);
        } catch (Throwable) {
            $state = null;
        }

        $state = is_array($state) ? $state : [];

        return [
            'done' => (bool) ($state['done'] ?? false),
            'failures' => max(0, (int) ($state['failures'] ?? 0)),
            'next_try' => max(0, (int) ($state['next_try'] ?? 0)),
        ];
    }

    /** @param  array<string, mixed>  $changes */
    private static function saveState(array $changes): void
    {
        rescue(fn () => Cache::forever(self::STATE_KEY, array_merge(self::state(), $changes)), null, false);
    }
}
