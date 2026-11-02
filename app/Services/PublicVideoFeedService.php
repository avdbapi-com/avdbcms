<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Video;
use App\Support\CachedUsers;
use App\Support\CategoryVideoCounts;
use App\Support\DiscoverySnapshots;
use App\Support\SafeCache;
use App\Support\SafeCursor;
use App\Support\VideoIndexes;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublicVideoFeedService
{
    /**
     * The home hero's list (PageController::home): the 10 trending videos, kept for 3 minutes. One list per language
     * (forLocale(): a language sees the videos made for it), so the cache key carries the locale: the site's default
     * language keeps this key, every other language adds ":<locale>" (heroCacheKey()).
     */
    public const HERO_CACHE_KEY = 'home_hero_featured_videos';

    /**
     * The home's shorts shelf (watch-32): up to six trending videos of 3 minutes or less of the request's language, kept
     * for 3 minutes like the hero list (heroShorts()). Forgotten with it (forgetHeroVideos()).
     */
    public const HERO_SHORTS_CACHE_KEY = 'home_hero_short_videos';

    /** The longest video the shorts shelf calls a short, in seconds. */
    public const SHORT_MAX_SECONDS = 180;

    /** How many short videos the shelf shows. */
    public const HERO_SHORTS_COUNT = 6;

    /** How long the shelf's short videos are current (seconds): 3 minutes, like the hero list. */
    public const HERO_SHORTS_SECONDS = 180;

    /** How many of the most viewed videos the shorts shelf looks for short ones in (heroShorts()). */
    public const SHORTS_FROM_MOST_VIEWED = 5000;

    /**
     * Cursor keys an earlier release wrote => the key a feed orders by now. mostCommented() orders by the stored count
     * comments_count, the key 1.2.2's cursors carry already; a "load more" cursor of an earlier 1.2.3 build named the
     * count public_comments_count (an open tab, the cached first page of the home feed) and goes on where it was.
     */
    public const RENAMED_CURSOR_KEYS = ['public_comments_count' => 'comments_count'];

    /**
     * The indexes the views-ordered feeds (trending(), mostViewed()) are read by on MySQL / MariaDB, the first one the
     * table has: the one whose columns are the feeds' order, then the one used before 1.2.3.
     */
    public const VIEWS_ORDER_INDEXES = ['videos_views_published_id_idx', 'videos_status_views_id_idx'];

    /** The index of mostCommented()'s order (comments_count, published_at, id): every site has it since 1.2.0. */
    public const COMMENTS_ORDER_INDEX = 'videos_comments_count_published_id_idx';

    /**
     * The indexes a category's list (categoryId()) is read by on MySQL / MariaDB, the first one the table has: the one
     * whose columns are the list's order within its category (App\Support\VideoIndexes, 1.2.3), then the one used before.
     */
    public const CATEGORY_ORDER_INDEXES = ['videos_category_published_id_idx', 'videos_category_status_published_id_idx'];

    /**
     * The indexes whose columns are a whole feed order, newest first (index => its columns): a page of a feed named to one
     * of them is read in its order, each row checked as it comes, when that finds the page soon (walk()). A leading column
     * the page holds to one value (a category's list: category_id = ?) is not part of the order.
     */
    public const WALK_INDEXES = [
        'videos_published_id_idx' => ['published_at', 'id'],
        'videos_views_published_id_idx' => ['views', 'published_at', 'id'],
        self::COMMENTS_ORDER_INDEX => ['comments_count', 'published_at', 'id'],
        self::CATEGORY_ORDER_INDEXES[0] => ['category_id', 'published_at', 'id'],
    ];

    /** How many entries of its index from a page's place walk() checks before the page is read in the index's order. */
    public const WALK_WINDOW = 1000;

    /**
     * How many entries of each alternative of the visitor's language rule (Video::scopeForLocale(): imported, in the
     * language, without one) walk()'s second check looks at, with every condition of the page: half of them from each end
     * of the alternative's index (its oldest and its newest entries, read in the index's order: no sort), so a run of
     * hidden entries at one end (an old batch in the bin, a new import batch in the bin) does not hide the language's
     * videos a visitor can be shown. An alternative with no more entries than this is seen whole, so a language none of
     * whose videos a visitor can be shown gives an empty page at once.
     */
    public const WALK_LANGUAGE_ROWS = 5000;

    /**
     * A visitor's language with fewer videos a visitor can be shown than this (counted by LANGUAGE_SHOWN_INDEX alone) is
     * read by them where the first check does not settle a page (shownPlan(): a bounded read); on a table without that
     * index, one with fewer entries than this (its indexes alone) is read by its own entries; with more, walk() also counts
     * the list's entries from the page's place up to this.
     */
    public const WALK_BOUND = 10000;

    /**
     * The most entries of the visitor's language walk() lets a page read by the language's own indexes on a long list
     * whose first entries are other languages' videos (the language's rows are further down the list's order).
     */
    public const WALK_LANGUAGE_MOST = 40000;

    /**
     * The most entries a category may have (whatever the page's place; counted by its list's index alone) for walk() to
     * count the category's rows the page could show by the index that tests every condition of the page before its other
     * checks (shownCount(): it reads that index until it has the page's count, all of the category where the rows are
     * few), where the visitor's language has more entries than the second check looks at: a bigger category is never read
     * whole for one page; the list's own checks decide there.
     */
    public const WALK_CATEGORY_MOST = 40000;

    /** The statuses a visitor can be shown a video in (Video::scopeActive(): public, or scheduled and due). */
    public const SHOWN_STATUSES = [VideoStatus::PUBLIC, VideoStatus::PLANNED];

    /**
     * A walk index => the index that keeps its order within each language and status: the newest list's (published_at,
     * id) in (language, status, published_at, id).
     */
    public const WALK_PART_INDEXES = ['videos_published_id_idx' => 'videos_language_status_published_id_idx'];

    /**
     * A walk index => the index that holds, after the walk index's leading column (a category's list: category_id = ?),
     * every column a visitor's page tests (App\Support\VideoIndexes::WANTED: the status, the bin, uploaded, published and
     * scheduled times, the language, the creator, the import keys): read by it, each entry is tested before its row is
     * read, so a category whose videos the visitor is shown few of (or none) costs a read of its own index entries, not
     * of its rows (walk()). Named only where the table has it with those columns (VideoIndexes::ready()).
     */
    public const WALK_SHOWN_INDEXES = [self::CATEGORY_ORDER_INDEXES[0] => 'videos_category_active_count_idx'];

    /**
     * The index that holds, after the language, every column of the rule a visitor is shown a video by
     * (App\Support\VideoIndexes::WANTED: the status, the bin, the creator, the scheduled, published and uploaded times),
     * in an order whose ranges leave out what nobody is shown: another status (private, a draft ...), the bin, a creator
     * who is not active (the active creators named), a video scheduled for later. walk() counts and reads a language's
     * videos a visitor can be shown by it (shownPlan()), whatever the language's hidden videos, where the table has it with
     * those columns (VideoIndexes::ready()).
     */
    public const LANGUAGE_SHOWN_INDEX = 'videos_language_active_idx';

    /** The index each alternative of the visitor's language rule is read by (its column => the index). */
    private const LANGUAGE_INDEXES = [
        'language' => 'videos_language_status_published_id_idx',
        'avdb_id' => 'videos_avdb_id_unique',
        'xvid_id' => 'videos_xvid_id_unique',
    ];

    /**
     * The columns of each LANGUAGE_INDEXES index as the migrations make it, in order (where the database does not say:
     * indexColumns()): the second check reads each alternative's newest entries in that order, backwards.
     */
    private const LANGUAGE_INDEX_COLUMNS = [
        'videos_language_status_published_id_idx' => ['language', 'status', 'published_at', 'id'],
        'videos_avdb_id_unique' => ['avdb_id'],
        'videos_xvid_id_unique' => ['xvid_id'],
    ];

    /**
     * One page of a feed of this service from the request's raw "cursor" value. A cursor that does not fit the feed
     * gives its first page (App\Support\SafeCursor).
     */
    public function page(Builder $query, int $perPage, mixed $cursor): CursorPaginator
    {
        return SafeCursor::paginate($query, $perPage, $cursor, self::RENAMED_CURSOR_KEYS);
    }

    /**
     * The home hero's videos with their creator and category, for the request's language: a list cached under one
     * language's key was shown to every language for its 3 minutes. The cached creators keep only their public
     * columns (App\Support\CachedUsers): the entry held each creator's password hash, e-mail address and phone.
     */
    public function heroVideos(): Collection
    {
        return SafeCache::remember(self::heroCacheKey(), 180, fn () => CachedUsers::publicOnly($this->trending()
            ->with(['user', 'category'])
            ->take(10)
            ->get()));
    }

    /**
     * The trending short videos (SHORT_MAX_SECONDS or less) of the request's language with their creator, for the shorts
     * shelf when the hero list holds fewer than HERO_SHORTS_COUNT of them, current for 3 minutes per language, the same
     * videos as since 1.2.0 (exactShorts()). A stored duration is text on some installs, so the length filter is computed
     * and no index serves it: the page looks for them among the SHORTS_FROM_MOST_VIEWED most viewed videos (the views
     * order's index alone, then those rows), in the trending order; that is the whole answer when it finds every short
     * the shelf shows or the site has no more videos than that. Else the scheduler's task finds the rest in the
     * background (App\Support\DiscoverySnapshots; reading every public video took 0.9-5.6 s at 300,000 videos on a site
     * with few short ones, look-around DB-X2) and the shelf shows the short videos found so far until then (the shelf
     * shows the trending videos when it has fewer than three short ones, as before).
     */
    public function heroShorts(): Collection
    {
        return DiscoverySnapshots::answer('shorts', ['locale' => app()->getLocale()], self::HERO_SHORTS_SECONDS, function (): array {
            $found = CachedUsers::publicOnly($this->shorts($this->baseQuery()
                ->joinSub($this->mostViewedIds(static::SHORTS_FROM_MOST_VIEWED), 'avd_top', 'avd_top.avd_top_id', '=', 'videos.id')
                ->select('videos.*')
                ->orderByDesc('views')
                ->latest('published_at')
                ->orderByDesc('id')));

            return [$found, $found->count() >= self::HERO_SHORTS_COUNT || ! $this->hasMoreVideosThan(static::SHORTS_FROM_MOST_VIEWED)];
        });
    }

    /**
     * The shorts shelf's videos for $params (its locale), 1.2.2's way: the trending videos of SHORT_MAX_SECONDS or less,
     * whichever their place in the trending order.
     *
     * @param  array<string, scalar>  $params
     */
    public function exactShorts(array $params = []): Collection
    {
        return CachedUsers::publicOnly($this->shorts($this->trending()));
    }

    /** The first HERO_SHORTS_COUNT short videos of $query (in its order) with their creator. */
    private function shorts(Builder $query): Collection
    {
        return $query
            // duration + 0: a number in MySQL and SQLite alike (the column holds text on some installs).
            ->whereRaw('duration + 0 > 0')
            ->whereRaw('duration + 0 <= ?', [self::SHORT_MAX_SECONDS])
            ->with('user')
            ->take(self::HERO_SHORTS_COUNT)
            ->get();
    }

    /** Whether the videos table holds more than $count rows (its key alone, at most $count + 1 entries). */
    private function hasMoreVideosThan(int $count): bool
    {
        return DB::table('videos')->select('id')->orderBy('id')->offset(max(0, $count))->limit(1)->exists();
    }

    /** The ids of the $count most viewed videos, as a subquery whose one column is avd_top_id (the views index alone). */
    private function mostViewedIds(int $count): \Illuminate\Database\Query\Builder
    {
        $ids = DB::table('videos')
            ->select('videos.id as avd_top_id')
            ->orderByDesc('videos.views')
            ->orderByDesc('videos.published_at')
            ->orderByDesc('videos.id')
            ->limit(max(1, $count));

        if (VideoIndexes::columns(self::VIEWS_ORDER_INDEXES[0]) !== null) {
            $ids->forceIndex(self::VIEWS_ORDER_INDEXES[0]);
        }

        return $ids;
    }

    /** The hero list's cache key for a language (the current one by default); $base names another hero list (the shorts). */
    public static function heroCacheKey(?string $locale = null, string $base = self::HERO_CACHE_KEY): string
    {
        $locale = (string) ($locale ?? app()->getLocale());
        $default = (string) config('site.default_locale', config('app.locale', 'en'));

        return $locale === '' || $locale === $default ? $base : $base.':'.$locale;
    }

    /**
     * A video left the site (VideoObserver::deleted): the home hero and its shorts shelf stop linking to it at once, not
     * 3 minutes later, in every language.
     */
    public static function forgetHeroVideos(): void
    {
        SafeCache::forget(self::HERO_CACHE_KEY);
        SafeCache::forget(self::HERO_SHORTS_CACHE_KEY);

        $locales = array_keys((array) config('languages', []));
        $locales[] = (string) config('app.locale', 'en');
        $locales[] = (string) config('app.fallback_locale', 'en');
        $locales = array_values(array_unique(array_filter($locales, 'is_string')));

        foreach ($locales as $locale) {
            SafeCache::forget(self::heroCacheKey($locale));
            SafeCache::forget(self::heroCacheKey($locale, self::HERO_SHORTS_CACHE_KEY));
        }

        // the shorts shelf's answers (heroShorts()) and their snapshots: the next page finds the short videos again
        DiscoverySnapshots::forget('shorts', array_map(fn (string $locale): array => ['locale' => $locale], $locales));
    }

    public function home(string $source = 'newest', int $categoryId = 0): Builder
    {
        return match ($source) {
            'most_viewed' => $this->mostViewed(),
            'most_commented' => $this->mostCommented(),
            'trending' => $this->trending(),
            'category' => $categoryId > 0 ? $this->categoryId($categoryId) : $this->latest(),
            default => $this->latest(),
        };
    }

    /**
     * The newest videos. Read in the order of the (published_at, id) index, newest first, each row checked as it comes,
     * so the first page reads about as many rows as it shows and a "load more" page starts at its cursor: the index of
     * (status, published_at, id) gives no order across the two statuses Video::active() allows (public and due
     * scheduled videos), so the database sorted every active video for each page (judge R3-022: 0.8 s at 300,000
     * videos for Home's first four cards whenever the feed's cache was cold).
     */
    public function latest(): Builder
    {
        return $this->baseQuery('videos_published_id_idx')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    public function mostViewed(): Builder
    {
        return $this->byViews(self::VIEWS_ORDER_INDEXES);
    }

    /**
     * The most commented videos, newest first among equals: by the count of public comments each video keeps
     * (videos.comments_count: not hidden by an admin, not deleted, replies included; App\Observers\CommentObserver moves
     * it with every comment written, hidden, shown again, deleted or restored, and App\Support\EngagementCounters counts
     * every video again once a day, the update to 1.2.3 asking for a pass at once), read in the order of its
     * (comments_count, published_at, id) index like the other feeds. Up to the first 1.2.3 build every page counted the
     * public comments of every active video and sorted them all (look-around DB-X1: 1.7-10 s at 300,000 videos); the
     * order is the same for the same comments.
     */
    public function mostCommented(): Builder
    {
        return $this->baseQuery(self::COMMENTS_ORDER_INDEX)
            ->orderByDesc('comments_count')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    /**
     * The most viewed videos, newest first among equals. Read in the order of the (views, published_at, id) index where
     * the site has it (App\Support\VideoIndexes: built by the 1.2.3 update, on a big table by the scheduler soon after),
     * each row checked as it comes, like latest(); until then the (status, views, id) index and a sort of every active
     * video (judge R3-022's look-around: at 300,000 videos about 4 s on MySQL 8.4 and 0.8 s on MariaDB 10.11 for the hero,
     * each page of Trend and Home's most viewed feed; 2-8 ms read by the order's index).
     */
    public function trending(): Builder
    {
        return $this->byViews(self::VIEWS_ORDER_INDEXES);
    }

    public function category(Category $category): Builder
    {
        return $this->categoryId($category->id);
    }

    /**
     * A category's videos, newest first (its page's list, Home's category source). Read in the order of the (category_id,
     * published_at, id) index where the site has it (App\Support\VideoIndexes: built by the 1.2.3 update, on a big table
     * by the scheduler soon after), each row checked as it comes, like latest(); until then the (category_id, status,
     * published_at, id) index, which gives no order across the two statuses Video::active() allows, so each page and
     * each "load more" sorted every video of the category (look-around of DB-X2: a category of 18,750 of 300,000 videos
     * took 0.1-0.3 s with its rows in memory and 1.5-3.4 s on MySQL 8.4 reading them from disk; members' pages are not
     * cached).
     */
    public function categoryId(int $categoryId): Builder
    {
        return $this->baseQuery(self::CATEGORY_ORDER_INDEXES)
            ->where('category_id', $categoryId)
            ->latest('published_at')
            ->orderByDesc('id');
    }

    /** @param  string|list<string>  $mysqlIndex  the index(es) to read by on MySQL / MariaDB (baseQuery()) */
    private function byViews(string|array $mysqlIndex): Builder
    {
        return $this->baseQuery($mysqlIndex)
            ->orderByDesc('views')
            ->latest('published_at')
            ->orderByDesc('id');
    }

    /** @param  string|list<string>|null  $mysqlIndex  an index to read by on MySQL / MariaDB, or several: the first the table has */
    public function baseQuery(string|array|null $mysqlIndex = null): Builder
    {
        $query = Video::query();
        $named = null;

        foreach ((array) $mysqlIndex as $index) {
            if (is_string($index) && $index !== '' && $this->applyIndex($query, $index)) {
                $named = $index;
                break;
            }
        }

        if ($named !== null && isset(self::WALK_INDEXES[$named])) {
            // each page is read in the index's order only where that order finds it soon (walk())
            $query->getQuery()->beforeQuery(fn (QueryBuilder $base) => $this->walk($base, $named));
        }

        // the creator's check row by row (Video::scopeActive()): a feed is read in its index's order up to its LIMIT
        return $query
            ->active(true)
            ->forLocale();
    }

    /**
     * Keeps a page of a feed in its index's order (the index stays named) only where that order finds the page soon; else
     * the page is read another way (look-around DB-RV-1, the checks' DB-V1-1, DB-V2-1 and DB-V3-1). Read in the index's
     * order, a page reads as many entries as it takes to find its videos: a few where most videos can be shown in the
     * visitor's language, every entry of the index where none can (a site whose own uploads all have the site's language,
     * visited in another one; a language whose videos are all in the bin). Right before a page is read:
     *
     *  - the first check (firstCheck()): the first WALK_WINDOW entries of the index from the page's place (its cursor),
     *    with every condition of the page. When they hold the page, or the index ends within them, the index stays named.
     *  - else, where the table has LANGUAGE_SHOWN_INDEX with its 1.2.3 columns (shownPlan()): the visitor's language's
     *    videos a visitor can be shown, counted by that index alone, per language alternative and status (its ranges
     *    leave out the bin, the other statuses, the creators who are not active and the videos scheduled for later: none
     *    of those entries is read), so the language's hidden videos never decide how a page is read, whatever their number:
     *     - none, and no import: an empty page at once;
     *     - fewer than WALK_BOUND, a bounded read: where the language's entries nobody is shown are many, the page read by
     *       the ids of its rows among them (shownIds(): each part read through that index as the page itself; the database
     *       reads those rows only and sorts them); where they are few (at most WALK_WINDOW: fewHidden()), the earlier
     *       passes' read of a small language (the newest list by its parts in its own order: partIds(); another list by the
     *       language rule's own entries);
     *     - WALK_BOUND or more: the checks below, knowing those rows many (the second check without its sample of rows, no
     *       count of a category's own rows);
     *    an import key with WALK_BOUND entries or more (no index tests an import's showability): the checks below decide.
     *  - else (a table without it: a big site before the scheduler built it; or a big import key), on a category's list
     *    (WALK_SHOWN_INDEXES, that index there with its 1.2.3 columns) whose visitor's language
     *    has more entries than the second check looks at (languageSizes(): its indexes alone; a smaller language's second
     *    check settles the page as cheaply) and whose category has fewer than WALK_CATEGORY_MOST entries (categoryEntries():
     *    the whole category by its order's index alone): the category's rows the page could show, counted by the index
     *    that holds every column the page tests (shownCount(): that index alone, up to the page's count). None -> an empty
     *    page at once; fewer than the page's count -> the page read by that index (readByShownIndex(): the rows of the few
     *    that pass); a page's worth -> the checks below, the second one without its sample of rows (the count found them).
     *  - the second check (languageProbe()), one statement bounded by the indexes alone: the entries of the visitor's
     *    language a visitor could be shown (each alternative of Video::scopeForLocale(): imported, in the language,
     *    without one; the language's own entries only public or scheduled ones), and how many of the page's rows (every
     *    condition of the page: the same rules) are among WALK_LANGUAGE_ROWS entries of each alternative (half from each
     *    end of its index). Then (sparsePlan()):
     *     - no video of the language can be shown (every entry seen, none of them the page's): the page is empty at once;
     *     - the newest list (an index keeps its order within each language and status: WALK_PART_INDEXES): each language
     *       and status, and each kind of import, gives the first rows of the page on its own, in that order (a few entries
     *       each, past the ones of that part nobody is shown), and the page reads those rows by their key;
     *     - a language with fewer than WALK_BOUND entries: its own entries (its indexes; the database sorts the rows they
     *       hold), a bounded read;
     *     - a big language whose rows a visitor can be shown are few or none where the second check looked (fewer of the
     *       page's rows than the page needs: a language whose many videos are all hidden, or nearly all, the third check's
     *       DB-V3-1): never the list's order to its end for rows that are not there. The list keeps its order where it
     *       ends within WALK_BOUND entries (listProbe(): a bounded walk), else reads the language's own entries; bounded
     *       reads every way;
     *     - a big language whose rows a visitor can be shown are many (WALK_BOUND or more counted by LANGUAGE_SHOWN_INDEX; on
     *       a table without it a page's worth among the entries the second check looked at, or a category's own count found
     *       a page's worth): a third check (listProbe()) counts the list's entries from the page's place and looks at what the first check's
     *       entries were: the list ends within WALK_BOUND entries, or they were mostly the language's own, which nobody is
     *       shown (a run above the page: an import batch in the bin, a banned creator's uploads, a batch made private,
     *       videos scheduled for later) -> the index's order, past them; mostly other languages' videos (the language's
     *       rows are further down that order) -> the language's own entries where it has fewer than WALK_LANGUAGE_MOST of
     *       them, else the index's order.
     * The same rows every way (the hints, and the ids of rows the page reads anyway); anything a check cannot read leaves
     * the page as it was. They run only where an index is named (MySQL / MariaDB).
     */
    protected function walk(QueryBuilder $base, string $index): void
    {
        $columns = self::WALK_INDEXES[$index] ?? null;

        if ($columns === null || ($base->indexHint?->index ?? null) !== $index || $base->aggregate !== null || $base->unions
            || $base->groups || $base->havings || $base->limit === null || (int) $base->limit < 1 || ! is_string($base->from)) {
            return; // not a page of the feed itself (a count, a grouped read, every row)
        }

        $orders = array_map(fn (array $order): array => [self::column($order['column'] ?? null), strtolower((string) ($order['direction'] ?? ''))], $base->orders ?? []);
        // a leading column of the index the page holds to one value (a category's list: category_id = ?) is not part of
        // its order
        $order = $columns;

        while (count($order) > 1 && ! in_array($order[0], array_column($orders, 0), true) && self::heldToOneValue($base->wheres, $order[0])) {
            array_shift($order);
        }

        if ($orders !== array_map(fn (string $column): array => [$column, 'desc'], $order)) {
            return; // another order (a page going back): read as before
        }

        $wanted = (int) $base->limit + max(0, (int) $base->offset);

        try {
            $window = $this->walkWindowQuery($base, $index, $columns, $order);

            if ($window === null) {
                return;
            }

            [$found, $entries] = $this->firstCheck($base, $window, $wanted);

            if ($found >= $wanted || $entries < max(1, $this->walkWindow())) {
                return; // the page is within the first entries, or the index ends there
            }

            $plan = $this->sparsePlan($base, $index, $window, $wanted);
        } catch (Throwable) {
            return;
        }

        match ($plan[0]) {
            'nothing' => $this->readNothing($base),
            'ids' => $this->readByIds($base, $plan[1]),
            'language' => $this->readByLanguage($base, $plan[1]),
            'shown' => $this->readByShownIndex($base, $plan[1]),
            default => null, // the index's order
        };
    }

    /**
     * The first check of walk(), one statement: how many of the page's rows are among the first WALK_WINDOW entries of
     * the index from the page's place (every condition of the page, up to the page's count), and how many entries there
     * are (fewer: the index ends there).
     *
     * @return array{0: int, 1: int}
     */
    private function firstCheck(QueryBuilder $base, QueryBuilder $window, int $wanted): array
    {
        $table = (string) $base->from;
        $window = (clone $window)->limit(max(1, $this->walkWindow()));
        $probe = clone $base;
        $probe->beforeQueryCallbacks = [];
        $probe->indexHint = null;
        $probe->orders = null;
        $probe->offset = null;
        // the window's entries first, then each one's row by the key
        $probe->fromSub($window, 'avd_walk')
            ->select(self::idsFirst($base, '1'))
            ->join($table, $table.'.id', '=', 'avd_walk.avd_walk_id')
            ->limit($wanted);

        $row = (array) $base->newQuery()
            ->selectSub($base->newQuery()->fromSub($probe, 'avd_walk_page')->selectRaw('count(*)'), 'avd_walk_found')
            ->selectSub($base->newQuery()->fromSub($window, 'avd_walk_entries')->selectRaw('count(*)'), 'avd_walk_entries')
            ->first();

        return [(int) ($row['avd_walk_found'] ?? 0), (int) ($row['avd_walk_entries'] ?? 0)];
    }

    /**
     * How a page the first check did not settle is read (walk()): ['index'] (the index's order), ['nothing'] (an empty
     * page), ['ids', the ids the page's rows are among], ['language', the indexes of the language rule] or ['shown', the
     * index that tests every condition of the page within its category: WALK_SHOWN_INDEXES].
     *
     * @return array{0: string, 1?: list<int|string>|string}
     */
    private function sparsePlan(QueryBuilder $base, string $index, QueryBuilder $window, int $wanted): array
    {
        $rule = self::languageRule($base->wheres);
        $alternatives = $rule === null ? null : $this->languageAlternatives($base->wheres[$rule]['query']);

        if ($alternatives === null) {
            return ['index']; // no language rule its indexes serve: the index's order, as before
        }

        // the language's videos a visitor can be shown, counted by the index that tests them (LANGUAGE_SHOWN_INDEX, where the
        // table has it with its 1.2.3 columns): none -> an empty page at once; fewer than WALK_BOUND -> the page read by them,
        // a bounded read; more -> the checks below, which then know them many
        $shownPlan = $this->shownPlan($base, $index, $alternatives, $wanted);

        if ($shownPlan !== null && $shownPlan[0] !== 'many') {
            return $shownPlan;
        }

        $many = $shownPlan !== null;

        // a table without LANGUAGE_SHOWN_INDEX (or a big import key): a category's list (WALK_SHOWN_INDEXES, that index there
        // with its 1.2.3 columns) whose visitor's language has more entries than the second check looks at (counted first by
        // the language's indexes alone: a smaller language's second check settles the page as cheaply) and whose category
        // has fewer than WALK_CATEGORY_MOST entries (the whole category, by its list's index alone): its own rows the page
        // could show, counted by the index that holds every column the page tests (the index alone, up to the page's
        // count). None: an empty page at once. Fewer than the page's count: read by that index. A page's worth: the checks
        // below, without the second check's sample of rows (the count found them: the language's rows a visitor can be
        // shown are many there). Where shownPlan() found those rows many, the checks below alone, as the fourth pass (the
        // category's count read up to two of its index's ranges for a page the list's order reads anyway)
        $shown = self::WALK_SHOWN_INDEXES[$index] ?? null;
        $inCategory = null;

        if (! $many && $shown !== null && $this->indexReady($shown)
            && max($this->languageSizes($base, $alternatives, max(1, $this->walkLanguageRows()) + 1)) > max(1, $this->walkLanguageRows())
            && $this->categoryEntries($base, $index) < max(1, $this->walkCategoryMost())) {
            $inCategory = $this->shownCount($base, $shown, $wanted);

            if ($inCategory === 0) {
                return ['nothing'];
            }

            if ($inCategory < $wanted) {
                return ['shown', $shown];
            }
        }

        $parts = self::WALK_PART_INDEXES[$index] ?? null;
        $parts = $parts !== null && $this->indexExists($parts) ? $parts : null;
        // the newest list of a rule without imports (the default language's) is read by its parts whatever the counts: its
        // entries are counted only past what the second check looks at
        $partsOnly = $parts !== null && array_filter($alternatives, fn (array $alternative): bool => $alternative['column'] !== 'language') === [];
        // without its sample of rows where those rows were found many (shownPlan()) or a category's own count found the
        // page's rows: the page's count as the rows found
        $probe = $this->languageProbe($base, $alternatives, $partsOnly, $wanted, ! $many && $inCategory === null);
        $bound = max(1, $this->walkBound());

        if ($probe['shown'] === 0 && max($probe['entries']) <= max(1, $this->walkLanguageRows())) {
            return ['nothing']; // every entry of the language seen: none of them can be shown on this page
        }

        $imports = array_filter($alternatives, fn (array $alternative, int $position): bool => $alternative['column'] !== 'language'
            && $probe['entries'][$position] >= $bound, ARRAY_FILTER_USE_BOTH);

        if ($parts !== null && $imports === []) {
            return ['ids', $this->partIds($base, $alternatives, $parts, $wanted)];
        }

        $indexes = array_values(array_unique(array_column($alternatives, 'index')));
        $language = array_sum($probe['entries']);

        if ($language < $bound) {
            return ['language', $indexes]; // fewer entries than WALK_BOUND: a bounded read
        }

        if ($probe['shown'] < $wanted) {
            // fewer of the page's rows among the entries the second check looked at than the page needs: the language's
            // videos a visitor can be shown are too few there to look for the page in the list's order, which reads its index
            // to the end when they are not there (a language whose many videos are all hidden, or nearly all: the third
            // check's DB-V3-1): the list's order only where it ends within WALK_BOUND entries (a bounded walk), else the
            // language's own entries (a category's list its own rows above, where it could be counted); bounded every way
            return $this->listProbe($base, $window, $base->wheres[$rule], false)['rest'] < $bound ? ['index'] : ['language', $indexes];
        }

        $list = $this->listProbe($base, $window, $base->wheres[$rule]);

        if ($list['rest'] < $bound || $list['window'] * 2 >= max(1, $this->walkWindow())) {
            // the list ends within WALK_BOUND entries; or its first entries are the language's own, which nobody is shown
            // (a run above the page), while the language's videos a visitor can be shown are many (counted by
            // LANGUAGE_SHOWN_INDEX, or a page's worth among the entries the second check looked at): the index's order,
            // past them
            return ['index'];
        }

        // the list's first entries are other languages' videos: the language's rows are further down its order
        return $language < max($bound, $this->walkLanguageMost()) ? ['language', $indexes] : ['index'];
    }

    /**
     * How a page the first check did not settle is read by the visitor's language's videos a visitor can be shown
     * (sparsePlan()), where the table has LANGUAGE_SHOWN_INDEX with its 1.2.3 columns: one statement (showableProbe())
     * counts them by that index alone, per language alternative of the rule (in the language, without one) and status a
     * visitor can be shown a video in, up to WALK_BOUND each - its ranges leave out the other statuses (private, a draft
     * ...), the bin, the creators who are not active (named: the active creators who have videos, where they are at most
     * CategoryVideoCounts' list; else each entry's creator is checked) and the videos scheduled for later, so none of those
     * entries is read, whatever their number - and each import key's entries by its own index alone (no index tests an
     * import's showability), up to WALK_BOUND:
     *  - none, and no import: ['nothing'], an empty page at once;
     *  - fewer than WALK_BOUND (and every import key fewer than WALK_BOUND entries), a bounded read every way: where the
     *    language's entries nobody is shown (every status) are at most WALK_WINDOW (fewHidden(), a second statement by the
     *    language's index alone), the earlier passes' reads of a language this small: the newest list by its parts in its
     *    own order (['ids', partIds()]), another list by the language rule's own entries (['language', its indexes]: the
     *    database merges them by key); else ['ids', ...], the ids of the page's rows among the videos a visitor can be shown,
     *    each part read through that index as the page itself (shownIds(): the database reads only those rows and sorts
     *    them);
     *  - WALK_BOUND or more: ['many'] (sparsePlan()'s checks decide, knowing them many: without the category's own count
     *    and the second check's sample).
     * Null where the table has not that index (or the rule no language alternative), or an import key has WALK_BOUND
     * entries or more: sparsePlan()'s checks decide as they do without it.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @return array{0: string, 1?: list<int>}|null
     */
    private function shownPlan(QueryBuilder $base, string $index, array $alternatives, int $wanted): ?array
    {
        if (array_filter($alternatives, fn (array $alternative): bool => $alternative['column'] === 'language') === []
            || ! $this->indexReady(self::LANGUAGE_SHOWN_INDEX)) {
            return null;
        }

        $bound = max(1, $this->walkBound());
        $creators = $this->shownCreators();
        $probe = $this->showableProbe($base, $alternatives, $creators, $bound);
        $shown = array_sum($probe['showable']);

        if ($shown >= $bound) {
            return ['many'];
        }

        if ($probe['imports'] !== [] && max($probe['imports']) >= $bound) {
            return null; // an import key with many entries: sparsePlan()'s checks (its own entries looked at)
        }

        if ($shown === 0 && array_sum($probe['imports']) === 0) {
            return ['nothing']; // no video of the language can be shown, and no import: an empty page at once
        }

        if ($this->fewHidden($base, $alternatives, $probe['showable'])) {
            // few entries nobody is shown: the earlier passes' bounded reads of a language this small, which read its rows in
            // their index's order (the newest list's parts) or merged by key (the language rule's own entries) - at most
            // its rows a visitor can be shown + WALK_WINDOW, and the imports' few entries
            $parts = self::WALK_PART_INDEXES[$index] ?? null;

            return $parts !== null && $this->indexExists($parts)
                ? ['ids', $this->partIds($base, $alternatives, $parts, $wanted)]
                : ['language', array_values(array_unique(array_column($alternatives, 'index')))];
        }

        return ['ids', $this->shownIds($base, $alternatives, $creators, $wanted)];
    }

    /**
     * shownPlan()'s count, one statement, each part by an index alone: for each language alternative of the rule its videos
     * a visitor can be shown (showable()) per status, up to $bound each (summed per alternative), and each import key's
     * entries (candidates()), up to $bound.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @param  list<int>|null  $creators
     * @return array{showable: array<int, int>, imports: array<int, int>}
     */
    private function showableProbe(QueryBuilder $base, array $alternatives, ?array $creators, int $bound): array
    {
        $probe = $base->newQuery();

        foreach ($alternatives as $position => $alternative) {
            if ($alternative['column'] !== 'language') {
                $probe->selectSub($base->newQuery()->fromSub($this->candidates($base, $alternative)->limit($bound), 'avd_walk_import')->selectRaw('count(*)'), 'avd_walk_import_'.$position);

                continue;
            }

            foreach (self::SHOWN_STATUSES as $status) {
                $probe->selectSub($base->newQuery()->fromSub($this->showable($base, $alternative, $status, $creators)->limit($bound), 'avd_walk_showable')->selectRaw('count(*)'), 'avd_walk_showable_'.$position.'_'.$status->value);
            }
        }

        $row = (array) $probe->first();
        $showable = [];
        $imports = [];

        foreach ($alternatives as $position => $alternative) {
            if ($alternative['column'] !== 'language') {
                $imports[$position] = (int) ($row['avd_walk_import_'.$position] ?? $bound);

                continue;
            }

            $showable[$position] = 0;

            foreach (self::SHOWN_STATUSES as $status) {
                $showable[$position] += (int) ($row['avd_walk_showable_'.$position.'_'.$status->value] ?? $bound);
            }
        }

        return ['showable' => $showable, 'imports' => $imports];
    }

    /**
     * The videos a visitor can be shown of a language alternative ("language = ?" / "language is null") in one status, as
     * a query whose one column is avd_walk_id, read by LANGUAGE_SHOWN_INDEX alone: Video::scopeActive()'s rule (not in the
     * bin, uploaded, public and published or scheduled and due, of an active creator: the active creators named where they
     * are few, $creators, else each one checked), the status and, for a scheduled one, its due time as conditions of their
     * own, so the index's ranges are the language's, the status's, outside the bin, of each named creator, due.
     *
     * @param  array{column: string, type: string, value: mixed, index: string}  $alternative
     * @param  list<int>|null  $creators
     */
    private function showable(QueryBuilder $base, array $alternative, VideoStatus $status, ?array $creators): QueryBuilder
    {
        $table = (string) $base->from;
        $query = Video::query()->active(true, $creators)->toBase();
        $query->select($table.'.id as avd_walk_id');
        $this->nameIndexes($query, [self::LANGUAGE_SHOWN_INDEX]);
        self::whereAlternative($query, $table, $alternative);
        $query->where($table.'.status', $status);

        if ($status === VideoStatus::PLANNED) {
            $query->where($table.'.scheduled_at', '<=', now());
        }

        return $query;
    }

    /**
     * Whether the language alternatives hold at most WALK_WINDOW entries nobody is shown, of any status (shownPlan(): the
     * newest list's parts then pass at most that many before their rows, and a read of the language's own entries reads at
     * most that many more than the rows a visitor can be shown): each one's entries, every status, by its own index alone
     * (the language's), counted up to its videos a visitor can be shown ($showable) + WALK_WINDOW + 1, in one statement.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @param  array<int, int>  $showable  the language alternatives' videos a visitor can be shown (showableProbe())
     */
    private function fewHidden(QueryBuilder $base, array $alternatives, array $showable): bool
    {
        $table = (string) $base->from;
        $window = max(1, $this->walkWindow());
        $probe = $base->newQuery();

        foreach ($showable as $position => $rows) {
            $entries = $base->newQuery()->from($table)->select($table.'.id as avd_walk_id');
            $this->nameIndexes($entries, [$alternatives[$position]['index']]);
            self::whereAlternative($entries, $table, $alternatives[$position]);
            $probe->selectSub($base->newQuery()->fromSub($entries->limit($rows + $window + 1), 'avd_walk_entries')->selectRaw('count(*)'), 'avd_walk_entries_'.$position);
        }

        $row = (array) $probe->first();
        $hidden = 0;

        foreach ($showable as $position => $rows) {
            $hidden += max(0, (int) ($row['avd_walk_entries_'.$position] ?? $rows + $window + 1) - $rows);
        }

        return $hidden <= $window;
    }

    /**
     * The ids of the page's rows (and a few more) where the visitor's language's videos a visitor can be shown are fewer
     * than WALK_BOUND (shownPlan()): each language alternative and status read through LANGUAGE_SHOWN_INDEX, with the
     * active creators named and a scheduled video's due time (the index's ranges: the database reads only the rows a
     * visitor can be shown, then sorts them), each import key by its own index (fewer than WALK_BOUND entries), each read
     * as the page itself (every condition, its cursor, its order) up to the page's count, all in one statement. The page's
     * rows are among them: each of the page's first rows is among the first rows of the part it belongs to.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @param  list<int>|null  $creators
     * @return list<int>
     */
    private function shownIds(QueryBuilder $base, array $alternatives, ?array $creators, int $wanted): array
    {
        $table = (string) $base->from;
        $union = null;

        foreach ($alternatives as $alternative) {
            foreach ($alternative['column'] === 'language' ? self::SHOWN_STATUSES : [null] as $status) {
                $part = clone $base;
                $part->beforeQueryCallbacks = [];
                $part->offset = null;
                $part->select(self::idsFirst($base, $part->getGrammar()->wrap($table.'.id as avd_walk_id')))->limit($wanted);
                $this->nameIndexes($part, [$status === null ? $alternative['index'] : self::LANGUAGE_SHOWN_INDEX]);
                self::whereAlternative($part, $table, $alternative);

                if ($status !== null) {
                    $part->where($table.'.status', $status);

                    if ($creators !== null) {
                        $part->whereIn($table.'.user_id', $creators);
                    }

                    if ($status === VideoStatus::PLANNED) {
                        $part->where($table.'.scheduled_at', '<=', now());
                    }
                }

                $union = $union === null ? $part : $union->unionAll($part);
            }
        }

        return array_values(array_unique(array_map('intval', $union->pluck('avd_walk_id')->all())));
    }

    /**
     * The active creators who have videos, named in shownPlan()'s count and reads (App\Support\CategoryVideoCounts::activeCreators():
     * two short reads; null where they are more than its list: each entry's creator is checked then).
     *
     * @return list<int>|null
     */
    protected function shownCreators(): ?array
    {
        return CategoryVideoCounts::activeCreators();
    }

    /**
     * The entries of each alternative of the language rule a visitor could be shown (candidates(): by its index alone),
     * each counted up to $limit, in one statement.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @return list<int>
     */
    private function languageSizes(QueryBuilder $base, array $alternatives, int $limit): array
    {
        $probe = $base->newQuery();

        foreach ($alternatives as $position => $alternative) {
            $probe->selectSub($base->newQuery()->fromSub($this->candidates($base, $alternative)->limit($limit), 'avd_walk_size')->selectRaw('count(*)'), 'avd_walk_size_'.$position);
        }

        $row = (array) $probe->first();

        return array_map(fn (int $position): int => (int) ($row['avd_walk_size_'.$position] ?? $limit), array_keys($alternatives));
    }

    /**
     * A category's list: the entries of its category, whatever the page's place (the count below reads the whole category),
     * counted by the list's own index alone ($index: its leading columns the page holds to one value, category_id = ?), up
     * to WALK_CATEGORY_MOST, in one statement; WALK_CATEGORY_MOST where the page holds none of them to one value.
     */
    private function categoryEntries(QueryBuilder $base, string $index): int
    {
        $table = (string) $base->from;
        $most = max(1, $this->walkCategoryMost());
        $entries = $base->newQuery()->from($table)->select($table.'.id')->forceIndex($index);
        $held = 0;

        foreach (self::WALK_INDEXES[$index] ?? [] as $column) {
            $where = self::heldWhere($base->wheres, $column);

            if ($where === null) {
                break;
            }

            $entries->where($table.'.'.$column, '=', $where['value']);
            $held++;
        }

        if ($held === 0) {
            return $most;
        }

        $row = (array) $base->newQuery()
            ->selectSub($base->newQuery()->fromSub($entries->limit($most), 'avd_walk_category_size')->selectRaw('count(*)'), 'avd_walk_category_size')
            ->first();

        return (int) ($row['avd_walk_category_size'] ?? $most);
    }

    /**
     * A category's list (WALK_SHOWN_INDEXES), one statement: how many of the page's rows (every condition of the page, its
     * place, up to its count) its category has, read by $index, which holds every column those conditions test, so the
     * database reads the index alone (and each passing entry's creator), never a video's row: at most the category's
     * entries a visitor could be shown (callers count the category first: categoryEntries()), and as few as the page's
     * count where they are many.
     */
    private function shownCount(QueryBuilder $base, string $index, int $wanted): int
    {
        $probe = clone $base;
        $probe->beforeQueryCallbacks = [];
        $probe->orders = null;
        $probe->offset = null;
        $probe->select(DB::raw('1'))->limit($wanted);
        $this->nameIndexes($probe, [$index]);

        $row = (array) $base->newQuery()
            ->selectSub($base->newQuery()->fromSub($probe, 'avd_walk_category')->selectRaw('count(*)'), 'avd_walk_category')
            ->first();

        return (int) ($row['avd_walk_category'] ?? $wanted);
    }

    /**
     * The ids of the entries of $index from the page's place (its cursor's conditions on the index's own columns), in
     * the page's order, as a query whose one column is avd_walk_id (the index alone); null when a condition of the page
     * is not one walk() can follow (an "or" at the top).
     *
     * @param  list<string>  $columns  the index's columns
     * @param  list<string>  $order  the page's order (its columns, newest first)
     */
    private function walkWindowQuery(QueryBuilder $base, string $index, array $columns, array $order): ?QueryBuilder
    {
        $table = (string) $base->from;
        // its own column name: the page's conditions name id unqualified (a cursor's)
        $window = $base->newQuery()->from($table)->select($table.'.id as avd_walk_id')->forceIndex($index);

        foreach ($base->wheres as $where) {
            if (strtolower((string) ($where['boolean'] ?? 'and')) !== 'and') {
                return null;
            }

            // the page's place: a condition on the index's own columns (a cursor's)
            if (($bindings = self::walkBindings($where, $columns)) !== null) {
                $window->wheres[] = $where;
                $window->addBinding($bindings, 'where');
            }
        }

        foreach ($order as $column) {
            $window->orderByDesc($column);
        }

        return $window;
    }

    /**
     * The second check of walk(), one statement bounded by the indexes alone: for each alternative of the language rule
     * its entries a visitor could be shown (candidates(): up to WALK_LANGUAGE_MOST each, or with $partsOnly only past
     * WALK_LANGUAGE_ROWS), and how many of the page's rows (every condition of the page, up to its count) are among
     * WALK_LANGUAGE_ROWS entries of each alternative: half of them its first entries as its index holds them, half its
     * last ones (the index read backwards), so neither half is sorted (an ORDER BY outside the index's order made MySQL
     * and MariaDB sort every entry of a big alternative) and a hidden run at one end of the index does not hide the rest
     * (an old batch in the bin, a new import batch in the bin). An alternative with no more entries than that is seen
     * whole. Without $withSample (a category's own count found the page's rows already: sparsePlan()) only the counts, and
     * the page's count as the rows found.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @return array{entries: array<int, int>, shown: int}
     */
    private function languageProbe(QueryBuilder $base, array $alternatives, bool $partsOnly, int $wanted, bool $withSample = true): array
    {
        $table = (string) $base->from;
        $looked = max(1, $this->walkLanguageRows());
        // each alternative's entries counted past WALK_LANGUAGE_ROWS (else it was seen whole) and, where a big language is
        // compared with the list (sparsePlan()), up to WALK_LANGUAGE_MOST
        $count = $partsOnly ? $looked + 1 : max($this->walkBound(), $looked + 1, $this->walkLanguageMost());
        $probe = $base->newQuery();
        $sample = null;

        foreach ($alternatives as $position => $alternative) {
            $probe->selectSub($base->newQuery()->fromSub($this->candidates($base, $alternative)->limit($count), 'avd_walk_language')->selectRaw('count(*)'), 'avd_walk_language_'.$position);

            if (! $withSample) {
                continue;
            }

            // the last entries need the index's columns (its order, backwards); without them every entry looked at is one
            // of the first, so an alternative with no more than $looked entries is still seen whole
            $columns = $looked > 1 ? $this->indexColumns($alternative['index']) : null;

            if ($columns !== null && in_array($alternative['type'], ['=', 'null'], true) && ($columns[0] ?? null) === $alternative['column']) {
                // its own column, held to one value (language = ? / is null), is no part of the order: named in it, MariaDB
                // 10.11 sorted every entry of a "language is null" range (159 ms at 300,000 videos, 3 ms read backwards)
                array_shift($columns);
            }

            $last = $columns === null || $columns === [] ? 0 : intdiv($looked, 2);
            $parts = [$this->candidates($base, $alternative)->limit($looked - $last)];

            if ($last > 0) {
                $newest = $this->candidates($base, $alternative)->limit($last);

                foreach ($columns as $column) {
                    $newest->orderByDesc($table.'.'.$column);
                }

                $parts[] = $newest;
            }

            foreach ($parts as $part) {
                $sample = $sample === null ? $part : $sample->union($part);
            }
        }

        if ($sample !== null) {
            $shown = clone $base;
            $shown->beforeQueryCallbacks = [];
            $shown->indexHint = null;
            $shown->orders = null;
            $shown->offset = null;
            // the entries looked at first, then each one's row by the key
            $shown->fromSub($sample, 'avd_walk_sample')
                ->select(self::idsFirst($base, '1'))
                ->join($table, $table.'.id', '=', 'avd_walk_sample.avd_walk_id')
                ->limit($wanted);
            $probe->selectSub($base->newQuery()->fromSub($shown, 'avd_walk_shown')->selectRaw('count(*)'), 'avd_walk_shown');
        }

        $row = (array) $probe->first();
        $entries = [];

        foreach (array_keys($alternatives) as $position) {
            $entries[$position] = (int) ($row['avd_walk_language_'.$position] ?? $count);
        }

        return ['entries' => $entries, 'shown' => (int) ($row['avd_walk_shown'] ?? $wanted)];
    }

    /**
     * The third check of walk() (a big language only), one statement: the index's entries from the page's place (by the
     * index alone, up to WALK_BOUND), and with $windowLanguage how many of the first check's entries are the language's
     * own (the language rule alone, on the rows the first check just read; only where the plan still asks it).
     *
     * @param  array<string, mixed>  $rule  the page's language rule (its where)
     * @return array{rest: int, window: int}
     */
    private function listProbe(QueryBuilder $base, QueryBuilder $window, array $rule, bool $windowLanguage = true): array
    {
        $table = (string) $base->from;
        $bound = max(1, $this->walkBound());
        $probe = $base->newQuery()
            ->selectSub($base->newQuery()->fromSub((clone $window)->limit($bound), 'avd_walk_rest')->selectRaw('count(*)'), 'avd_walk_rest');

        if ($windowLanguage) {
            $seen = $base->newQuery()
                ->fromSub((clone $window)->limit(max(1, $this->walkWindow())), 'avd_walk_seen')
                ->select(self::idsFirst($base, '1'))
                ->join($table, $table.'.id', '=', 'avd_walk_seen.avd_walk_id');
            $seen->wheres[] = $rule;
            $seen->addBinding($rule['query']->getRawBindings()['where'], 'where');
            $probe->selectSub($base->newQuery()->fromSub($seen, 'avd_walk_window')->selectRaw('count(*)'), 'avd_walk_window_language');
        }

        $row = (array) $probe->first();

        return ['rest' => (int) ($row['avd_walk_rest'] ?? $bound), 'window' => (int) ($row['avd_walk_window_language'] ?? 0)];
    }

    /**
     * The entries of an alternative of the language rule a visitor could be shown, by its index alone, as a query whose
     * one column is avd_walk_id: the language's (or no language's) entries of the statuses a visitor can be shown
     * (SHOWN_STATUSES, the index's next column), every import's.
     *
     * @param  array{column: string, type: string, value: mixed, index: string}  $alternative
     */
    private function candidates(QueryBuilder $base, array $alternative): QueryBuilder
    {
        $table = (string) $base->from;
        $query = $base->newQuery()->from($table)->select($table.'.id as avd_walk_id');
        $this->nameIndexes($query, [$alternative['index']]);
        self::whereAlternative($query, $table, $alternative);

        if ($alternative['column'] === 'language') {
            $query->whereIn($table.'.status', self::SHOWN_STATUSES);
        }

        return $query;
    }

    /**
     * The ids of the page's rows (and a few more) where an index keeps the walk index's order within each language and
     * status ($parts, WALK_PART_INDEXES): each language and status in it, and each kind of import by its own index, read
     * as the page itself (every condition, its cursor, its order) up to the page's count, all in one statement. The page's
     * rows are among them: the first rows of the page are the first rows of the parts they belong to.
     *
     * @param  list<array{column: string, type: string, value: mixed, index: string}>  $alternatives
     * @return list<int>
     */
    private function partIds(QueryBuilder $base, array $alternatives, string $parts, int $wanted): array
    {
        $table = (string) $base->from;
        $union = null;

        foreach ($alternatives as $alternative) {
            foreach ($alternative['column'] === 'language' ? self::SHOWN_STATUSES : [null] as $status) {
                $part = clone $base;
                $part->beforeQueryCallbacks = [];
                $part->offset = null;
                $part->select(self::idsFirst($base, $part->getGrammar()->wrap($table.'.id as avd_walk_id')))->limit($wanted);
                $this->nameIndexes($part, [$status === null ? $alternative['index'] : $parts]);
                self::whereAlternative($part, $table, $alternative);

                if ($status !== null) {
                    $part->where($table.'.status', $status);
                }

                $union = $union === null ? $part : $union->unionAll($part);
            }
        }

        return array_values(array_unique(array_map('intval', $union->pluck('avd_walk_id')->all())));
    }

    /**
     * The alternatives of the visitor's language rule (Video::scopeForLocale(): "language = ?" or "language is null", an
     * import key "is not null", joined by "or") with the index each is read by; null when one of them is another
     * condition or the table lacks its index.
     *
     * @return list<array{column: string, type: string, value: mixed, index: string}>|null
     */
    private function languageAlternatives(QueryBuilder $rule): ?array
    {
        $alternatives = [];

        foreach ($rule->wheres as $position => $where) {
            $column = self::column($where['column'] ?? null);
            $type = match ($where['type'] ?? null) {
                'Null' => 'null',
                'NotNull' => 'not null',
                'Basic' => ($where['operator'] ?? null) === '=' && ! (($where['value'] ?? null) instanceof Expression) ? '=' : null,
                default => null,
            };
            $wanted = $column === 'language' ? ['=', 'null'] : ['not null'];

            if ($type === null || ! isset(self::LANGUAGE_INDEXES[$column]) || ! in_array($type, $wanted, true)
                || ($position > 0 && strtolower((string) ($where['boolean'] ?? '')) !== 'or')
                || ! $this->indexExists(self::LANGUAGE_INDEXES[$column])) {
                return null;
            }

            $alternatives[] = ['column' => $column, 'type' => $type, 'value' => $where['value'] ?? null, 'index' => self::LANGUAGE_INDEXES[$column]];
        }

        return $alternatives === [] ? null : $alternatives;
    }

    /**
     * Adds an alternative of the language rule to $query as its own condition.
     *
     * @param  array{column: string, type: string, value: mixed, index: string}  $alternative
     */
    private static function whereAlternative(QueryBuilder $query, string $table, array $alternative): void
    {
        $column = $table.'.'.$alternative['column'];

        match ($alternative['type']) {
            'null' => $query->whereNull($column),
            'not null' => $query->whereNotNull($column),
            default => $query->where($column, '=', $alternative['value']),
        };
    }

    /**
     * The position in $wheres of the visitor's language rule (Video::scopeForLocale(): a group whose conditions name only
     * the language and the import keys, the language among them), or null.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private static function languageRule(array $wheres): ?int
    {
        foreach ($wheres as $position => $where) {
            if (($where['type'] ?? null) !== 'Nested' || ! ($where['query'] ?? null) instanceof QueryBuilder
                || strtolower((string) ($where['boolean'] ?? 'and')) !== 'and' || $where['query']->wheres === []) {
                continue;
            }

            $named = [];

            foreach ($where['query']->wheres as $inner) {
                if (! in_array($inner['type'] ?? null, ['Basic', 'Null', 'NotNull'], true) || (($inner['value'] ?? null) instanceof Expression)) {
                    continue 2;
                }

                $named[] = self::column($inner['column'] ?? null);
            }

            if (in_array('language', $named, true) && array_diff($named, ['language', 'avdb_id', 'xvid_id']) === []) {
                return $position;
            }
        }

        return null;
    }

    /**
     * "STRAIGHT_JOIN $columns" on MySQL / MariaDB, $columns elsewhere: a check reads its table of ids (or its index) first,
     * then each row by the key, whatever the server estimates (right after a mass update its estimates count the entries
     * waiting for purge: MariaDB 10.11 then read every video of every creator for one check, 46 s).
     */
    private static function idsFirst(QueryBuilder $base, string $columns): Expression
    {
        return DB::raw(in_array($base->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'STRAIGHT_JOIN '.$columns : $columns);
    }

    /** No video of the visitor's language can be shown from the page's place: an empty page, read at once. */
    protected function readNothing(QueryBuilder $base): void
    {
        $base->whereRaw('0 = 1');
    }

    /** The page read by its rows' ids (partIds(): every condition of the page still applies), by the table's key. */
    protected function readByIds(QueryBuilder $base, array $ids): void
    {
        if ($ids === []) {
            $this->readNothing($base);

            return;
        }

        $base->indexHint = null;
        $base->whereIn($base->from.'.id', $ids);
    }

    /**
     * The page read by the language rule's indexes (the language's and the imports'): the database reads the few rows
     * they hold, or merges them, and sorts them; never in another index's order.
     *
     * @param  list<string>  $indexes
     */
    protected function readByLanguage(QueryBuilder $base, array $indexes): void
    {
        $this->nameIndexes($base, $indexes);
    }

    /**
     * The page read by the index that holds every column of the page's conditions after its leading one (WALK_SHOWN_INDEXES:
     * a category's entries): the database tests each of the category's entries by the index alone, reads the rows of the
     * few that pass and sorts them; never the list's order past rows that are not there.
     */
    protected function readByShownIndex(QueryBuilder $base, string $index): void
    {
        $this->nameIndexes($base, [$index]);
    }

    /**
     * Names $indexes for $query (MySQL / MariaDB: FORCE INDEX; with several, the database reads by one of them or merges
     * them).
     *
     * @param  list<string>  $indexes
     */
    protected function nameIndexes(QueryBuilder $query, array $indexes): void
    {
        $query->forceIndex(implode(', ', array_values(array_unique($indexes))));
    }

    /** How many entries of its index walk() checks from a page's place (WALK_WINDOW). */
    protected function walkWindow(): int
    {
        return self::WALK_WINDOW;
    }

    /** How many entries of each alternative of the visitor's language walk()'s second check looks at (WALK_LANGUAGE_ROWS). */
    protected function walkLanguageRows(): int
    {
        return self::WALK_LANGUAGE_ROWS;
    }

    /** The most entries walk() counts of a read whose length it can know (WALK_BOUND). */
    protected function walkBound(): int
    {
        return self::WALK_BOUND;
    }

    /** The most entries of the visitor's language a page on a long list may read by its own indexes (WALK_LANGUAGE_MOST). */
    protected function walkLanguageMost(): int
    {
        return self::WALK_LANGUAGE_MOST;
    }

    /** The most entries left of a category's list walk() counts the category's rows in (WALK_CATEGORY_MOST). */
    protected function walkCategoryMost(): int
    {
        return self::WALK_CATEGORY_MOST;
    }

    /**
     * The bindings of $where when it names only the columns of a walk's index (a cursor's condition), else null.
     *
     * @param  array<string, mixed>  $where
     * @param  list<string>  $columns
     * @return list<mixed>|null
     */
    private static function walkBindings(array $where, array $columns): ?array
    {
        switch ($where['type'] ?? null) {
            case 'Basic':
                return in_array(self::column($where['column'] ?? null), $columns, true) && ! (($where['value'] ?? null) instanceof Expression)
                    ? [$where['value']]
                    : null;
            case 'Null':
            case 'NotNull':
                return in_array(self::column($where['column'] ?? null), $columns, true) ? [] : null;
            case 'raw':
                // App\Support\SafeCursor: nothing comes after the cursor's empty value going down
                return trim((string) ($where['sql'] ?? '')) === '0 = 1' ? [] : null;
            case 'Nested':
                if (! ($where['query'] ?? null) instanceof QueryBuilder) {
                    return null;
                }

                foreach ($where['query']->wheres as $inner) {
                    if (self::walkBindings($inner, $columns) === null) {
                        return null;
                    }
                }

                return array_values($where['query']->getRawBindings()['where']);
        }

        return null;
    }

    /**
     * Whether the page's own conditions hold $column to one value: a top-level "column = value" (a category's list:
     * category_id = ?).
     *
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private static function heldToOneValue(array $wheres, string $column): bool
    {
        return self::heldWhere($wheres, $column) !== null;
    }

    /**
     * The page's own top-level "column = value" condition on $column (a category's list: category_id = ?), or null.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     * @return array<string, mixed>|null
     */
    private static function heldWhere(array $wheres, string $column): ?array
    {
        foreach ($wheres as $where) {
            if (($where['type'] ?? null) === 'Basic' && ($where['operator'] ?? null) === '='
                && strtolower((string) ($where['boolean'] ?? 'and')) === 'and'
                && self::column($where['column'] ?? null) === $column
                && ! (($where['value'] ?? null) instanceof Expression)) {
                return $where;
            }
        }

        return null;
    }

    /** A column's own name ("videos.published_at" -> "published_at"); null for an expression. */
    private static function column(mixed $column): ?string
    {
        if (! is_string($column)) {
            return null;
        }

        $dot = strrpos($column, '.');

        return $dot === false ? $column : substr($column, $dot + 1);
    }

    /** Names $indexName for $query on MySQL / MariaDB when the table has it; whether it did. */
    protected function applyIndex(Builder $query, string $indexName): bool
    {
        if (! $this->indexExists($indexName)) {
            return false;
        }

        $query->forceIndex($indexName);

        return true;
    }

    /** Whether the table has the index $name: the table's indexes, read once a process (MySQL / MariaDB only: App\Support\VideoIndexes::columns()). */
    protected function indexExists(string $name): bool
    {
        return VideoIndexes::columns($name) !== null;
    }

    /**
     * Whether the table has the index $name with the columns 1.2.3 gives it (App\Support\VideoIndexes::ready(): MySQL /
     * MariaDB only); an index the scheduler has not rebuilt yet on a big table is not named for what it cannot test.
     */
    protected function indexReady(string $name): bool
    {
        return VideoIndexes::ready($name);
    }

    /**
     * The columns of the videos index $name in order: the table's own on MySQL / MariaDB (App\Support\VideoIndexes::columns()),
     * else the migrations' definition of a LANGUAGE_INDEXES index; null when not known.
     *
     * @return list<string>|null
     */
    protected function indexColumns(string $name): ?array
    {
        return VideoIndexes::columns($name) ?? (self::LANGUAGE_INDEX_COLUMNS[$name] ?? null);
    }
}

