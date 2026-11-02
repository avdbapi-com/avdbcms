<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Support\VideoIndexes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Look-around DB-RV-1 (review of R3-022): a feed read in its index's order reads as many entries as it takes to find its
 * page, so where almost no video fits the visitor's language (a site whose own uploads all have the site's language,
 * visited in another one) it read the whole index. Right before a page is read, PublicVideoFeedService::walk() checks the
 * first WALK_WINDOW entries of the index from the page's place: the index stays named when they hold the page or the
 * index ends there. Else a second check, bounded by the indexes alone, looks at the visitor's language: the videos of
 * it a visitor can be shown (the same rules as the page: public or due, not in the bin, of an active creator), the
 * checks' DB-V1-1 and DB-V2-1:
 *  - none: an empty page at once;
 *  - the newest list: each language and status (and each kind of import) gives its first rows on its own, in the order
 *    the (language, status, published_at, id) index keeps, and the page reads them by their key;
 *  - other lists: the language's own entries where they are fewer than the list's (a language with few videos, or whose
 *    videos are mostly hidden); for a big language on a long list, the index's order past the first check only where the
 *    second check found a page's worth of rows a visitor can be shown among the entries it looked at (half from each end
 *    of each index): then where the first check's entries were the language's own, nobody shown (a run above the page:
 *    an import batch in the bin, a banned creator's uploads, a batch made private, videos scheduled for later), its own
 *    entries where they were other languages' videos (its rows are further down the list's order) and it has fewer than
 *    WALK_LANGUAGE_MOST. Where it found fewer (the third check's DB-V3-1: a language whose many videos are all hidden),
 *    the language's own entries unless the list ends soon: never the whole index for rows that are not there.
 *  - a category's list whose visitor's language has more entries than the second check looks at (counted by its indexes
 *    alone), in a category of fewer than WALK_CATEGORY_MOST entries, first counts its own rows the page could show by the
 *    index that tests every condition of the page, alone (from the page's place, up to the page's count): none, an empty
 *    page at once; fewer than the page's count, read by that index; a page's worth, as above (the second check without
 *    its sample of rows: the count found them).
 * Where the table has LANGUAGE_SHOWN_INDEX (the fifth pass, DB-V3-1 at its root; the tests opt in with $shownIndex, the
 * others are a site before the scheduler built it), the language's videos a visitor can be shown are counted by that
 * index alone first (its ranges leave out the bin, the other statuses, the creators who are not active, the videos
 * scheduled for later): none, an empty page at once whatever the hidden videos; fewer than WALK_BOUND, the page read by
 * them (through that index; the newest list by its parts where its hidden entries are few); more, the checks above.
 * The rows are the same every way.
 *
 * SQLite names an index too ("indexed by"), so the checks run here as on MySQL / MariaDB (feedsNamingIndexes()).
 */
class PublicFeedWalkTest extends TestCase
{
    use RefreshDatabase;

    /** PublicVideoFeedService::LANGUAGE_SHOWN_INDEX (App\Support\VideoIndexes::WANTED: the fifth pass). */
    public const SHOWN_INDEX = 'videos_language_active_idx';

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function ($query): void {
            $this->statements[] = $query->sql;
        });
    }

    public function test_a_page_most_videos_fit_keeps_its_index_and_one_no_video_fits_is_empty_at_once(): void
    {
        $this->videos(12, 'en');
        $feeds = $this->feedsNamingIndexes(window: 5);

        foreach (['latest' => 'videos_published_id_idx', 'trending' => 'videos_views_published_id_idx', 'mostCommented' => PublicVideoFeedService::COMMENTS_ORDER_INDEX] as $feed => $index) {
            app()->setLocale('en');
            $this->statements = [];
            $this->assertCount(4, $feeds->{$feed}()->limit(4)->get());
            $this->assertCount(1, $this->checks(), $feed.'(): the check before the page');
            $this->assertSame([], $this->languageChecks(), $feed.'(): the first check settles it');
            $this->assertStringContainsString('indexed by '.$index, $this->page(), $feed.'(): every video fits: read in the index\'s order');

            // 'ar' is not the default language: the site's own uploads in English are not for it (Video::forLocale())
            app()->setLocale('ar');
            $this->statements = [];
            $this->assertCount(0, $feeds->{$feed}()->limit(4)->get());
            $this->assertCount(1, $this->checks(), $feed.'(): the check before the page');
            $this->assertCount(1, $this->languageChecks(), $feed.'(): then the visitor\'s language: no video of it');
            $this->assertStringContainsString('0 = 1', $this->page(), $feed.'(): an empty page at once, no entry read');
            $this->assertSame([], $feeds->languageReads);
        }

        $category = $this->category('Walked');
        $this->videos(6, 'en', category: $category);
        app()->setLocale('en');
        $this->statements = [];
        $this->assertCount(4, $feeds->categoryId($category->id)->limit(4)->get());
        $this->assertCount(1, $this->checks(), 'categoryId(): the check before the page');
        $this->assertStringContainsString('indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0], $this->page(), 'categoryId(): read in its category\'s order');

        app()->setLocale('ar');
        $this->statements = [];
        $this->assertCount(0, $feeds->categoryId($category->id)->limit(4)->get());
        $this->assertCount(1, $this->checks(), 'categoryId(): the check before the page');
        // no entry of the language at all (fewer than the second check looks at): the second check settles it, the
        // category's own rows are not counted
        $this->assertCount(1, $this->languageChecks(), 'categoryId(): then the visitor\'s language');
        $this->assertSame([], $this->categoryChecks());
        $this->assertStringContainsString('0 = 1', $this->page(), 'categoryId(): an empty page at once');
    }

    /**
     * The check's DB-V1-1: the newest entries of an index are not shown (an import batch in the bin, a banned creator's
     * uploads, a batch made private, videos scheduled for later, the most viewed videos in the bin): the first window
     * holds none of the page, but the page comes right below them and the language has many videos a visitor can be
     * shown, so the lists keep their index (the newest list reads its parts in that same order) and give the same rows.
     */
    public function test_a_page_right_below_a_run_of_entries_nobody_is_shown_keeps_its_index(): void
    {
        $category = $this->category('Run');
        $this->videos(10, 'en', hoursAgo: 30, category: $category);
        $plain = app(PublicVideoFeedService::class);
        $walking = $this->feedsNamingIndexes(window: 3, language: 8, bound: 6);
        app()->setLocale('en');

        foreach (['bin', 'banned creator', 'private', 'scheduled for later'] as $kind) {
            $hidden = $this->hiddenRun($kind, 6, $category);

            foreach (['latest' => [[], 'videos_published_id_idx'], 'trending' => [[], 'videos_views_published_id_idx'], 'categoryId' => [[$category->id], PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0]]] as $feed => [$arguments, $index]) {
                if ($feed === 'trending' && $kind === 'scheduled for later') {
                    continue; // a scheduled video has no views yet: never at the top of the views order
                }

                $this->statements = [];
                $walking->languageReads = [];
                $rows = $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all();

                $this->assertSame($plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all(), $rows, "{$kind}, {$feed}(): the same rows");
                $this->assertCount(4, $rows, "{$kind}, {$feed}()");
                $this->assertSame([], array_values(array_intersect($rows, $hidden)), "{$kind}, {$feed}(): none of the hidden ones");
                $this->assertCount(1, $this->checks(), "{$kind}, {$feed}(): the first window holds none");
                $this->assertCount(1, $this->languageChecks(), "{$kind}, {$feed}(): the visitor's language has many videos a visitor can be shown");
                $this->assertSame([], $walking->languageReads, "{$kind}, {$feed}(): never every video of the language");

                if ($feed === 'categoryId') {
                    // the language bigger than the second check's look (10 or 16 entries, it looks at 8): the category's
                    // entries and its own rows counted first, a page's worth (the 4 below the run): the fourth pass's checks,
                    // the second one without its sample of rows (the count found them)
                    $this->assertCount(1, $this->languageSizes(), "{$kind}, categoryId(): the language's entries, by its indexes alone");
                    $this->assertCount(1, $this->categorySizes(), "{$kind}, categoryId()");
                    $this->assertCount(1, $this->categoryChecks(), "{$kind}, categoryId()");
                    $this->assertStringNotContainsString('avd_walk_shown', $this->languageChecks()[0], "{$kind}, categoryId(): no sample of rows");
                    $this->assertSame([], $walking->shownReads, "{$kind}, categoryId()");
                } else {
                    // the checks of the fourth pass alone (the second one with its sample), no count of a category
                    $this->assertSame([], $this->languageSizes(), "{$kind}, {$feed}()");
                    $this->assertSame([], $this->categorySizes(), "{$kind}, {$feed}()");
                    $this->assertSame([], $this->categoryChecks(), "{$kind}, {$feed}()");
                    $this->assertStringContainsString('as "avd_walk_shown"', $this->languageChecks()[0], "{$kind}, {$feed}(): its sample of rows");
                }

                if ($feed === 'latest') {
                    $this->assertCount(1, $this->parts(), "{$kind}, latest(): its parts in their index's order");
                    $this->assertStringContainsString('indexed by videos_language_status_published_id_idx', $this->parts()[0]);
                    $this->assertStringContainsString('"videos"."id" in (', $this->page(), "{$kind}, latest(): the page reads the parts' rows by their key");
                    // the default language's rule has no imports: read by its parts whatever the counts, so its entries are
                    // counted only past what the check looks at (9 = 8 + 1) and the list's own entries not at all
                    $this->assertStringNotContainsString('avd_walk_rest', $this->languageChecks()[0]);
                    $this->assertStringContainsString('"videos"."status" in (?, ?) limit 9)', $this->languageChecks()[0]);
                } else {
                    $this->assertStringContainsString('indexed by '.$index, $this->page(), "{$kind}, {$feed}(): read in its index's order below them");
                }
            }

            Video::withTrashed()->whereKey($hidden)->forceDelete();
            User::query()->whereNotNull('banned_at')->delete();
        }
    }

    /**
     * The check's DB-V2-1: the visitor's language has many videos, but none a visitor can be shown (in the bin, private,
     * a banned creator's, scheduled for later): the page is empty at once where the second check saw every entry of the
     * language (a category's list: where its own count found none of the page's rows, before the second check), and never
     * read in the index's order where it did not - also where the language has more entries than
     * WALK_BOUND and WALK_LANGUAGE_MOST and they are the list's first entries (the third check's DB-V3-1: the fourth pass
     * read the list's whole index there).
     */
    public function test_a_visitors_language_whose_videos_are_all_hidden_gives_an_empty_page_at_once(): void
    {
        $category = $this->category('Hidden');
        $this->videos(8, 'en', hoursAgo: 2, category: $category);
        $plain = app(PublicVideoFeedService::class);
        $feeds = ['latest' => [], 'trending' => [], 'mostCommented' => [], 'categoryId' => [$category->id]];
        $indexes = ['latest' => 'videos_published_id_idx', 'trending' => 'videos_views_published_id_idx', 'mostCommented' => PublicVideoFeedService::COMMENTS_ORDER_INDEX, 'categoryId' => PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0]];

        foreach (['bin', 'banned creator', 'private', 'scheduled for later'] as $kind) {
            $hidden = $this->hiddenRun($kind, 5, $category, hoursAgo: 30, language: 'ar');
            app()->setLocale('ar');

            foreach ($feeds as $feed => $arguments) {
                // every entry of the language seen (5 or fewer: a private video is not even an entry of it)
                $walking = $this->feedsNamingIndexes(window: 3, language: 8, bound: 20);
                $this->statements = [];
                $this->assertSame([], $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$kind}, {$feed}()");
                $this->assertSame([], $plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all());
                $this->assertCount(1, $this->languageChecks(), "{$kind}, {$feed}(): every entry of the language seen");
                $this->assertSame([], $this->categoryChecks(), "{$kind}, {$feed}()");
                $this->assertSame([], $this->parts(), "{$kind}, {$feed}(): nothing else read");
                $this->assertStringContainsString('0 = 1', $this->page(), "{$kind}, {$feed}(): an empty page at once");

                // more entries than the check looks at, fewer than WALK_BOUND: the language's own entries (the newest list:
                // its parts; a category's list its own rows, counted first: none), never every entry of the index
                $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 20);
                $this->statements = [];
                $this->assertSame([], $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$kind}, {$feed}()");

                if ($feed !== 'categoryId' || $kind === 'private') {
                    $this->assertSame([], $this->categoryChecks(), "{$kind}, {$feed}(): a bounded read already");
                }

                if ($kind === 'private') {
                    $this->assertStringContainsString('0 = 1', $this->page(), 'private videos are not entries a visitor could be shown: none');
                } elseif ($feed === 'categoryId') {
                    // the language bigger than the second check's look (5 entries, it looks at 2): the category's own rows
                    // counted first, none: an empty page at once, no second check
                    $this->assertCount(1, $this->categoryChecks(), "{$kind}, categoryId(): its own rows counted");
                    $this->assertSame([], $this->languageChecks(), "{$kind}, categoryId(): the category decides");
                    $this->assertStringContainsString('0 = 1', $this->page(), "{$kind}, categoryId(): an empty page at once");
                    $this->assertSame([], $walking->languageReads);
                } elseif ($feed === 'latest') {
                    $this->assertCount(1, $this->parts());
                    $this->assertStringContainsString('0 = 1', $this->page(), "{$kind}, latest(): the parts hold no row");
                } else {
                    $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "{$kind}, {$feed}(): the language's own entries");
                }

                // DB-V3-1: more entries than the check looks at, than WALK_BOUND (4) and than WALK_LANGUAGE_MOST (4), the most
                // viewed and most commented of the list (the first check's entries: the language's own), none a visitor
                // can be shown: never the list's order past the first check; another list the language's own entries, the
                // newest list its parts, a category's list its own rows counted (none: an empty page at once)
                $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: 4);
                $this->statements = [];
                $this->assertSame([], $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$kind}, {$feed}() past the bounds");

                if ($kind === 'private' || $feed === 'latest') {
                    // no entry a visitor could be shown / the parts hold no row: "0 = 1", the database reads nothing
                    $this->assertStringContainsString('0 = 1', $this->page(), "{$kind}, {$feed}(): an empty page at once");
                    $this->assertSame([], $this->categoryChecks());

                    continue;
                }

                if ($feed === 'categoryId') {
                    // the language bigger than the second check's look (5 entries, it looks at 2): the category's entries (13,
                    // fewer than WALK_CATEGORY_MOST: its order's index alone) and its own rows the page could show (the index
                    // that tests every condition, alone), before the second check: none
                    $this->assertCount(1, $this->languageSizes(), "{$kind}, categoryId(): the language's entries, by its indexes alone");
                    $this->assertSame([], $this->languageChecks(), "{$kind}, categoryId(): no second check");
                    $this->assertCount(1, $this->categorySizes(), "{$kind}, categoryId(): its entries counted");
                    $this->assertCount(1, $this->categoryChecks(), "{$kind}, categoryId(): its own rows counted");
                    $this->assertSame([], $this->listChecks(), "{$kind}, categoryId(): the category decides");
                    $this->assertStringContainsString('0 = 1', $this->page(), "{$kind}, categoryId(): an empty page at once");
                    $this->assertSame([], $walking->languageReads);
                    $this->assertSame([], $walking->shownReads);

                    continue;
                }

                $this->assertStringNotContainsString('indexed by '.$indexes[$feed], $this->page(), "{$kind}, {$feed}(): never the list's order for rows that are not there");
                $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "{$kind}, {$feed}(): the language's own entries");
                $this->assertSame([], $walking->shownReads);
                $this->assertSame([], $this->categoryChecks());
            }

            app()->setLocale('en');
            Video::withTrashed()->whereKey($hidden)->forceDelete();
            User::query()->whereNotNull('banned_at')->delete();
        }
    }

    /**
     * The third check's DB-V3-1, its own two cases (verify3's V3HiddenBigLanguageTest, and a category's list): the
     * visitor's language has more entries than the second check looks at and than WALK_BOUND, none a visitor can be shown.
     * (a) They are the list's first entries (the most viewed and most commented videos, in the bin): the first check's
     * entries are the language's own. (b) There are more of them than WALK_LANGUAGE_MOST, below every other video. The
     * fourth pass read these pages in the list's index order, to its end on a big site; now by the language's own entries
     * (a category's list: its category's own entries by the index that tests every condition of the page), the same rows.
     */
    public function test_a_language_whose_many_videos_are_all_hidden_is_never_read_in_the_lists_order(): void
    {
        foreach (['a: at the top of the lists' => [1000, 0, 40], 'b: past WALK_LANGUAGE_MOST, below every other video' => [0, 100, 6]] as $case => [$hiddenViews, $shownViews, $most]) {
            $category = $this->category('Hidden '.$case[0]);
            $this->rated(10, 'en', $shownViews, $category);
            $hidden = $this->rated(8, 'ar', $hiddenViews, $category, binned: true);
            $plain = app(PublicVideoFeedService::class);
            $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: $most);
            app()->setLocale('ar');

            foreach (['trending' => [[], 'videos_views_published_id_idx'], 'mostCommented' => [[], PublicVideoFeedService::COMMENTS_ORDER_INDEX], 'categoryId' => [[$category->id], PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0]]] as $feed => [$arguments, $index]) {
                $this->assertSame([], $plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all(), "{$case}, {$feed}(): no Arabic video can be shown");
                $walking->languageReads = [];
                $walking->shownReads = [];
                $this->statements = [];

                $this->assertSame([], $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$case}, {$feed}()");

                if ($feed === 'categoryId') {
                    // the language bigger than the second check's look (8 entries, it looks at 2): its own rows counted first
                    // by the index that tests every condition of the page (the index alone): none, so an empty page at once
                    // ("0 = 1": the database reads nothing), no second check
                    $this->assertSame([], $this->languageChecks(), "{$case}, categoryId(): no second check");
                    $this->assertCount(1, $this->categorySizes(), "{$case}: the category's entries");
                    $this->assertCount(1, $this->categoryChecks(), "{$case}: the category's own rows");
                    $this->assertStringContainsString('indexed by videos_category_active_count_idx', $this->categoryChecks()[0]);
                    $this->assertSame([], $this->listChecks(), "{$case}, categoryId()");
                    $this->assertStringContainsString('0 = 1', $this->page(), "{$case}, categoryId(): an empty page at once");
                    $this->assertSame([], $walking->languageReads);
                    $this->assertSame([], $walking->shownReads);

                    continue;
                }

                $this->assertStringNotContainsString('indexed by '.$index, $this->page(), "{$case}, {$feed}(): never the list's order for rows that are not there");
                $this->assertCount(1, $this->languageChecks(), "{$case}, {$feed}(): the second check");
                // the list's entries counted (a list that ends soon keeps its order), what the first check's entries were not
                // asked: there is nothing to walk past to
                $this->assertCount(1, $this->listChecks(), "{$case}, {$feed}(): the third check");
                $this->assertStringNotContainsString('avd_walk_window_language', $this->listChecks()[0], "{$case}, {$feed}()");
                $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "{$case}, {$feed}(): the language's own entries");
                $this->assertSame([], $walking->shownReads);
                $this->assertSame([], $this->categoryChecks(), "{$case}, {$feed}(): a category's check only on a category's list");
            }

            app()->setLocale('en');
            $this->assertCount(8, $hidden);
            Video::withTrashed()->forceDelete(); // every video of this case: the next one starts from none
        }
    }

    /**
     * The third check's DB-V3-1 at its root (the fifth pass, where the table has LANGUAGE_SHOWN_INDEX): the visitor's
     * language has many videos, none a visitor can be shown - in the bin, of a banned creator, scheduled for later,
     * private, all at once, at the top of every list (the most viewed and most commented, the newest), and more of each
     * kind than every bound - and every list answers an empty page at once: after the first check one statement counts the
     * language's videos a visitor can be shown by that index alone (per language alternative and status, the active
     * creators named, a scheduled video's due time; the index's ranges leave every hidden entry out), finds none, and the
     * page is "0 = 1". No count of the language's entries, no sample of rows, no third check, no category count, never the
     * list's order - and the same statements whatever the number of hidden videos.
     */
    public function test_a_language_with_no_video_a_visitor_can_be_shown_is_empty_at_once_whatever_its_hidden_videos(): void
    {
        $category = $this->category('Hidden everywhere');
        $this->videos(8, 'en', hoursAgo: 20, category: $category);
        $plain = app(PublicVideoFeedService::class);
        $feeds = ['latest' => [], 'trending' => [], 'mostCommented' => [], 'categoryId' => [$category->id]];
        $hidden = [];

        foreach ([3, 12] as $each) {
            foreach (['bin', 'banned creator', 'scheduled for later', 'private'] as $kind) {
                $hidden = array_merge($hidden, $this->hiddenRun($kind, $each, $category, hoursAgo: 1, language: 'ar'));
            }

            app()->setLocale('ar');
            // every bound below the hidden videos' number: the second check's look, WALK_BOUND, WALK_LANGUAGE_MOST, a category's
            $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: 4, categoryMost: 5, shownIndex: true);

            foreach ($feeds as $feed => $arguments) {
                $this->assertSame([], $plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all(), "{$each} of each kind, {$feed}(): no Arabic video can be shown");

                foreach (['first page', 'load more'] as $page) {
                    $this->statements = [];
                    $walking->languageReads = [];
                    $walking->shownReads = [];
                    $cursor = $page === 'first page' ? '' : $this->cursorBelowTheTop($walking, $feed, $arguments);
                    $this->statements = [];

                    $this->assertSame([], collect($walking->page($walking->{$feed}(...$arguments), 4, $cursor)->items())->pluck('id')->all(), "{$each}, {$feed}() {$page}");
                    $context = "{$each} of each kind, {$feed}() {$page}";
                    $this->assertCount(1, $this->checks(), "{$context}: the first check");
                    $this->assertCount(1, $this->showableChecks(), "{$context}: one count of the videos a visitor can be shown");
                    $this->assertSame([], $this->languageChecks(), "{$context}: no count of the language's entries, no sample");
                    $this->assertSame([], $this->listChecks(), "{$context}: no third check");
                    $this->assertSame([], $this->languageSizes(), "{$context}");
                    $this->assertSame([], $this->categorySizes(), "{$context}");
                    $this->assertSame([], $this->categoryChecks(), "{$context}");
                    $this->assertSame([], $this->hiddenChecks(), "{$context}");
                    $this->assertSame([], $this->parts(), "{$context}: nothing else read");
                    $this->assertSame([], $walking->languageReads, "{$context}");
                    $this->assertSame([], $walking->shownReads, "{$context}");
                    $this->assertStringContainsString('0 = 1', $this->page(), "{$context}: an empty page at once, the database reads nothing");

                    $check = $this->showableChecks()[0];
                    // the language and no language, each status a visitor can be shown a video in: 4 counts by the index alone,
                    // the active creators named (the banned one is not), a scheduled video only once due; the imports by
                    // their own indexes
                    $this->assertSame(4, substr_count($check, 'indexed by '.self::SHOWN_INDEX), $context);
                    $this->assertSame(4, substr_count($check, '"user_id" in ('), $context);
                    $this->assertSame(4, substr_count($check, '"videos"."status" = ?'), $context);
                    $this->assertSame(2, substr_count($check, '"videos"."scheduled_at" <= ?'), $context);
                    $this->assertSame(2, substr_count($check, 'as "avd_walk_import_'), $context);
                    $this->assertStringContainsString('"videos"."language" = ?', $check);
                    $this->assertStringContainsString('"videos"."language" is null', $check);
                    $this->assertStringContainsString('"videos"."deleted_at" is null', $check);
                    $this->assertStringNotContainsString(' order by ', $check, $context);
                    $this->assertStringNotContainsString('exists (', $check, "{$context}: the creators named, not looked up");
                }
            }

            app()->setLocale('en');
        }

        $this->assertCount(4 * 3 + 4 * 12, array_unique($hidden), 'every hidden video of both rounds');
    }

    /**
     * A "load more" cursor of $feed below its first page (an English visitor's: the page's place for a visitor of another
     * language), read by the plain service; the locale is put back.
     *
     * @param  list<mixed>  $arguments
     */
    private function cursorBelowTheTop(PublicVideoFeedService $feeds, string $feed, array $arguments): string
    {
        $locale = app()->getLocale();
        app()->setLocale('en');
        $plain = app(PublicVideoFeedService::class);
        $cursor = (string) $plain->page($plain->{$feed}(...$arguments), 4, '')->nextCursor()?->encode();
        app()->setLocale($locale);
        $this->assertNotSame('', $cursor, "{$feed}(): a page below the first one");

        return $cursor;
    }

    /**
     * The fifth pass: few videos a visitor can be shown among many hidden ones (the visitor's language: 3 shown, 15 in the
     * bin / of a banned creator / scheduled for later at the top of every list). The count by LANGUAGE_SHOWN_INDEX finds
     * fewer than WALK_BOUND and the language's entries nobody is shown are more than WALK_WINDOW (counted by its own index,
     * every status, up to the shown + WALK_WINDOW + 1), so the page is read by the ids of its rows among them: each part
     * (language alternative and status) read through that index as the page itself, the active creators named (the database
     * reads only the rows a visitor can be shown, then sorts them), then the page by the key - never the list's order,
     * never the language's hidden entries. Where those hidden entries are few, the earlier passes' reads of a small
     * language: the newest list by its parts in its own order, another list by the language's own entries. The same rows as
     * the plain service, every page.
     */
    public function test_few_videos_a_visitor_can_be_shown_among_many_hidden_ones_are_read_by_their_own_rows(): void
    {
        $category = $this->category('Few shown');
        $this->videos(8, 'en', hoursAgo: 20, category: $category);
        $shown = $this->rated(3, 'ar', 10, $category);
        foreach (['bin', 'banned creator', 'scheduled for later'] as $kind) {
            $this->hiddenRun($kind, 5, $category, hoursAgo: 1, language: 'ar');
        }
        $plain = app(PublicVideoFeedService::class);
        $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 6, most: 6, shownIndex: true);
        app()->setLocale('ar');

        foreach (['latest' => [[], 'videos_published_id_idx'], 'trending' => [[], 'videos_views_published_id_idx'], 'mostCommented' => [[], PublicVideoFeedService::COMMENTS_ORDER_INDEX], 'categoryId' => [[$category->id], PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0]]] as $feed => [$arguments, $index]) {
            $cursor = '';
            $pages = 0;

            do {
                $expected = $plain->page($plain->{$feed}(...$arguments), 2, $cursor);
                $this->statements = [];
                $actual = $walking->page($walking->{$feed}(...$arguments), 2, $cursor);
                $context = "{$feed}() page {$pages}";

                $this->assertSame(collect($expected->items())->pluck('id')->all(), collect($actual->items())->pluck('id')->all(), "{$context}: the same rows");
                $this->assertSame((string) $expected->nextCursor()?->encode(), (string) $actual->nextCursor()?->encode(), "{$context}: the same next page");

                if ($this->checks() !== [] && $this->showableChecks() !== []) {
                    $this->assertSame([], $this->languageChecks(), "{$context}: no count of the language's entries, no sample");
                    $this->assertSame([], $this->listChecks(), "{$context}: no third check");
                    $this->assertSame([], $this->categoryChecks(), "{$context}");
                    $this->assertSame([], $walking->languageReads, "{$context}");
                    $this->assertStringNotContainsString('indexed by '.$index, $this->page(), "{$context}: never the list's order");
                    $this->assertStringContainsString('"videos"."id" in (', $this->page(), "{$context}: the page by its rows' key");

                    // 15 hidden entries of the language (in the bin, of the banned creator, scheduled for later): more than
                    // WALK_WINDOW (3) past the 3 shown (counted by the language's own index, every status, up to 3 + 3 + 1),
                    // so through the index that leaves them out
                    $this->assertCount(1, $this->hiddenChecks(), "{$context}: the language's hidden entries counted, up to the shown + 4");
                    $this->assertStringContainsString('indexed by videos_language_status_published_id_idx where "videos"."language" = ? limit 7)', $this->hiddenChecks()[0]);
                    $this->assertStringContainsString('indexed by videos_language_status_published_id_idx where "videos"."language" is null limit 4)', $this->hiddenChecks()[0]);
                    $this->assertStringNotContainsString('"status"', $this->hiddenChecks()[0], "{$context}: every status");
                    $this->assertSame([], $this->categorySizes(), $context);
                    $this->assertSame([], $this->languageSizes(), $context);
                    $this->assertCount(1, $this->shownParts(), "{$context}: the parts through the index that tests them, one statement");
                    // the language and no language, both statuses, through that index with the creators named; the two import
                    // keys by their own indexes (no entries here): 6 parts
                    $this->assertSame(4, substr_count($this->shownParts()[0], 'indexed by '.self::SHOWN_INDEX), $context);
                    $this->assertSame(5, substr_count($this->shownParts()[0], 'union all'), $context);
                    $this->assertSame(4, substr_count($this->shownParts()[0], '"videos"."user_id" in ('), $context);
                }

                $cursor = (string) $expected->nextCursor()?->encode();
                $pages++;
            } while ($cursor !== '' && $pages < 10);

            $this->assertGreaterThan(1, $pages, "{$feed}(): the shown videos fill two pages");
        }

        // where the language's hidden entries are few (3 in the bin, the most viewed, most commented and newest of it,
        // WALK_WINDOW 3): the earlier passes' reads of a small language - the newest list its parts in its own order (a few
        // entries each), another list the language rule's own entries (an index merge)
        Video::withTrashed()->where('language', 'ar')->whereNotIn('id', $shown)->get()->each(fn (Video $video) => $video->forceDelete());
        $this->hiddenRun('bin', 3, $category, hoursAgo: 1, language: 'ar');

        foreach (['latest' => [], 'trending' => [], 'mostCommented' => [], 'categoryId' => [$category->id]] as $feed => $arguments) {
            $this->statements = [];
            $walking->languageReads = [];
            $this->assertSame($plain->{$feed}(...$arguments)->limit(2)->pluck('id')->all(), $walking->{$feed}(...$arguments)->limit(2)->get()->pluck('id')->all(), "few hidden, {$feed}()");
            $this->assertCount(1, $this->checks(), "few hidden, {$feed}(): the first window holds the 3 in the bin");
            $this->assertCount(1, $this->showableChecks(), "few hidden, {$feed}()");
            $this->assertCount(1, $this->hiddenChecks(), "few hidden, {$feed}(): the hidden entries counted");
            $this->assertSame([], $this->shownParts(), "few hidden, {$feed}()");
            $this->assertSame([], $this->languageChecks(), "few hidden, {$feed}()");

            if ($feed === 'latest') {
                $this->assertCount(1, $this->parts(), 'its parts in the newest list\'s order');
                $this->assertStringContainsString('indexed by videos_language_status_published_id_idx', $this->parts()[0]);
                $this->assertSame([], $walking->languageReads);
            } else {
                $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "few hidden, {$feed}(): the language's own entries");
                $this->assertSame([], $this->parts(), "few hidden, {$feed}()");
            }
        }

        app()->setLocale('en');
    }

    /**
     * The fifth pass: where the visitor's language has WALK_BOUND or more videos a visitor can be shown (counted by
     * LANGUAGE_SHOWN_INDEX), the earlier passes' checks decide, knowing them many: the second check counts the entries
     * without its sample of rows, and a page right below a run of the language's own hidden entries keeps its list's order
     * (DB-V1-1; the newest list its parts). Where the active creators are too many to name, each entry's creator is
     * checked in the count instead (the same decisions, the same rows). An import key with WALK_BOUND entries or more (no
     * index tests an import's showability) leaves the page to the earlier checks, with their sample.
     */
    public function test_many_videos_a_visitor_can_be_shown_go_on_to_the_earlier_checks_without_a_sample(): void
    {
        $category = $this->category('Many shown');
        $this->videos(10, 'en', hoursAgo: 30, category: $category);
        $hidden = $this->hiddenRun('bin', 6, $category);
        $plain = app(PublicVideoFeedService::class);
        app()->setLocale('en');

        foreach ([true, false] as $creatorList) {
            $walking = $this->feedsNamingIndexes(window: 3, language: 8, bound: 6, shownIndex: true, creatorList: $creatorList);

            foreach (['latest' => [[], 'videos_published_id_idx'], 'trending' => [[], 'videos_views_published_id_idx'], 'categoryId' => [[$category->id], PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0]]] as $feed => [$arguments, $index]) {
                $this->statements = [];
                $rows = $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all();
                $context = ($creatorList ? 'creators named' : 'each creator checked').", {$feed}()";

                $this->assertSame($plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all(), $rows, "{$context}: the same rows");
                $this->assertSame([], array_values(array_intersect($rows, $hidden)), $context);
                $this->assertCount(1, $this->showableChecks(), "{$context}: the videos a visitor can be shown counted: many");
                // the language and no language (the default language's rule), both statuses: 4 counts
                $this->assertSame($creatorList ? 4 : 0, substr_count($this->showableChecks()[0], '"user_id" in ('), $context);
                $this->assertSame($creatorList ? 0 : 4, substr_count($this->showableChecks()[0], 'exists ('), $context);
                $this->assertCount(1, $this->languageChecks(), "{$context}: then the second check");
                $this->assertStringNotContainsString('avd_walk_shown', $this->languageChecks()[0], "{$context}: without its sample of rows");
                $this->assertSame([], $this->shownParts(), $context);
                $this->assertSame([], $this->hiddenChecks(), $context);
                $this->assertSame([], $walking->languageReads, $context);
                // no count of a category's own rows either (the fourth pass's checks: the list's order reads the page anyway)
                $this->assertSame([], $this->languageSizes(), $context);
                $this->assertSame([], $this->categorySizes(), $context);
                $this->assertSame([], $this->categoryChecks(), $context);
                $this->assertSame([], $walking->shownReads, $context);

                if ($feed === 'latest') {
                    $this->assertCount(1, $this->parts(), "{$context}: its parts");
                    $this->assertStringContainsString('indexed by videos_language_status_published_id_idx', $this->parts()[0]);
                } else {
                    $this->assertStringContainsString('indexed by '.$index, $this->page(), "{$context}: the list's order, past the run");
                }
            }
        }

        // an import key with WALK_BOUND entries or more (a Japanese visitor sees every import, here German ones; no Japanese
        // video of the site's own): the earlier checks, with their sample
        $this->videos(7, 'de', hoursAgo: 40, imported: true);
        app()->setLocale('ja');
        $walking = $this->feedsNamingIndexes(window: 3, language: 8, bound: 6, shownIndex: true);
        $this->statements = [];
        $this->assertSame($plain->trending()->limit(4)->pluck('id')->all(), $walking->trending()->limit(4)->get()->pluck('id')->all());
        $this->assertCount(1, $this->showableChecks());
        $this->assertCount(1, $this->languageChecks());
        $this->assertStringContainsString('as "avd_walk_shown"', $this->languageChecks()[0], 'its sample of rows');
        app()->setLocale('en');
    }

    /**
     * Where the category index does not have the columns 1.2.3 gives it yet (a big site whose index the scheduler has not
     * rebuilt), it cannot test every condition of the page: a category's list reads the language's own entries then.
     */
    public function test_a_categorys_list_without_the_full_category_index_reads_the_languages_own_entries(): void
    {
        $category = $this->category('Older index');
        $this->rated(10, 'en', 0, $category);
        $this->rated(8, 'ar', 1000, $category, binned: true);
        Schema::table('videos', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex('videos_category_active_count_idx');
        });
        Schema::table('videos', function (\Illuminate\Database\Schema\Blueprint $table): void {
            // 1.2.0's definition (database/migrations/2026_07_19_020000_add_active_video_count_indexes.php)
            $table->index(['category_id', 'status', 'deleted_at', 'uploaded_at', 'published_at', 'scheduled_at'], 'videos_category_active_count_idx');
        });
        $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: 40);
        app()->setLocale('ar');

        $this->statements = [];
        $this->assertSame([], $walking->categoryId($category->id)->limit(4)->get()->pluck('id')->all());
        $this->assertSame([], $walking->shownReads, 'an index that cannot test the language and the creator is not named');
        $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads);
        $this->assertStringNotContainsString('indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0], $this->page());
    }

    /**
     * A language with a few videos, all older than many newer videos in another language: never a walk of the index past
     * the newer ones (the third pass read the whole index there, the language counted as "many" by its hidden videos too).
     */
    public function test_a_language_with_few_videos_below_many_newer_ones_is_read_by_its_own_entries(): void
    {
        $category = $this->category('Mixed');
        $this->videos(3, 'de', hoursAgo: 40, category: $category);
        $this->videos(12, 'en', hoursAgo: 2, category: $category);
        $plain = app(PublicVideoFeedService::class);
        $walking = $this->feedsNamingIndexes(window: 3, language: 8, bound: 20);
        app()->setLocale('de');

        foreach (['latest' => [], 'trending' => [], 'mostCommented' => [], 'categoryId' => [$category->id]] as $feed => $arguments) {
            $this->statements = [];
            $walking->languageReads = [];
            $expected = $plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all();

            $this->assertCount(3, $expected);
            $this->assertSame($expected, $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$feed}(): the same rows");

            if ($feed === 'latest') {
                $this->assertCount(1, $this->parts(), 'latest(): its parts');
                $this->assertStringContainsString('"videos"."id" in (', $this->page());
            } else {
                $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "{$feed}(): fewer entries of the language than of the list");
            }
        }

        // more videos of the language elsewhere (23 in all, more than the second check looks at: 1): the category's own
        // rows counted first (3, fewer than the page's count), read by the index that tests every condition, no second check
        $walking = $this->feedsNamingIndexes(window: 3, language: 1, bound: 20);
        $this->videos(20, 'de', hoursAgo: 50);
        $this->statements = [];
        $expected = $plain->categoryId($category->id)->limit(4)->pluck('id')->all();
        $this->assertSame($expected, $walking->categoryId($category->id)->limit(4)->get()->pluck('id')->all());
        $this->assertSame([], $walking->languageReads);
        $this->assertSame(['videos_category_active_count_idx'], $walking->shownReads);
        $this->assertSame([], $this->languageChecks());
        $this->assertCount(1, $this->categorySizes());
        $this->assertCount(1, $this->categoryChecks());
        $this->assertStringNotContainsString('indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0], $this->page());
    }

    /**
     * A big language (more entries than WALK_BOUND) on a long list whose first entries are other languages' videos: its
     * rows are further down the list's order (here: older, fewer views, no comments), so the page is read by the
     * language's own entries where it has fewer than WALK_LANGUAGE_MOST, else in the index's order; a run of the
     * language's own hidden entries above the page keeps the index (the test above). Both only where the second check saw
     * a page's worth of the language's videos a visitor can be shown (a category's list: where its own count found a page's
     * worth, before the second check): where it saw fewer, the language's own entries whatever its size.
     */
    public function test_a_big_language_below_other_languages_videos_is_read_by_its_own_entries(): void
    {
        $category = $this->category('Far');
        $creator = User::factory()->create();

        foreach ([['en', 10, 2, 100, 20], ['de', 8, 50, 0, 0]] as [$language, $count, $hoursAgo, $views, $comments]) {
            for ($i = 0; $i < $count; $i++) {
                Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
                    'category_id' => $category->id,
                    'language' => $language,
                    'published_at' => now()->subHours($hoursAgo)->subMinutes($i),
                    'uploaded_at' => now()->subHours($hoursAgo + 1),
                    'views' => $views + $i,
                    'comments_count' => $comments + $i,
                ]);
            }
        }

        $plain = app(PublicVideoFeedService::class);
        app()->setLocale('de');

        foreach (['trending' => [[], 'videos_views_published_id_idx'], 'mostCommented' => [[], PublicVideoFeedService::COMMENTS_ORDER_INDEX], 'categoryId' => [[$category->id], PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0]]] as $feed => [$arguments, $index]) {
            $expected = $plain->{$feed}(...$arguments)->limit(4)->pluck('id')->all();
            $this->assertCount(4, $expected);

            // 8 entries of the language, past WALK_BOUND (4) and under WALK_LANGUAGE_MOST (40), and the 4 the second check
            // looks at (2 from each end of its index) are the page's: its own entries
            $walking = $this->feedsNamingIndexes(window: 3, language: 4, bound: 4, most: 40);
            $this->statements = [];
            $this->assertSame($expected, $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$feed}(): the same rows");
            $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "{$feed}(): the language's own entries");
            $this->assertCount(1, $this->listChecks(), "{$feed}(): what the first check's entries were (a big language)");
            $this->assertStringContainsString('avd_walk_window_language', $this->listChecks()[0]);

            // past WALK_LANGUAGE_MOST (6): the index's order
            $walking = $this->feedsNamingIndexes(window: 3, language: 4, bound: 4, most: 6);
            $this->statements = [];
            $this->assertSame($expected, $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$feed}(): the same rows");
            $this->assertSame([], $walking->languageReads);
            $this->assertStringContainsString('indexed by '.$index, $this->page(), "{$feed}(): a language too big to read whole");

            // the second check looks at 2 of them, fewer than the page's 4: too few seen to look for the page in the list's
            // order, so the language's own entries, whatever WALK_LANGUAGE_MOST. A category's list counts its own rows first
            // (the language bigger than the second check's look): a page's worth (its 8 German rows), so the fourth pass's
            // checks decide, the second one without its sample: past WALK_LANGUAGE_MOST (6) the index's order, which finds
            // the page within the category (fewer than WALK_CATEGORY_MOST entries: the count saw them there)
            $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: 6);
            $this->statements = [];
            $this->assertSame($expected, $walking->{$feed}(...$arguments)->limit(4)->get()->pluck('id')->all(), "{$feed}(): the same rows");
            $this->assertSame([], $walking->shownReads);

            if ($feed === 'categoryId') {
                $this->assertCount(1, $this->categoryChecks(), 'categoryId(): its own rows counted, a page\'s worth');
                $this->assertCount(1, $this->languageChecks(), 'categoryId(): then the second check');
                $this->assertStringNotContainsString('avd_walk_shown', $this->languageChecks()[0], 'categoryId(): without its sample of rows');
                $this->assertSame([], $walking->languageReads);
                $this->assertStringContainsString('indexed by '.$index, $this->page(), 'categoryId(): the index\'s order');

                // under WALK_LANGUAGE_MOST (40): the language's own entries, as the fourth pass
                $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: 40);
                $this->statements = [];
                $this->assertSame($expected, $walking->categoryId(...$arguments)->limit(4)->get()->pluck('id')->all(), 'categoryId(): the same rows');
                $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, 'categoryId(): the language\'s own entries');
                $this->assertSame([], $walking->shownReads);
            } else {
                $this->assertStringNotContainsString('indexed by '.$index, $this->page(), "{$feed}(): not the list's order");
                $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads, "{$feed}(): the language's own entries");
                $this->assertSame([], $this->categoryChecks(), "{$feed}(): a category's count only on a category's list");
            }
        }
    }

    public function test_a_page_where_the_index_ends_within_the_first_check_keeps_its_index(): void
    {
        $category = $this->category('Small');
        $this->videos(5, 'en', category: $category);
        $this->videos(3, 'ja', hoursAgo: 1, category: $category);
        $feeds = $this->feedsNamingIndexes(window: 50);
        app()->setLocale('en');

        $first = $feeds->page($feeds->categoryId($category->id), 4, '');
        $this->statements = [];
        $last = $feeds->page($feeds->categoryId($category->id), 4, (string) $first->nextCursor()?->encode());

        $this->assertCount(1, $last->items());
        $this->assertCount(1, $this->checks());
        $this->assertSame([], $this->languageChecks(), 'the window held every entry left: no second check');
        $this->assertStringContainsString('indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0], $this->page());
    }

    public function test_every_page_gives_the_same_rows_whatever_plan_reads_it(): void
    {
        // the newest five are in German: a window of 3 entries holds none of the English ones (the plan changes there)
        // (and two categories: the newest videos of the first are in German too)
        $first = $this->category('First');
        $second = $this->category('Second');
        $this->videos(5, 'de', hoursAgo: 1, category: $first);
        $this->videos(9, 'en', hoursAgo: 10, category: $second);
        $this->videos(3, 'en', hoursAgo: 20, category: $first);
        $this->videos(4, null, hoursAgo: 30);
        // imports in Japanese (every language but the default sees them) and Arabic videos nobody is shown
        $this->videos(3, 'ja', hoursAgo: 25, category: $second, imported: true);
        $this->hiddenRun('bin', 3, $second, hoursAgo: 12, language: 'ar');
        // and runs of entries nobody is shown at the top and in the middle of the indexes (DB-V1-1)
        $this->hiddenRun('bin', 4, $first);
        $this->hiddenRun('private', 3, $second, hoursAgo: 15);
        $plain = app(PublicVideoFeedService::class);
        $feeds = ['latest' => [], 'trending' => [], 'mostViewed' => [], 'mostCommented' => [], 'categoryId' => [$first->id], 'categoryId ' => [$second->id]];

        $read = ['by the language' => 0, 'by the category index' => 0];

        // each plan in turn: the parts, the language's own entries, the index past a run, an empty page, a category's own
        // entries by the index that tests every condition of the page (fewer than a page's worth of them there: DB-V3-1);
        // and where the table has LANGUAGE_SHOWN_INDEX (the fifth pass): an empty page by its count, the rows a visitor can be
        // shown read through it, the earlier checks where they are many (with the creators named and without)
        foreach ([[3, 2, 4, null, false, true], [3, 8, 50, null, false, true], [3, 1, 2, null, false, true], [2, 30, 3, null, false, true], [3, 6, 2, 3, false, true],
            [3, 2, 4, null, true, true], [3, 8, 50, null, true, true], [2, 30, 3, null, true, true], [3, 1, 2, 3, true, true], [3, 2, 4, null, true, false]] as [$window, $language, $bound, $most, $shownIndex, $creatorList]) {
            $walking = $this->feedsNamingIndexes(window: $window, language: $language, bound: $bound, most: $most, shownIndex: $shownIndex, creatorList: $creatorList);

            foreach (['en', 'de', 'ar', 'ja'] as $locale) {
                app()->setLocale($locale);

                foreach ($feeds as $feed => $arguments) {
                    $method = trim($feed);
                    $cursor = '';
                    $pages = 0;

                    do {
                        $expected = $plain->page($plain->{$method}(...$arguments), 2, $cursor);
                        $actual = $walking->page($walking->{$method}(...$arguments), 2, $cursor);
                        $context = "{$window}/{$language}/{$bound}/".($shownIndex ? 'shown index' : 'without it').($creatorList ? '' : ', no creator list')." {$locale} {$feed}() page {$pages}";

                        $this->assertSame(collect($expected->items())->pluck('id')->all(), collect($actual->items())->pluck('id')->all(), "{$context}: the same rows");

                        $cursor = (string) $expected->nextCursor()?->encode();
                        $this->assertSame($cursor, (string) $actual->nextCursor()?->encode(), "{$context}: the same next page");
                        $pages++;
                    } while ($cursor !== '' && $pages < 20);

                    $this->assertGreaterThan(0, $pages);
                }
            }

            $read['by the language'] += count($walking->languageReads);
            $read['by the category index'] += count($walking->shownReads);
        }

        $this->assertGreaterThan(0, $read['by the language'], 'pages read by the language\'s own entries were compared');
        $this->assertGreaterThan(0, $read['by the category index'], 'pages read by the category\'s own entries were compared');
        $this->assertNotSame([], $this->shownParts(), 'pages read through the index that tests the rows a visitor can be shown were compared');
        $this->assertNotSame([], array_filter($this->showableChecks(), fn (string $sql): bool => str_contains($sql, 'exists (')), 'and counted without a list of creators');
    }

    public function test_the_language_rule_written_as_four_alternatives_keeps_the_same_videos(): void
    {
        // Video::scopeForLocale(): "imported, or own and in this language" is now "imported or in this language or
        // without one" (each alternative served by an index, DB-RV-1); the same videos in every combination
        $creator = User::factory()->create();
        foreach ([null, 'en', 'ar', 'ja'] as $language) {
            foreach ([[null, null], [1000, null], [null, 2000], [3000, 4000]] as [$avdb, $xvid]) {
                Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
                    'language' => $language,
                    'avdb_id' => $avdb === null ? null : $avdb + crc32((string) $language) % 997,
                    'xvid_id' => $xvid === null ? null : $xvid + crc32((string) $language) % 997,
                ]);
            }
        }

        foreach (['ar', 'ja', 'de', 'en'] as $locale) {
            $before = Video::query()->where(function (Builder $outer) use ($locale) {
                if ($locale === 'en') {
                    $outer->where('language', $locale)->orWhereNull('language');

                    return;
                }

                $outer->where(fn (Builder $q) => $q->whereNotNull('avdb_id')->orWhereNotNull('xvid_id'))
                    ->orWhere(fn (Builder $q) => $q->whereNull('avdb_id')->whereNull('xvid_id')
                        ->where(fn (Builder $q) => $q->where('language', $locale)->orWhereNull('language')));
            })->orderBy('id')->pluck('id')->all();

            $this->assertNotSame([], $before);
            $this->assertSame($before, Video::query()->forLocale($locale)->orderBy('id')->pluck('id')->all(), $locale);
        }
    }

    /**
     * LANGUAGE_SHOWN_INDEX (one of 1.2.3's indexes) holds, after the language, every column of the rule a visitor is shown
     * a video by (Video::scopeActive() with the creators named, and the bin), so its count and its parts test each entry by
     * the index alone; its order leaves out the other statuses, the bin, the creators who are not active and the videos
     * scheduled for later (status, deleted_at, user_id, scheduled_at first).
     */
    public function test_the_index_of_the_videos_a_visitor_can_be_shown_holds_every_column_of_the_rule(): void
    {
        $this->assertSame(self::SHOWN_INDEX, PublicVideoFeedService::LANGUAGE_SHOWN_INDEX);
        $columns = VideoIndexes::WANTED[self::SHOWN_INDEX];
        $this->assertSame(['language', 'status', 'deleted_at', 'user_id', 'scheduled_at'], array_slice($columns, 0, 5));

        preg_match_all('/"(?:videos"\.")?([a-z_]+)"\s*(?:=|<=|is|in)/', Video::query()->active(false, [1])->toSql(), $named);
        $rule = array_values(array_unique($named[1]));
        sort($rule);

        $this->assertSame(['deleted_at', 'published_at', 'scheduled_at', 'status', 'uploaded_at', 'user_id'], $rule, 'the rule\'s columns');
        $this->assertSame([], array_values(array_diff($rule, $columns)), 'every one of them in the index');
    }

    /** The second check reads only entries a visitor could be shown: the statuses Video::active() can show. */
    public function test_the_statuses_the_second_check_reads_are_the_ones_a_visitor_can_be_shown(): void
    {
        $creator = User::factory()->create();

        foreach (VideoStatus::cases() as $status) {
            Video::factory()->for($creator, 'user')->withStatus($status)->create([
                'language' => 'en',
                'published_at' => now()->subDay(),
                'scheduled_at' => now()->subDay(),
                'uploaded_at' => now()->subDays(2),
            ]);
        }

        $shown = Video::query()->active()->pluck('status')->map(fn ($status) => $status instanceof VideoStatus ? $status : VideoStatus::from((int) $status))->unique()->values()->all();
        usort($shown, fn (VideoStatus $a, VideoStatus $b): int => $a->value <=> $b->value);

        $this->assertSame(PublicVideoFeedService::SHOWN_STATUSES, $shown);
    }

    public function test_the_second_check_is_one_statement_bounded_by_the_indexes_alone(): void
    {
        $this->videos(10, 'en', hoursAgo: 30);
        $this->hiddenRun('bin', 6, null);
        $feeds = $this->feedsNamingIndexes(window: 3, language: 8, bound: 6, most: 12);
        app()->setLocale('ja');

        $this->statements = [];
        $this->assertSame([], $feeds->trending()->limit(4)->get()->all());
        $check = $this->languageChecks()[0] ?? '';

        $this->assertCount(1, $this->languageChecks());
        // each alternative of the language rule counted by its index alone, up to WALK_LANGUAGE_MOST (12)
        $this->assertSame(4, substr_count($check, 'as "avd_walk_language_'));
        $this->assertStringContainsString('indexed by videos_avdb_id_unique where "videos"."avdb_id" is not null limit 12)', $check);
        $this->assertStringContainsString('indexed by videos_xvid_id_unique where "videos"."xvid_id" is not null limit 12)', $check);
        $this->assertStringContainsString('indexed by videos_language_status_published_id_idx where "videos"."language" = ? and "videos"."status" in (?, ?) limit 12)', $check);
        $this->assertStringContainsString('indexed by videos_language_status_published_id_idx where "videos"."language" is null and "videos"."status" in (?, ?) limit 12)', $check);
        // the rows a visitor can be shown among 8 entries of each alternative, up to the page's count: its first 4 as its
        // index holds them and its last 4, the index read backwards in its own order (neither half sorted: 8 parts, 7
        // joined to the next one; the only orders in the statement are those four, without the column an alternative holds
        // to one value: named, MariaDB sorted a whole "language is null" range)
        $this->assertSame(7, substr_count($check, ') union select'));
        $this->assertSame(4, substr_count($check, ' order by '));
        $this->assertStringNotContainsString('order by "videos"."language"', $check);
        foreach (['videos_avdb_id_unique where "videos"."avdb_id" is not null' => '"videos"."avdb_id" desc', 'videos_xvid_id_unique where "videos"."xvid_id" is not null' => '"videos"."xvid_id" desc', 'videos_language_status_published_id_idx where "videos"."language" = ? and "videos"."status" in (?, ?)' => '"videos"."status" desc, "videos"."published_at" desc, "videos"."id" desc', 'videos_language_status_published_id_idx where "videos"."language" is null and "videos"."status" in (?, ?)' => '"videos"."status" desc, "videos"."published_at" desc, "videos"."id" desc'] as $part => $backwards) {
            $this->assertStringContainsString('indexed by '.$part.' limit 4)', $check, 'its first entries');
            $this->assertStringContainsString('indexed by '.$part.' order by '.$backwards.' limit 4)', $check, 'its last entries');
        }
        $this->assertStringContainsString('as "avd_walk_sample"', $check);
        $this->assertMatchesRegularExpression('/limit 4\) as "avd_walk_shown"/', $check);
        // no video of the language: nothing more is read, the page is empty at once
        $this->assertSame([], $this->listChecks());
        $this->assertStringContainsString('0 = 1', $this->page());

        // a big language (16 entries, past WALK_BOUND 6): a third statement counts the list's entries from the page's
        // place (up to the bound) and how many of the first check's entries (3) are the language's own
        app()->setLocale('en');
        $this->statements = [];
        $this->assertCount(4, $feeds->trending()->limit(4)->get());
        $list = $this->listChecks()[0] ?? '';

        $this->assertCount(1, $this->listChecks());
        $this->assertMatchesRegularExpression('/indexed by videos_views_published_id_idx order by "views" desc, "published_at" desc, "id" desc limit 6\) as "avd_walk_rest"/', $list);
        $this->assertMatchesRegularExpression('/order by "views" desc, "published_at" desc, "id" desc limit 3\) as "avd_walk_seen" inner join "videos" on "videos"."id" = "avd_walk_seen"."avd_walk_id" where \("language" = \? or "language" is null\)\) as "avd_walk_window"/', $list);
        // they were the language's own, nobody shown (the run in the bin): the index's order, past them
        $this->assertStringContainsString('indexed by videos_views_published_id_idx', $this->page());
    }

    /**
     * A category's list (where the index that tests every condition of the page is there) whose visitor's language has
     * more entries than the second check looks at (counted first by the language's indexes alone): the category is counted
     * as a whole by its order's index alone (whatever the page's place: the count below may read all of it), up to
     * WALK_CATEGORY_MOST; then one statement counts the category's rows the page could show, every condition of the page
     * and its place, up to the page's count, read by that index (no sort, no other index). None: an empty page; fewer than
     * the page's count: that index reads the page; a page's worth: the fourth pass's checks, the second without its sample.
     */
    public function test_a_categorys_own_rows_are_counted_by_the_index_that_tests_every_condition(): void
    {
        // newest first: 6 in the bin, 2 shown, 6 in the bin, the 2 oldest shown (16 English entries, 4 a visitor is shown)
        $category = $this->category('Counted');
        $hidden = $this->hiddenRun('bin', 6, $category, hoursAgo: 1);
        $this->videos(2, 'en', hoursAgo: 30, category: $category);
        $hidden = array_merge($hidden, $this->hiddenRun('bin', 6, $category, hoursAgo: 40));
        $this->videos(2, 'en', hoursAgo: 50, category: $category);
        $plain = app(PublicVideoFeedService::class);
        // the second check would look at 4 of the language's 16 entries
        $walking = $this->feedsNamingIndexes(window: 3, language: 4, bound: 6);
        app()->setLocale('en');

        // a page of 4 reads 5 rows: the category holds 4 of them, below the first check's 3 entries (in the bin)
        $expected = $plain->page($plain->categoryId($category->id), 4, '');
        $this->statements = [];
        $few = $walking->page($walking->categoryId($category->id), 4, '');
        $check = $this->categoryChecks()[0] ?? '';

        $this->assertSame(collect($expected->items())->pluck('id')->all(), collect($few->items())->pluck('id')->all());
        $this->assertCount(4, $few->items());
        $this->assertSame([], array_values(array_intersect(collect($few->items())->pluck('id')->all(), $hidden)));
        $this->assertCount(1, $this->languageSizes(), 'the language\'s entries first, by its indexes alone');
        $this->assertStringContainsString('indexed by videos_language_status_published_id_idx where "videos"."language" = ? and "videos"."status" in (?, ?) limit 5)', $this->languageSizes()[0], 'up to one more than the second check looks at');
        $this->assertCount(1, $this->categorySizes(), 'then the category\'s entries');
        $this->assertMatchesRegularExpression('/\(select "videos"."id" from "videos" indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0].' where "videos"."category_id" = \? limit 40000\) as "avd_walk_category_size"/', $this->categorySizes()[0], 'by its order\'s index alone, the whole category');
        $this->assertCount(1, $this->categoryChecks(), 'then its own rows');
        $this->assertMatchesRegularExpression('/\(select 1 from "videos" indexed by videos_category_active_count_idx where .* limit 5\) as "avd_walk_category"/', $check);
        foreach (['"category_id" = ?', '"videos"."deleted_at" is null', 'exists (select', '("language" = ? or "language" is null)', '"status" = ?', '"uploaded_at" is not null', '"scheduled_at" <= ?'] as $condition) {
            $this->assertStringContainsString($condition, $check, 'every condition of the page');
        }
        $this->assertStringNotContainsString(' order by ', $check);
        $this->assertSame([], $this->languageChecks(), 'the category decides: no second check');
        $this->assertSame([], $this->listChecks(), 'nor a third');
        $this->assertSame(['videos_category_active_count_idx'], $walking->shownReads);
        $this->assertStringContainsString('indexed by videos_category_active_count_idx', $this->page());

        // a page of 2 reads 3 rows: the category holds a page's worth of them (4): the fourth pass's checks, the second one
        // without its sample of rows (the count found them); the first check's entries were the language's own, in the bin:
        // the index's order, past them
        $expected = $plain->page($plain->categoryId($category->id), 2, '');
        $this->statements = [];
        $walking->shownReads = [];
        $plentiful = $walking->page($walking->categoryId($category->id), 2, '');

        $this->assertSame(collect($expected->items())->pluck('id')->all(), collect($plentiful->items())->pluck('id')->all());
        $this->assertCount(2, $plentiful->items());
        $this->assertCount(1, $this->categoryChecks());
        $this->assertMatchesRegularExpression('/limit 3\) as "avd_walk_category"/', $this->categoryChecks()[0]);
        $this->assertCount(1, $this->languageChecks(), 'the second check');
        $this->assertStringNotContainsString('avd_walk_sample', $this->languageChecks()[0], 'without its sample of rows');
        $this->assertStringNotContainsString('avd_walk_shown', $this->languageChecks()[0]);
        $this->assertCount(1, $this->listChecks(), 'the third check');
        $this->assertStringContainsString('avd_walk_window_language', $this->listChecks()[0]);
        $this->assertSame([], $walking->shownReads);
        $this->assertSame([], $walking->languageReads);
        $this->assertStringContainsString('indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0], $this->page(), 'the index\'s order, past the run');

        // its "load more" page (below its cursor 6 in the bin, then the 2 oldest): the category counted whole all the same
        // (no cursor in its size), its own rows from the cursor (2, fewer than the page's count): read by that index
        $cursor = (string) $expected->nextCursor()?->encode();
        $this->assertNotSame('', $cursor);
        $this->statements = [];
        $next = $walking->page($walking->categoryId($category->id), 2, $cursor);

        $this->assertSame(collect($plain->page($plain->categoryId($category->id), 2, $cursor)->items())->pluck('id')->all(), collect($next->items())->pluck('id')->all());
        $this->assertCount(2, $next->items());
        $this->assertCount(1, $this->categorySizes());
        $this->assertStringNotContainsString('published_at', $this->categorySizes()[0], 'the whole category, whatever the page\'s place');
        $this->assertMatchesRegularExpression('/indexed by videos_category_active_count_idx where .*"category_id" = \? and \(\("published_at" < \?.* limit \d+\) as "avd_walk_category"/', $this->categoryChecks()[0] ?? '', 'its own rows from the cursor');
        $this->assertSame([], $this->languageChecks());
        $this->assertSame(['videos_category_active_count_idx'], $walking->shownReads);
    }

    /**
     * The category's own count runs only where the visitor's language has more entries than the second check looks at
     * (counted first by the language's indexes alone, one statement): with fewer, the second check settles the page as
     * cheaply as before (every entry seen), so a category's list of a small or absent language costs what it did.
     */
    public function test_a_categorys_own_count_runs_only_for_a_language_bigger_than_the_second_checks_look(): void
    {
        $category = $this->category('Gate');
        $this->videos(8, 'en', hoursAgo: 2, category: $category);
        $this->hiddenRun('bin', 5, $category, hoursAgo: 30, language: 'ar');
        app()->setLocale('ar');

        foreach ([[8, 0, 1], [4, 1, 0]] as [$language, $counted, $checked]) {
            $walking = $this->feedsNamingIndexes(window: 3, language: $language, bound: 20);
            $this->statements = [];
            $this->assertSame([], $walking->categoryId($category->id)->limit(4)->get()->pluck('id')->all());

            $this->assertCount(1, $this->languageSizes(), "{$language}: the language's entries counted first, by its indexes alone");
            $this->assertStringContainsString('indexed by videos_language_status_published_id_idx where "videos"."language" = ? and "videos"."status" in (?, ?) limit '.($language + 1).')', $this->languageSizes()[0]);
            $this->assertCount($counted, $this->categorySizes(), "{$language}: the category's entries");
            $this->assertCount($counted, $this->categoryChecks(), "{$language}: the category's own count");
            $this->assertCount($checked, $this->languageChecks(), "{$language}: the second check");
            $this->assertStringContainsString('0 = 1', $this->page());
            $this->assertSame([], $walking->languageReads);
            $this->assertSame([], $walking->shownReads);
        }

        // the other lists never count the language's entries apart from the second check
        foreach (['trending', 'mostCommented', 'latest'] as $feed) {
            $walking = $this->feedsNamingIndexes(window: 3, language: 4, bound: 20);
            $this->statements = [];
            $this->assertSame([], $walking->{$feed}()->limit(4)->get()->pluck('id')->all());
            $this->assertSame([], $this->languageSizes(), $feed);
            $this->assertSame([], $this->categoryChecks(), $feed);
        }
    }

    /**
     * A category with WALK_CATEGORY_MOST entries or more (the whole category, counted by its order's index alone) is not
     * counted by the category index (that could read all of it for one page): the list's own checks decide, as for the other
     * lists (here a language with three videos there: its own entries; all of them hidden: the language's own entries too,
     * never the category's order).
     */
    public function test_a_category_with_too_many_entries_is_not_counted_whole(): void
    {
        $category = $this->category('Big');
        $this->videos(3, 'de', hoursAgo: 40, category: $category);
        $this->videos(12, 'en', hoursAgo: 2, category: $category);
        $plain = app(PublicVideoFeedService::class);
        app()->setLocale('de');

        // the language's 3 entries past WALK_BOUND (2), the 2 the second check looks at fewer than the page's 4; the
        // category's 15 entries, the bound 10: not counted
        $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 2, categoryMost: 10);
        $this->statements = [];
        $expected = $plain->categoryId($category->id)->limit(4)->pluck('id')->all();
        $this->assertCount(3, $expected);
        $this->assertSame($expected, $walking->categoryId($category->id)->limit(4)->get()->pluck('id')->all());
        $this->assertCount(1, $this->categorySizes(), 'its entries counted, by its order\'s index alone');
        $this->assertMatchesRegularExpression('/indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0].' where "videos"."category_id" = \? limit 10\) as "avd_walk_category_size"/', $this->categorySizes()[0]);
        $this->assertSame([], $this->categoryChecks(), 'not counted by the category index');
        $this->assertCount(1, $this->listChecks(), 'the list\'s own checks decide');
        $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads);
        $this->assertSame([], $walking->shownReads);

        // the same with the bound 16: counted (3 of the page's 4 rows: read by the category index)
        $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 2, categoryMost: 16);
        $this->statements = [];
        $this->assertSame($expected, $walking->categoryId($category->id)->limit(4)->get()->pluck('id')->all());
        $this->assertCount(1, $this->categoryChecks());
        $this->assertSame([], $this->listChecks());
        $this->assertSame(['videos_category_active_count_idx'], $walking->shownReads);

        // its German videos hidden, more of them than the second check looks at and than WALK_BOUND: not counted, the
        // language's own entries (never the category's order to its end)
        Video::query()->where('language', 'de')->get()->each->delete();
        $this->hiddenRun('bin', 6, $category, hoursAgo: 60, language: 'de');
        $walking = $this->feedsNamingIndexes(window: 3, language: 2, bound: 4, most: 4, categoryMost: 10);
        $this->statements = [];
        $this->assertSame([], $walking->categoryId($category->id)->limit(4)->get()->pluck('id')->all());
        $this->assertSame([], $this->categoryChecks());
        $this->assertStringNotContainsString('indexed by '.PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0], $this->page());
        $this->assertSame([['videos_avdb_id_unique', 'videos_xvid_id_unique', 'videos_language_status_published_id_idx']], $walking->languageReads);
        $this->assertSame([], $walking->shownReads);
    }

    public function test_the_check_reads_at_most_its_window_of_the_index_from_the_pages_place(): void
    {
        $this->videos(6, 'en');
        $feeds = $this->feedsNamingIndexes(window: 4);
        $first = $feeds->page($feeds->latest(), 2, '');

        $this->statements = [];
        $feeds->page($feeds->latest(), 2, (string) $first->nextCursor()?->encode());
        $check = $this->checks()[0] ?? '';

        $this->assertStringContainsString('indexed by videos_published_id_idx', $check, 'the window is read by the index alone');
        $this->assertMatchesRegularExpression('/limit 4\)/', $check, 'at most the window\'s entries');
        $this->assertMatchesRegularExpression('/indexed by videos_published_id_idx where .*published_at.* order by/', $check, 'from the page\'s place: its cursor');
    }

    public function test_a_categorys_page_is_checked_within_its_category_from_the_pages_place(): void
    {
        $category = $this->category('Checked');
        $this->videos(6, 'en', category: $category);
        $this->videos(6, 'en');
        $feeds = $this->feedsNamingIndexes(window: 4);
        app()->setLocale('en');
        $first = $feeds->page($feeds->categoryId($category->id), 2, '');

        $this->statements = [];
        $next = $feeds->page($feeds->categoryId($category->id), 2, (string) $first->nextCursor()?->encode());
        $check = $this->checks()[0] ?? '';
        $index = PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0];

        $this->assertCount(2, $next->items());
        $this->assertStringContainsString('indexed by '.$index, $check, 'the window is read by the category\'s index alone');
        $this->assertMatchesRegularExpression('/indexed by '.$index.' where "category_id" = \? and .*published_at.* order by "published_at" desc, "id" desc limit 4\)/', $check, 'within the category, from the page\'s cursor, in the list\'s order');
        $this->assertStringContainsString('indexed by '.$index, $this->page(), 'the page keeps the category\'s index');
        $this->assertSame([$category->id], array_values(array_unique(array_map(fn (Video $video): int => (int) $video->category_id, $next->items()))));
    }

    public function test_a_count_or_a_read_without_a_limit_is_left_as_it_is(): void
    {
        $this->videos(3, 'en');
        $feeds = $this->feedsNamingIndexes();
        app()->setLocale('en');

        $this->statements = [];
        $this->assertSame(3, $feeds->latest()->count());
        $this->assertSame(3, $feeds->latest()->get()->count());
        $this->assertSame([], $this->checks(), 'no check: no page to bound');
    }

    public function test_no_index_is_named_by_the_site_on_sqlite_so_nothing_is_checked(): void
    {
        $this->videos(3, 'en');
        $this->statements = [];
        app(PublicVideoFeedService::class)->latest()->limit(2)->get();

        $this->assertSame([], $this->checks());
        $this->assertStringNotContainsString('indexed by', $this->page());
    }

    /**
     * A feed service that names its indexes on SQLite as it does on MySQL / MariaDB; $window: WALK_WINDOW, $language:
     * WALK_LANGUAGE_ROWS, $bound: WALK_BOUND, $most: WALK_LANGUAGE_MOST, $categoryMost: WALK_CATEGORY_MOST (null: the service's own). SQLite names one index at a time, so a page read
     * by the language's indexes is noted in languageReads (and read by SQLite's own plan); a page read by the index that
     * tests every condition of a category's page is noted in shownReads and read by that index (one index: SQLite names it).
     * $shownIndex: the table has LANGUAGE_SHOWN_INDEX with its 1.2.3 columns (the migrations make it here; without it, a
     * site before the scheduler built it: the checks of the earlier passes alone); $creatorList: the active creators who
     * have videos are few enough to be named (else each entry's creator is checked).
     */
    private function feedsNamingIndexes(int $window = PublicVideoFeedService::WALK_WINDOW, ?int $language = null, ?int $bound = null, ?int $most = null, ?int $categoryMost = null, bool $shownIndex = false, bool $creatorList = true): PublicVideoFeedService
    {
        return new class($window, $language, $bound, $most, $categoryMost, $shownIndex, $creatorList) extends PublicVideoFeedService
        {
            /** @var list<list<string>> */
            public array $languageReads = [];

            /** @var list<string> */
            public array $shownReads = [];

            public function __construct(private readonly int $window, private readonly ?int $language, private readonly ?int $bound, private readonly ?int $most, private readonly ?int $categoryMost, private readonly bool $shownIndex, private readonly bool $creatorList) {}

            protected function shownCreators(): ?array
            {
                return $this->creatorList ? parent::shownCreators() : null;
            }

            protected function applyIndex(Builder $query, string $indexName): bool
            {
                if (! $this->indexExists($indexName)) {
                    return false;
                }

                $query->forceIndex($indexName);

                return true;
            }

            protected function indexExists(string $name): bool
            {
                return Schema::hasIndex('videos', $name);
            }

            /** VideoIndexes::ready() on SQLite: the index with the columns 1.2.3 gives it. */
            protected function indexReady(string $name): bool
            {
                if ($name === PublicFeedWalkTest::SHOWN_INDEX && ! $this->shownIndex) {
                    return false;
                }

                foreach (Schema::getIndexes('videos') as $index) {
                    if ($index['name'] === $name) {
                        return array_map('strval', $index['columns']) === (VideoIndexes::WANTED[$name] ?? null);
                    }
                }

                return false;
            }

            protected function readByLanguage(QueryBuilder $base, array $indexes): void
            {
                $this->languageReads[] = $indexes;
                $base->indexHint = null;
            }

            protected function readByShownIndex(QueryBuilder $base, string $index): void
            {
                $this->shownReads[] = $index;
                parent::readByShownIndex($base, $index);
            }

            protected function walkWindow(): int
            {
                return $this->window;
            }

            protected function walkLanguageRows(): int
            {
                return $this->language ?? parent::walkLanguageRows();
            }

            protected function walkBound(): int
            {
                return $this->bound ?? parent::walkBound();
            }

            protected function walkLanguageMost(): int
            {
                return $this->most ?? parent::walkLanguageMost();
            }

            protected function walkCategoryMost(): int
            {
                return $this->categoryMost ?? parent::walkCategoryMost();
            }
        };
    }

    /**
     * $count of the newest (and most viewed, most commented) entries that visitors are not shown, in $language, of one
     * kind: in the bin, uploads of a banned creator, private, scheduled for later. Returns their ids.
     *
     * @return list<int>
     */
    private function hiddenRun(string $kind, int $count, ?Category $category, int $hoursAgo = 1, string $language = 'en'): array
    {
        $creator = $kind === 'banned creator' ? User::factory()->create(['banned_at' => now()]) : User::factory()->create();
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $publishedAt = $kind === 'scheduled for later' ? now()->addDays(7)->subMinutes($i) : now()->subHours($hoursAgo)->subMinutes($i);
            $video = Video::factory()->for($creator, 'user')->withStatus(match ($kind) {
                'private' => VideoStatus::PRIVATE,
                'scheduled for later' => VideoStatus::PLANNED,
                default => VideoStatus::PUBLIC,
            })->create([
                'category_id' => $category?->id,
                'language' => $language,
                'published_at' => $publishedAt,
                'scheduled_at' => $kind === 'scheduled for later' ? $publishedAt : null,
                'uploaded_at' => now()->subHours($hoursAgo + 1),
                'views' => $kind === 'scheduled for later' ? 0 : 1000 + $i,
                'comments_count' => 50 + $i,
            ]);

            if ($kind === 'bin') {
                $video->delete();
            }

            $ids[] = (int) $video->id;
        }

        return $ids;
    }

    /** @return list<string> the second checks sent (walk(): the visitor's language) */
    private function languageChecks(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'avd_walk_language')));
    }

    /** @return list<string> the category counts sent (walk(): a category's own rows, by the index that tests every condition) */
    private function categoryChecks(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'as "avd_walk_category"')));
    }

    /** @return list<string> the language's sizes sent (walk(): a category's list, each alternative by its index alone) */
    private function languageSizes(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'as "avd_walk_size_')));
    }

    /** @return list<string> the category sizes sent (walk(): a category's entries, by its order's index alone) */
    private function categorySizes(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'as "avd_walk_category_size"')));
    }

    /** @return list<string> the counts of the language's videos a visitor can be shown sent (walk(): shownPlan(), by LANGUAGE_SHOWN_INDEX) */
    private function showableChecks(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'as "avd_walk_showable_')));
    }

    /** @return list<string> the counts of the language's hidden entries sent (walk(): fewHidden(), the newest list) */
    private function hiddenChecks(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'as "avd_walk_entries_')));
    }

    /** @return list<string> the parts read through LANGUAGE_SHOWN_INDEX sent (walk(): shownIds()) */
    private function shownParts(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, '"avd_walk_id"')
            && str_contains($sql, 'indexed by '.self::SHOWN_INDEX) && ! str_contains($sql, 'avd_walk_showable')));
    }

    /** @return list<string> the third checks sent (walk(): the list's entries, a big language only) */
    private function listChecks(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'as "avd_walk_rest"')));
    }

    /** @return list<string> the first checks sent (walk()) */
    private function checks(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'avd_walk_page')));
    }

    /** @return list<string> the parts of the newest list read on their own (walk()) */
    private function parts(): array
    {
        return array_values(array_filter($this->statements, fn (string $sql): bool => str_contains($sql, 'union all') && str_contains($sql, '"avd_walk_id"')));
    }

    /** The last page statement sent (not a check, not an eager load). */
    private function page(): string
    {
        $pages = array_values(array_filter($this->statements, fn (string $sql): bool => str_starts_with($sql, 'select * from "videos"')));

        return (string) end($pages);
    }

    private function category(string $title): Category
    {
        return Category::query()->create(['title' => $title, 'slug' => str($title)->slug()->toString(), 'icon' => 'folder', 'in_menu' => true]);
    }

    /**
     * $count public videos of one creator in $language and $category, published two hours ago (a minute apart), with
     * $views + i views and as many comments; in the bin with $binned. Returns their ids.
     *
     * @return list<int>
     */
    private function rated(int $count, string $language, int $views, Category $category, bool $binned = false): array
    {
        $creator = User::factory()->create();
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $video = Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
                'category_id' => $category->id,
                'language' => $language,
                'published_at' => now()->subHours(2)->subMinutes($i),
                'uploaded_at' => now()->subHours(3),
                'views' => $views + $i,
                'comments_count' => $views + $i,
            ]);

            if ($binned) {
                $video->delete();
            }

            $ids[] = (int) $video->id;
        }

        return $ids;
    }

    private function videos(int $count, ?string $language, int $hoursAgo = 2, ?Category $category = null, bool $imported = false): void
    {
        $creator = User::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
                'category_id' => $category?->id,
                'language' => $language,
                'avdb_id' => $imported ? 7000000 + Video::withTrashed()->count() : null,
                'published_at' => now()->subHours($hoursAgo)->subMinutes($i),
                'uploaded_at' => now()->subHours($hoursAgo + 1),
                'views' => ($i * 7) % 5,
                'comments_count' => $i % 3,
            ]);
        }
    }
}
