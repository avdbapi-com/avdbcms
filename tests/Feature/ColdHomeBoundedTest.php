<?php

namespace Tests\Feature;

use App\Console\Commands\DeleteUnconfirmedUsers;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\AdaptiveTrendingCollectionsService;
use App\Services\SmartDiscoveryRailService;
use App\Support\CategoryVideoCounts;
use App\Support\DiscoverySnapshots;
use App\Support\VideoIndexes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Look-around DB-X2 (and the review's DB-RV-2): a cold home page read every video of the catalogue several times (the
 * tags rail every video of its 30 days, the creators rail every one of its 60 days, the side menu's counts every row
 * of their categories, the Ctrl+K chips the whole table when no index served them): 6-29 s at 300,000 videos. Each read
 * of a page is bounded now; the rails still count every video of their window (the check's DB-V1-2): the page where the
 * window is small, else the scheduler's task in the background (App\Support\DiscoverySnapshots).
 */
class ColdHomeBoundedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        CategoryVideoCounts::flush();
    }

    protected function tearDown(): void
    {
        if (class_exists(DiscoverySnapshots::class)) {
            DiscoverySnapshots::$schedulerRuns = null;
            DiscoverySnapshots::$inlineRows = null;
        }

        parent::tearDown();
    }

    /** A site whose rails' windows hold more videos and view rows than a page counts itself, with a scheduler. */
    private function bigWindows(): void
    {
        Cache::flush();
        DiscoverySnapshots::$inlineRows = 1;
        DiscoverySnapshots::$schedulerRuns = true;
    }

    public function test_the_tags_rail_counts_every_video_of_its_window_by_the_page_or_in_the_background(): void
    {
        $creator = User::factory()->create();
        $old = Tag::query()->create(['name' => 'Older', 'slug' => 'older', 'usage_count' => 0]);
        $new = Tag::query()->create(['name' => 'Newer', 'slug' => 'newer', 'usage_count' => 0]);
        // three older videos with "Older" (a day ago), two newer ones with "Newer" (an hour ago)
        foreach ([[$old, 24], [$old, 25], [$old, 26], [$new, 1], [$new, 2]] as [$tag, $hoursAgo]) {
            $this->video($creator, $hoursAgo)->tags()->attach($tag->id);
        }
        config(['site.smart_discovery_rail_enabled' => true, 'site.smart_discovery_rail_limit' => 5, 'site.smart_discovery_rail_lookback_days' => 7]);
        $exact = ['Older' => 3, 'Newer' => 2];

        // a window of few videos: the page counts every one of them, as before
        $this->assertSame($exact, $this->tagCounts(app(SmartDiscoveryRailService::class)));

        // a window of more videos than a page counts (DB-V1-2): never a count of some of them; the scheduler's task
        // counts all of them and the rail shows its count from then on
        $this->bigWindows();
        $this->assertSame([], $this->tagCounts(app(SmartDiscoveryRailService::class)), 'before the first count: no rail (Home shows its categories)');
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame($exact, $this->tagCounts(app(SmartDiscoveryRailService::class)));

        // a site where no scheduler runs counts in the page, as before 1.2.3
        Cache::flush();
        DiscoverySnapshots::$schedulerRuns = false;
        $this->assertSame($exact, $this->tagCounts(app(SmartDiscoveryRailService::class)));
    }

    public function test_the_creators_rail_counts_every_video_of_its_window_and_of_every_date_by_the_page_or_in_the_background(): void
    {
        [$busy, $recent, $older] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        foreach ([[$busy, 30], [$busy, 31], [$busy, 32], [$recent, 1]] as [$creator, $hoursAgo]) {
            $this->video($creator, $hoursAgo);
        }
        // a creator of the fallback "of every date" only (ten days ago, outside the 7 days)
        $this->video($older, 240);
        $this->video($older, 241);
        config(['site.creator_spotlight_enabled' => true, 'site.creator_spotlight_limit' => 2, 'site.creator_spotlight_lookback_days' => 7]);

        $this->assertSame([$busy->id => 3, $recent->id => 1], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)));

        $this->bigWindows();
        $this->assertSame([], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)));
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame([$busy->id => 3, $recent->id => 1], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)));

        // three creators wanted, two in the window: the count of every date, in the background too
        config(['site.creator_spotlight_limit' => 3]);
        $this->assertSame([], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)));
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame([$busy->id => 3, $older->id => 2, $recent->id => 1], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)));

        // a small window on a big catalogue: the window by the page, the count of every date in the background
        DiscoverySnapshots::$inlineRows = 4;
        Cache::flush();
        config(['site.creator_spotlight_limit' => 2]);
        $this->assertSame([$busy->id => 3, $recent->id => 1], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)), 'the window has enough creators');
        config(['site.creator_spotlight_limit' => 3]);
        $this->assertSame([], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)), 'the count of every date reads the whole catalogue');
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame([$busy->id => 3, $older->id => 2, $recent->id => 1], $this->creatorCounts(app(AdaptiveCreatorSpotlightService::class)));
    }

    public function test_the_actors_rail_counts_every_view_row_of_its_window_by_the_page_or_in_the_background(): void
    {
        $creator = User::factory()->create();
        $older = $this->video($creator, 50);
        $older->update(['avdb_actor' => 'Older Actor']);
        $newer = $this->video($creator, 2);
        $newer->update(['avdb_actor' => 'Newer Actor']);
        foreach ([[$older, 5, 10], [$older, 4, 10], [$newer, 0, 3]] as [$video, $daysAgo, $views]) {
            DB::table('video_view_daily_stats')->insert(['video_id' => $video->id, 'view_date' => now()->subDays($daysAgo)->toDateString(),
                'views_count' => $views, 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['site.trending_collections_enabled' => true, 'site.trending_collections_limit' => 5, 'site.trending_collections_lookback_days' => 14]);
        $exact = ['Older Actor' => 20, 'Newer Actor' => 3];

        $this->assertSame($exact, $this->actorViews(app(AdaptiveTrendingCollectionsService::class)));

        $this->bigWindows();
        $this->assertSame([], $this->actorViews(app(AdaptiveTrendingCollectionsService::class)));
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame($exact, $this->actorViews(app(AdaptiveTrendingCollectionsService::class)));
    }

    public function test_active_with_a_list_of_creators_keeps_the_videos_of_the_creators_check(): void
    {
        $active = User::factory()->create();
        $unconfirmed = User::factory()->create(['email_verified_at' => null]);
        $banned = User::factory()->create(['banned_at' => now()]);
        $deleted = User::factory()->create();
        foreach ([$active, $unconfirmed, $banned, $deleted] as $creator) {
            $this->video($creator, 3);
            $this->video($creator, 4, VideoStatus::PRIVATE);
        }
        $deleted->delete();

        $creators = User::query()->whereIn('id', Video::query()->withTrashed()->distinct()->pluck('user_id'))->active()->pluck('id')->all();

        $this->assertSame([$active->id], $creators);
        $this->assertSame(
            Video::query()->active()->orderBy('id')->pluck('id')->all(),
            Video::query()->active(false, $creators)->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame([], Video::query()->active(false, [])->pluck('id')->all(), 'no active creator: no video');
    }

    public function test_the_categories_page_counts_every_category_once_and_its_side_menu_comes_from_that_count(): void
    {
        $creator = User::factory()->create();
        $menu = Category::query()->create(['title' => 'In Menu', 'slug' => 'in-menu', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);
        $other = Category::query()->create(['title' => 'Not In Menu', 'slug' => 'not-in-menu', 'icon' => 'film', 'in_menu' => false, 'position' => 2]);
        $this->video($creator, 3, VideoStatus::PUBLIC, $menu);
        $this->video($creator, 4, VideoStatus::PUBLIC, $other);
        $counts = [];
        DB::listen(function ($query) use (&$counts): void {
            if (preg_match('/count\(\*\)/i', $query->sql) && str_contains($query->sql, '"categories"')) {
                $counts[] = $query->sql;
            }
        });

        $this->get(route('pages.categories'))->assertOk()->assertViewHas('categories', fn ($categories) => $categories->pluck('videos_count', 'title')->all() === ['In Menu' => 1, 'Not In Menu' => 1]);

        $this->assertCount(1, $counts, 'every category counted once: the side menu takes its own from that count');
    }

    public function test_the_hourly_clean_up_of_unconfirmed_accounts_stops_after_its_time_and_the_next_run_goes_on(): void
    {
        config(['site.email_verification_required' => true]);
        $accounts = User::factory()->count(3)->create(['email_verified_at' => null, 'created_at' => now()->subDays(3)]);

        // no time left: no account is started, and the run says the next one goes on
        $this->artisan('users:delete-unconfirmed', ['--seconds' => 0])->expectsOutputToContain('more in the next run')->assertSuccessful();
        $this->assertSame(3, User::query()->whereKey($accounts->modelKeys())->count());

        $this->artisan('users:delete-unconfirmed')->expectsOutputToContain('Delete 3 unconfirmed users')->assertSuccessful();
        $this->assertSame(0, User::withTrashed()->whereKey($accounts->modelKeys())->count());

        // one run at a time, beside the scheduler's other tasks
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'users:delete-unconfirmed'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertSame('0 * * * *', $event->expression, 'still once an hour');
    }

    public function test_an_older_site_gets_the_1_2_3_count_and_category_indexes_and_keeps_the_comments_index(): void
    {
        // the 1.2.0 definition of the count index, no index of a category's list order (1.2.2), and a site that lost the
        // most commented order's index
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('videos_category_active_count_idx');
            $table->dropIndex('videos_comments_count_published_id_idx');
            $table->dropIndex('videos_category_published_id_idx');
        });
        Schema::table('videos', fn (Blueprint $table) => $table->index(['category_id', 'status', 'deleted_at', 'uploaded_at', 'published_at', 'scheduled_at'], 'videos_category_active_count_idx'));
        VideoIndexes::forget();

        $this->assertSame(['videos_category_active_count_idx', 'videos_comments_count_published_id_idx', 'videos_category_published_id_idx'], array_keys(VideoIndexes::pending()));

        (require base_path('database/migrations/2026_10_29_110000_align_video_index_definitions.php'))->up();

        $this->assertSame([], VideoIndexes::pending());
        $indexes = collect(Schema::getIndexes('videos'))->keyBy('name');
        $this->assertSame(VideoIndexes::WANTED['videos_category_active_count_idx'], $indexes['videos_category_active_count_idx']['columns']);
        $this->assertSame(['comments_count', 'published_at', 'id'], $indexes['videos_comments_count_published_id_idx']['columns']);
        $this->assertSame(['category_id', 'published_at', 'id'], $indexes['videos_category_published_id_idx']['columns']);
    }

    private function video(User $creator, int $hoursAgo, VideoStatus $status = VideoStatus::PUBLIC, ?Category $category = null): Video
    {
        return Video::factory()->for($creator, 'user')->withStatus($status)->create([
            'category_id' => $category?->id,
            'language' => null,
            'published_at' => now()->subHours($hoursAgo),
            'uploaded_at' => now()->subHours($hoursAgo + 1),
        ]);
    }

    /** @return array<string, int> */
    private function tagCounts(SmartDiscoveryRailService $rail): array
    {
        return $rail->tags()->mapWithKeys(fn ($tag) => [$tag->name => (int) $tag->smart_discovery_videos_count])->all();
    }

    /** @return array<string, int> */
    private function actorViews(AdaptiveTrendingCollectionsService $rail): array
    {
        return collect($rail->collections())->mapWithKeys(fn ($row) => [$row->actor_name => (int) $row->trending_collections_views_count])->all();
    }

    /** @return array<int, int> */
    private function creatorCounts(AdaptiveCreatorSpotlightService $rail): array
    {
        return $rail->creators()->mapWithKeys(fn ($creator) => [(int) $creator->id => (int) $creator->creator_spotlight_videos_count])->all();
    }
}
