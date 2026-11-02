<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Video;
use App\Enums\VideoStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How many videos each category shows to visitors (the side menu, the footer, Home, Trend, the Categories page and a
 * category's page): the category page's own rule (Video::active()->forLocale(): public or due, uploaded, of an active
 * creator, in the visitor's language, not in the bin), counted in one statement per set of categories and kept until
 * it can change (judge R3-022: at 300,000 videos the side menu recounted every category on every page view, 0.57-0.78 s).
 *
 * A kept count never lies:
 *  - every statement the site sends that can move a video in or out of a count changes the stamp the counts are kept
 *    under (changes(), a listener of every statement: an insert, delete or truncate of videos, an update of videos
 *    that sets one of VIDEO_COLUMNS, an update of users that sets one of USER_COLUMNS or a delete of users, any write to
 *    categories): the studio, the admin's single and bulk actions, bans, imports, the scheduler, the update's steps and
 *    the tidy-ups, whatever way they write (models, the query builder, raw statements), in any process. Inside a
 *    transaction the stamp changes again once it commits, so counts worked out by another request before the commit
 *    are never kept under the new stamp;
 *  - counts worked out inside a transaction (a view-only demo page runs in one that is rolled back; a REPEATABLE READ
 *    snapshot may be older than the stamp) are shown, never kept;
 *  - a video that becomes visible by time alone (a public video dated later, a scheduled one) ends the counts at that
 *    moment: each entry carries the next such moment (until) and is worked out again from then on;
 *  - per language and per default language (forLocale() reads both), kept at most KEEP_SECONDS: a change made outside
 *    the site (a database tool) shows within that time too.
 * One cache entry per set and language, overwritten in place (the stamp is in the value): a new stamp leaves no stale
 * entry behind in a file cache. Without a cache store that keeps values between requests, the counts are worked out
 * once per request, as before.
 */
final class CategoryVideoCounts
{
    /** The stamp the counts are kept under: a new value at every change (see above). */
    public const STAMP_KEY = 'catalogue.category_counts.stamp';

    /** The columns of videos whose change can move a video in or out of a count (Video::active(), forLocale()). */
    public const VIDEO_COLUMNS = ['status', 'category_id', 'language', 'avdb_id', 'xvid_id', 'uploaded_at', 'published_at', 'scheduled_at', 'deleted_at', 'user_id'];

    /** The columns of users whose change can (a creator's videos count while the account is active: User::active()). */
    public const USER_COLUMNS = ['email_verified_at', 'banned_at', 'deleted_at'];

    private const ENTRY_PREFIX = 'catalogue.category_counts.v2.';

    /** The index a count reads alone once it has its 1.2.3 columns (count(), App\Support\VideoIndexes). */
    private const COUNT_INDEX = 'videos_category_active_count_idx';

    /** The most active creators the count checks as a list on MySQL 8 (activeCreators()). */
    private const CREATORS_LIST = 2000;

    /**
     * The longest an entry is used (a newer stamp usually ends it long before): also the longest a change made outside
     * the site, which no listener sees, takes to show.
     */
    private const KEEP_SECONDS = 600;

    /** @var array<string, array{counts: array<int, int>, until: ?int, stamp: string, at: int}> this process's counts per set */
    private static array $memo = [];

    /** @var \WeakMap<object, bool>|null the event dispatchers listened to (one per application: tests make many) */
    private static ?\WeakMap $listening = null;

    /**
     * The number of videos each of these categories shows in $locale (the request's language by default), by category
     * id (a category without such a video is left out). Each set of categories is counted and kept on its own: the side
     * menu's (every page), all of them (the Categories page), one (a category's page), so a page counts no more than it
     * shows when the counts are worked out again.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    public static function forIds(array $ids, ?string $locale = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        sort($ids);
        $locale = (string) ($locale ?? app()->getLocale());
        // forLocale() follows the default language too (app.fallback_locale): another default is another rule
        $set = $locale.'.'.(string) config('app.fallback_locale', 'en').'.'.sha1(implode(',', $ids));
        $stamp = self::stamp();
        $memo = self::$memo[$set] ?? null;

        if (self::fresh($memo, $stamp)) {
            return $memo['counts'];
        }

        // a set this process counted already that holds every one of these categories (the Categories page counts all
        // of them, then its side menu shows some): their counts come from it, with no second count of the same videos
        foreach (self::$memo as $other => $counted) {
            if (str_starts_with($other, $locale.'.'.(string) config('app.fallback_locale', 'en').'.') && is_array($counted['ids'] ?? null)
                && self::fresh($counted, $stamp) && array_diff($ids, $counted['ids']) === []) {
                $entry = ['counts' => array_intersect_key($counted['counts'], array_flip($ids)), 'ids' => $ids] + $counted;
                rescue(fn () => Cache::put(self::ENTRY_PREFIX.$set, $entry, self::KEEP_SECONDS), null, false);

                return (self::$memo[$set] = $entry)['counts'];
            }
        }

        $key = self::ENTRY_PREFIX.$set;

        try {
            $entry = Cache::get($key);
        } catch (Throwable) {
            $entry = null;
        }

        if (self::fresh($entry, $stamp)) {
            self::$memo[$set] = $entry;

            return $entry['counts'];
        }

        // kept under the stamp read BEFORE counting: a change made meanwhile has another stamp already
        $entry = self::count($ids, $locale) + ['stamp' => $stamp, 'at' => now()->getTimestamp(), 'ids' => $ids];

        if ($stamp !== '' && ! self::insideTransaction()) {
            rescue(fn () => Cache::put($key, $entry, self::KEEP_SECONDS), null, false);
            self::$memo[$set] = $entry;
        }

        return $entry['counts'];
    }

    /** The number of videos $category shows in $locale (forIds()). */
    public static function of(Category|int $category, ?string $locale = null): int
    {
        $id = $category instanceof Category ? (int) $category->getKey() : $category;

        return (int) (self::forIds([$id], $locale)[$id] ?? 0);
    }

    /**
     * Gives each of $categories its videos_count (forIds()), as Eloquent's withCount() would.
     *
     * @template T of iterable<Category>
     *
     * @param  T  $categories
     * @return T
     */
    public static function apply(iterable $categories, ?string $locale = null): iterable
    {
        $ids = [];

        foreach ($categories as $category) {
            $ids[] = (int) $category->getKey();
        }

        $counts = self::forIds($ids, $locale);

        foreach ($categories as $category) {
            $category->setAttribute('videos_count', (int) ($counts[(int) $category->getKey()] ?? 0));
        }

        return $categories;
    }

    /**
     * Listens to every statement of this process (once): the writes that can change a count change the stamp
     * (changes()). Registered by App\Providers\ViewServiceProvider, in web requests and console processes alike.
     */
    public static function listen(): void
    {
        $events = app('events');
        self::$listening ??= new \WeakMap();

        if (isset(self::$listening[$events])) {
            return;
        }

        self::$listening[$events] = true;
        // on the dispatcher itself: booting this never makes a database connection (the installer has none yet)
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (self::changes($query->sql, $query->connection->getTablePrefix())) {
                self::changed($query->connection);
            }
        });
    }

    /**
     * Whether a statement can change a count: an insert, replace, delete or truncate of videos, users or categories; an
     * update of videos that sets one of VIDEO_COLUMNS, of users that sets one of USER_COLUMNS, any update of categories.
     * Reads (the great majority) are told apart by their first word.
     */
    public static function changes(string $sql, string $prefix = ''): bool
    {
        $head = strtolower(substr(ltrim($sql, " \t\r\n("), 0, 6));

        if (! in_array($head, ['insert', 'update', 'delete', 'replac', 'trunca'], true)) {
            return false;
        }

        $q = '[`"\[]?';
        // the whole name: (?<![\w$]) keeps playlist_has_videos, user_videos ... from reading as videos
        $table = '(?:'.$q.'\w+'.$q.'\.)?'.$q.'(?<![\w$])'.preg_quote($prefix, '/').'(videos|users|categories)'.$q;

        if ($head === 'update') {
            // the assignments: from set to the statement's last where (a subquery in a value stays in them)
            if (preg_match('/^\s*update\s+(?:low_priority\s+|ignore\s+)*'.$table.'(?=[\s,]).*?\sset\s(.*)\swhere\s/is', $sql, $m) !== 1
                && preg_match('/^\s*update\s+(?:low_priority\s+|ignore\s+)*'.$table.'(?=[\s,]).*?\sset\s(.*)$/is', $sql, $m) !== 1) {
                // a multi-table update (update videos inner join ... set ...): the tables are named before set
                return preg_match('/^\s*update\s.*?'.$table.'(?=[\s,]).*?\sset\s/is', $sql) === 1;
            }

            $columns = match (strtolower($m[1])) {
                'videos' => self::VIDEO_COLUMNS,
                'users' => self::USER_COLUMNS,
                default => null, // categories: any change
            };

            return $columns === null
                || preg_match('/(?<![\w])'.$q.'(?:\w+'.$q.'\.'.$q.')?('.implode('|', $columns).')'.$q.'\s*=/i', $m[2]) === 1;
        }

        if ($head === 'delete') {
            // delete from videos ... / delete videos from videos join ... (MySQL's multi-table form)
            return preg_match('/^\s*delete\s+(?:low_priority\s+|quick\s+|ignore\s+)*(?:'.$table.'(?:\s*,\s*'.$table.')*\s+)?from\s+'.$table.'(?=[\s;]|$)/is', $sql) === 1
                || preg_match('/^\s*delete\s+(?:low_priority\s+|quick\s+|ignore\s+)*'.$table.'[\s,]/is', $sql) === 1;
        }

        // insert into / insert ignore into / replace into / truncate [table]; a new account has no videos yet
        if ($head !== 'trunca' && preg_match('/^\s*(?:insert|replace)\s+(?:low_priority\s+|delayed\s+|high_priority\s+|ignore\s+|or\s+\w+\s+)*(?:into\s+)?'.$q.'(?:\w+'.$q.'\.)?'.$q.preg_quote($prefix, '/').'users'.$q.'(?=[\s(]|$)/is', $sql) === 1) {
            return false;
        }

        return preg_match('/^\s*(?:insert|replace)\s+(?:low_priority\s+|delayed\s+|high_priority\s+|ignore\s+|or\s+\w+\s+)*(?:into\s+)?'.$table.'(?=[\s(]|$)/is', $sql) === 1
            || preg_match('/^\s*truncate\s+(?:table\s+)?'.$table.'(?=[\s;]|$)/is', $sql) === 1;
    }

    /**
     * Something changed: the stamp gets a new value now and, inside a transaction, again once it commits (counts worked
     * out meanwhile from the rows before the commit are then kept under a stamp nobody reads any more).
     */
    public static function changed(?Connection $connection = null): void
    {
        self::bump();

        try {
            $connection ??= DB::connection();

            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit(static fn () => self::bump());
            }
        } catch (Throwable) {
            // no transaction support: the stamp changed already
        }
    }

    /** Forgets what this process kept (tests, a long-running worker). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function bump(): void
    {
        self::$memo = [];

        try {
            // a store that cannot write answers false (a full disk, a read-only folder): then the old stamp must go
            $written = Cache::forever(self::STAMP_KEY, bin2hex(random_bytes(8)).'.'.microtime(true));
        } catch (Throwable) {
            $written = false;
        }

        if ($written === false) {
            rescue(fn () => Cache::forget(self::STAMP_KEY), null, false);
        }
    }

    private static function stamp(): string
    {
        try {
            $stamp = Cache::get(self::STAMP_KEY);

            if (! is_string($stamp) || $stamp === '') {
                $stamp = bin2hex(random_bytes(8)).'.'.microtime(true);
                Cache::forever(self::STAMP_KEY, $stamp);
            }

            return $stamp;
        } catch (Throwable) {
            return ''; // no cache: counted every time
        }
    }

    /**
     * Whether $entry (a cache entry or this process's memo) holds counts of the current stamp that are still good: worked
     * out under $stamp, less than KEEP_SECONDS ago, before the next moment a video becomes visible by time alone.
     *
     * @param  mixed  $entry
     */
    private static function fresh($entry, string $stamp): bool
    {
        $now = now()->getTimestamp();

        return $stamp !== '' && is_array($entry) && is_array($entry['counts'] ?? null) && array_key_exists('until', $entry)
            && ($entry['stamp'] ?? null) === $stamp
            && (int) ($entry['at'] ?? 0) > $now - self::KEEP_SECONDS
            && ($entry['until'] === null || (int) $entry['until'] > $now);
    }

    /**
     * Whether this process is inside a transaction of the site's own (not the test suite's wrapping one, which the
     * transaction manager leaves out like it does for its after-commit callbacks): counts read there may come from a
     * snapshot older than the stamp, or from writes that will be rolled back (a view-only demo page).
     */
    private static function insideTransaction(): bool
    {
        try {
            if (DB::connection()->transactionLevel() < 1) {
                return false;
            }

            $manager = app()->bound('db.transactions') ? app('db.transactions') : null;

            return ! is_object($manager) || ! method_exists($manager, 'callbackApplicableTransactions')
                || count($manager->callbackApplicableTransactions()) > 0;
        } catch (Throwable) {
            return true; // unknown: show the counts, keep nothing
        }
    }

    /**
     * The counts of these categories and the next moment a video becomes visible by time alone (null: none is waiting),
     * in one statement per 500 categories, as many statements as the side menu's withCount() sent before.
     *
     * On MySQL / MariaDB, once the count index has its 1.2.3 columns (App\Support\VideoIndexes: the language, the creator
     * and the imports after the status and dates), the count may read that index alone, or the language's and the
     * imports' indexes where few videos are in the language: the database chooses among them (USE INDEX), so a cold
     * count reads no row of the table (look-around DB-X2: at 300,000 videos 0.6-2 s per count before, 0.1-0.4 s now on
     * the side menu's categories, a few ms in a language few videos are in). MySQL 8 counts every category in one
     * grouped pass (its creator check one semi-join: a count per category ran that check for every row and every
     * category), MariaDB per category (its own best plan).
     *
     * @param  list<int>  $ids
     * @return array{counts: array<int, int>, until: ?int}
     */
    private static function count(array $ids, string $locale): array
    {
        $counts = [];
        $next = [];
        $now = now();
        $use = self::countIndexes();
        $mysql = $use !== null && ! self::mariaDb();
        // MySQL 8: the active creators who have videos, when there are not many: the count checks each row's creator
        // against that list instead of a semi-join with the members (at 300,000 videos about half the count's time)
        $creators = $mysql ? self::activeCreators() : null;

        foreach (array_chunk($ids, 500) as $chunk) {
            $categories = Category::query()->whereKey($chunk);

            if ($mysql) {
                $grouped = Video::query()
                    ->useIndex($use)
                    ->whereIn('videos.category_id', $chunk)
                    ->active(false, $creators)
                    ->forLocale($locale)
                    ->groupBy('videos.category_id')
                    ->select('videos.category_id as avd_category_id')
                    ->selectRaw('count(*) as avd_videos_count');
                $categories
                    ->leftJoinSub($grouped->toBase(), 'avd_counts', 'avd_counts.avd_category_id', '=', 'categories.id')
                    ->select('categories.id')
                    ->selectRaw('coalesce(avd_counts.avd_videos_count, 0) as videos_count');
            } else {
                $categories->withCount(['videos as videos_count' => fn ($query) => $query
                    ->when($use !== null, fn ($query) => $query->useIndex($use))
                    ->active()
                    ->forLocale($locale)]);
            }

            $rows = $categories
                // the next public video dated later and the next scheduled one: the first entry of an index range each
                // ((status, published_at, id), (status, scheduled_at, id)), subqueries of their own worked out once
                ->addSelect([
                    'next_public_at' => Video::query()->selectRaw('min(published_at)')
                        ->where('status', VideoStatus::PUBLIC)->where('published_at', '>', $now),
                    'next_planned_at' => Video::query()->selectRaw('min(scheduled_at)')
                        ->where('status', VideoStatus::PLANNED)->where('scheduled_at', '>', $now),
                ])
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                if ((int) $row->videos_count > 0) {
                    $counts[(int) $row->id] = (int) $row->videos_count;
                }

                foreach ([$row->next_public_at ?? null, $row->next_planned_at ?? null] as $at) {
                    if ($at !== null && $at !== '') {
                        $next[] = Carbon::parse((string) $at)->getTimestamp();
                    }
                }
            }
        }

        return ['counts' => $counts, 'until' => $next !== [] ? (int) min($next) : null];
    }

    /**
     * The indexes a count may read on MySQL / MariaDB (count()): the count index once it has its 1.2.3 columns, and the
     * language's and the imports' indexes the table has; null elsewhere or before then (the plan of 1.2.2).
     */
    private static function countIndexes(): ?string
    {
        if (! VideoIndexes::ready(self::COUNT_INDEX)) {
            return null;
        }

        $names = [self::COUNT_INDEX];

        foreach (['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx'] as $index) {
            if (VideoIndexes::columns($index) !== null) {
                $names[] = $index;
            }
        }

        return implode(', ', $names);
    }

    /**
     * The ids of the active creators (User::active(), not deleted) who have videos, when there are at most
     * CREATORS_LIST of them; null (the count checks the creator by a join) when there are more. Two short reads: the
     * creators of the videos from the creator index (one entry each), then which of them are active. Also the public
     * lists' count of a language's videos a visitor can be shown (PublicVideoFeedService::shownPlan()): named, the
     * creators who are not active are left out of the index's ranges.
     *
     * @return list<int>|null
     */
    public static function activeCreators(): ?array
    {
        try {
            $ids = Video::query()->withTrashed()->distinct()->limit(self::CREATORS_LIST + 1)->pluck('user_id')->all();

            if (count($ids) > self::CREATORS_LIST) {
                return null;
            }

            return \App\Models\User::query()->whereIn('id', $ids)->active()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        } catch (Throwable) {
            return null;
        }
    }

    private static function mariaDb(): bool
    {
        try {
            $connection = DB::connection();

            return $connection instanceof MySqlConnection && $connection->isMaria();
        } catch (Throwable) {
            return false;
        }
    }
}
