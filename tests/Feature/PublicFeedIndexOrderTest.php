<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Support\DiscoverySnapshots;
use App\Support\VideoIndexes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R3-022 and its look-around: on MySQL / MariaDB the newest, trending and most viewed feeds are read in the order of an
 * index whose columns are the feed's order (newest first: (published_at, id); most viewed: (views, published_at, id)),
 * each row checked as it comes, so a page reads about as many rows as it shows instead of sorting every active video.
 * The index a feed names must keep matching its order: a feed ordered otherwise would be sorted again whatever it names.
 * The views-ordered feeds fall back to the index 1.2.2 named until the new one is built (on a big table by the scheduler).
 * A category's list likewise: (category_id, published_at, id), its category held to one value, then 1.2.2's index.
 */
class PublicFeedIndexOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_index_a_feed_is_read_by_has_the_feeds_order(): void
    {
        $feeds = app(PublicVideoFeedService::class);
        $indexes = collect(Schema::getIndexes('videos'))->keyBy('name');

        foreach ([
            'latest' => 'videos_published_id_idx',
            'trending' => PublicVideoFeedService::VIEWS_ORDER_INDEXES[0],
            'mostViewed' => PublicVideoFeedService::VIEWS_ORDER_INDEXES[0],
            // DB-X1: the stored count of public comments and its 1.2.0 index
            'mostCommented' => PublicVideoFeedService::COMMENTS_ORDER_INDEX,
        ] as $feed => $index) {
            $orders = $feeds->{$feed}()->getQuery()->orders;

            $this->assertTrue($indexes->has($index), $index.' exists on a site installed by the migrations');
            $this->assertSame(array_column($orders, 'column'), $indexes[$index]['columns'], $feed.'(): the index has the order\'s columns');
            $this->assertSame(['desc'], array_values(array_unique(array_column($orders, 'direction'))), $feed.'(): one direction (a backward read)');
        }

        $this->assertSame(['views', 'published_at', 'id'], VideoIndexes::WANTED[PublicVideoFeedService::VIEWS_ORDER_INDEXES[0]], 'every site gets it (the update step or the scheduler)');
        $this->assertSame(['comments_count', 'published_at', 'id'], VideoIndexes::WANTED[PublicVideoFeedService::COMMENTS_ORDER_INDEX], 'every site keeps it (since 1.2.0)');

        // a category's list: its category, then its order
        $category = $feeds->categoryId(7)->getQuery();
        $index = PublicVideoFeedService::CATEGORY_ORDER_INDEXES[0];
        $this->assertTrue($indexes->has($index), $index.' exists on a site installed by the migrations');
        $this->assertSame(array_merge(['category_id'], array_column($category->orders, 'column')), $indexes[$index]['columns'], 'categoryId(): the index has the category and the order\'s columns');
        $this->assertSame(['desc'], array_values(array_unique(array_column($category->orders, 'direction'))), 'categoryId(): one direction (a backward read)');
        $this->assertContains(['type' => 'Basic', 'column' => 'category_id', 'operator' => '=', 'value' => 7, 'boolean' => 'and'], $category->wheres, 'categoryId(): one category');
        $this->assertSame(['category_id', 'published_at', 'id'], VideoIndexes::WANTED[$index], 'every site gets it (the update step or the scheduler)');

        foreach (PublicVideoFeedService::WALK_INDEXES as $index => $columns) {
            $this->assertSame($columns, $indexes[$index]['columns'], $index.': walk() reads it in this order');
        }
    }

    public function test_the_views_feeds_name_the_new_index_and_fall_back_to_the_earlier_one(): void
    {
        foreach ([
            'both indexes' => [['videos_views_published_id_idx', 'videos_status_views_id_idx'], 'videos_views_published_id_idx', ['videos_views_published_id_idx']],
            'before the scheduler built it' => [['videos_status_views_id_idx'], 'videos_status_views_id_idx', ['videos_views_published_id_idx', 'videos_status_views_id_idx']],
            'neither' => [[], null, ['videos_views_published_id_idx', 'videos_status_views_id_idx']],
        ] as $case => [$has, $named, $asked]) {
            foreach (['trending', 'mostViewed'] as $feed) {
                $feeds = $this->feedsWith($has);
                $feeds->{$feed}();

                $this->assertSame($asked, $feeds->asked, $case.', '.$feed.'(): the indexes asked for, in order');
                $this->assertSame($named, $feeds->named, $case.', '.$feed.'(): the index named');
            }
        }
    }

    public function test_a_categorys_list_names_the_new_index_and_falls_back_to_the_earlier_one(): void
    {
        foreach ([
            'both indexes' => [PublicVideoFeedService::CATEGORY_ORDER_INDEXES, 'videos_category_published_id_idx', ['videos_category_published_id_idx']],
            'before the scheduler built it' => [['videos_category_status_published_id_idx'], 'videos_category_status_published_id_idx', PublicVideoFeedService::CATEGORY_ORDER_INDEXES],
            'neither' => [[], null, PublicVideoFeedService::CATEGORY_ORDER_INDEXES],
        ] as $case => [$has, $named, $asked]) {
            $feeds = $this->feedsWith($has);
            $feeds->categoryId(3);

            $this->assertSame($asked, $feeds->asked, $case.': the indexes asked for, in order');
            $this->assertSame($named, $feeds->named, $case.': the index named');
        }
    }

    public function test_the_shorts_shelf_looks_among_the_most_viewed_videos_only(): void
    {
        // a computed length filter no index serves: the page reads the SHORTS_FROM_MOST_VIEWED most viewed videos, never
        // every public video (look-around DB-X2), in the trending order
        $creator = User::factory()->create();
        foreach ([['Long', 100, 1800], ['Popular Short', 50, 60], ['Quiet Short', 10, 90]] as [$title, $views, $seconds]) {
            Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create(['title' => $title, 'views' => $views, 'duration' => (string) $seconds]);
        }
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        Cache::flush();
        $this->assertSame(['Popular Short', 'Quiet Short'], app(PublicVideoFeedService::class)->heroShorts()->pluck('title')->all());
        $this->assertNotEmpty(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'limit '.PublicVideoFeedService::SHORTS_FROM_MOST_VIEWED.')')));

        // a site with more videos than the page looks through (DB-V1-2): the short videos found there first (the same as
        // 1.2.2's first ones), and every one 1.2.2 showed once the scheduler's task looked through every video
        Cache::flush();
        $topTwo = new class extends PublicVideoFeedService
        {
            public const SHORTS_FROM_MOST_VIEWED = 2;
        };
        DiscoverySnapshots::$schedulerRuns = true;

        try {
            $this->assertSame(['Popular Short'], $topTwo->heroShorts()->pluck('title')->all(), 'only among the two most viewed, at first');
            $this->artisan('discovery:snapshots')->assertSuccessful();
            $this->assertSame(['Popular Short', 'Quiet Short'], $topTwo->heroShorts()->pluck('title')->all(), 'every short video, from the background');

            // a video that leaves the site leaves the shelf at once
            Video::query()->where('title', 'Quiet Short')->first()->delete();
            $this->assertSame(['Popular Short'], $topTwo->heroShorts()->pluck('title')->all());

            // a site where no scheduler runs looks through every video in the page, as before 1.2.3
            Cache::flush();
            DiscoverySnapshots::$schedulerRuns = false;
            Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Third Short', 'views' => 5, 'duration' => '30']);
            $this->assertSame(['Popular Short', 'Third Short'], $topTwo->heroShorts()->pluck('title')->all());
        } finally {
            DiscoverySnapshots::$schedulerRuns = null;
        }
    }

    public function test_sqlite_and_other_drivers_get_no_index_hint(): void
    {
        $feeds = app(PublicVideoFeedService::class);

        foreach (['latest', 'trending', 'mostViewed', 'mostCommented'] as $feed) {
            $this->assertStringNotContainsStringIgnoringCase('indexed by', $feeds->{$feed}()->toSql(), $feed.'()');
        }

        $this->assertStringNotContainsStringIgnoringCase('indexed by', $feeds->categoryId(1)->toSql(), 'categoryId()');
    }

    /** @param  list<string>  $has  the indexes the (pretended MySQL) table has */
    private function feedsWith(array $has): PublicVideoFeedService
    {
        return new class($has) extends PublicVideoFeedService
        {
            /** @var list<string> */
            public array $asked = [];

            public ?string $named = null;

            /** @param  list<string>  $has */
            public function __construct(private readonly array $has) {}

            protected function applyIndex(Builder $query, string $indexName): bool
            {
                $this->asked[] = $indexName;

                if (! in_array($indexName, $this->has, true)) {
                    return false;
                }

                $this->named = $indexName;

                return true;
            }
        };
    }
}
