<?php

namespace Tests\Feature;

use App\Console\Commands\BuildDiscoverySnapshots;
use App\Enums\VideoStatus;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Services\SmartDiscoveryRailService;
use App\Services\VideoDatabaseSearchService;
use App\Support\DiscoverySnapshots;
use App\Support\SpotlightChips;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * The check's DB-V1-2 (look-around DB-X2): Home's rails, its shorts shelf and the Ctrl+K chips show the answers 1.2.2
 * showed (every video of a rail's window counted, every video looked through for short ones and chips) without a page
 * of a big site waiting for them: App\Support\DiscoverySnapshots keeps what the scheduler's task discovery:snapshots
 * works out in the background, as current as the rails always were.
 */
class DiscoverySnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // a big site (every rail's window holds more than a page counts) with a scheduler
        if (class_exists(DiscoverySnapshots::class)) {
            DiscoverySnapshots::$inlineRows = 0;
            DiscoverySnapshots::$schedulerRuns = true;
        }

        config(['site.smart_discovery_rail_enabled' => true, 'site.smart_discovery_rail_limit' => 5, 'site.smart_discovery_rail_lookback_days' => 7]);
    }

    protected function tearDown(): void
    {
        if (class_exists(DiscoverySnapshots::class)) {
            DiscoverySnapshots::$schedulerRuns = null;
            DiscoverySnapshots::$inlineRows = null;
        }

        parent::tearDown();
    }

    public function test_a_snapshot_is_shown_while_current_the_last_one_while_a_newer_one_is_worked_out_and_none_after_six_hours(): void
    {
        $tag = Tag::query()->create(['name' => 'Counted', 'slug' => 'counted', 'usage_count' => 0]);
        $this->taggedVideos($tag, 2);

        $this->assertSame([], $this->tags(), 'no snapshot yet: no rail');
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame(['Counted' => 2], $this->tags());

        // a new video: the rail keeps its count while it is current (10 minutes, as before), like 1.2.2's cache
        $this->taggedVideos($tag, 1);
        $this->travel(5)->minutes();
        $this->assertSame(['Counted' => 2], $this->tags());

        // no longer current: the last count while the task works out the next one, then the next one
        $this->travel(6)->minutes();
        $this->assertTrue(BuildDiscoverySnapshots::due());
        $this->assertSame(['Counted' => 2], $this->tags());
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame(['Counted' => 3], $this->tags());

        // six hours without the task: never so old a count
        $this->travel(7)->hours();
        $this->assertSame([], $this->tags());
    }

    public function test_the_task_works_out_what_pages_asked_for_ahead_of_time_within_its_seconds_and_forgets_what_nobody_asks_for(): void
    {
        $tag = Tag::query()->create(['name' => 'Counted', 'slug' => 'counted', 'usage_count' => 0]);
        $this->taggedVideos($tag, 2);
        $this->assertFalse(BuildDiscoverySnapshots::due(), 'nothing asked for yet');

        $this->tags();
        app()->setLocale('ja');
        $this->tags();
        app()->setLocale('en');
        $this->assertCount(2, DiscoverySnapshots::dueAnswers(), 'one answer per language');

        // no time left: no answer is started, and the run says the next one goes on
        $this->artisan('discovery:snapshots', ['--seconds' => 0])->expectsOutputToContain('more in the next run')->assertSuccessful();
        $this->assertCount(2, DiscoverySnapshots::dueAnswers());

        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame([], DiscoverySnapshots::dueAnswers());
        $this->assertFalse(BuildDiscoverySnapshots::due());

        // worked out again before its snapshot stops being current: pages never see an old one
        $this->travel(9)->minutes();
        $this->assertCount(2, DiscoverySnapshots::dueAnswers());
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame(['Counted' => 2], $this->tags());

        // an answer no page asked for in an hour is not worked out again
        $this->travel(2)->hours();
        $this->assertFalse(BuildDiscoverySnapshots::due());
    }

    public function test_an_answer_the_task_cannot_work_out_is_tried_again_after_ten_minutes_and_the_others_are_still_worked_out(): void
    {
        $tag = Tag::query()->create(['name' => 'Counted', 'slug' => 'counted', 'usage_count' => 0]);
        $this->taggedVideos($tag, 2);
        $this->app->instance(VideoDatabaseSearchService::class, new class extends VideoDatabaseSearchService
        {
            public function findsVideos(Builder $query, string $term, bool $bounded = true): bool
            {
                if (! $bounded) {
                    throw new RuntimeException('the database went away');
                }

                return false;
            }

            public function lastCheckWasBounded(): bool
            {
                return true;
            }
        });

        $this->assertSame([], SpotlightChips::all());
        $this->tags();
        $this->artisan('discovery:snapshots')->expectsOutputToContain('tried again in 10 minutes')->assertSuccessful();
        $this->assertSame(['Counted' => 2], $this->tags(), 'the other answer was worked out');
        $this->assertFalse(BuildDiscoverySnapshots::due(), 'not every minute');

        $this->travel(11)->minutes();
        SpotlightChips::all();
        $this->assertContains('chips', array_column(DiscoverySnapshots::dueAnswers(), 'name'), 'tried again after ten minutes');
    }

    public function test_the_scheduler_starts_the_task_every_minute_in_the_background_only_while_an_answer_is_due(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'discovery:snapshots'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertStringContainsString('--seconds=50', (string) $event->command);
        $this->assertFalse($event->filtersPass($this->app), 'nothing wanted: not started');

        $this->taggedVideos(Tag::query()->create(['name' => 'Counted', 'slug' => 'counted', 'usage_count' => 0]), 1);
        $this->tags();
        $this->assertTrue($event->filtersPass($this->app));
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertFalse($event->filtersPass($this->app), 'worked out: not started again until it is due');
    }

    public function test_a_chip_the_page_finds_only_among_older_videos_is_shown_once_the_task_checked_every_video(): void
    {
        $creator = User::factory()->create();
        foreach (['A Japanese story', 'Filmed in 4K'] as $title) {
            Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create(['title' => $title, 'description' => 'plain', 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        }
        // the page's check reads only the newest videos where no index serves a match; here the "4K" video is older
        $this->app->instance(VideoDatabaseSearchService::class, new class extends VideoDatabaseSearchService
        {
            private bool $cut = false;

            public function findsVideos(Builder $query, string $term, bool $bounded = true): bool
            {
                $this->cut = $bounded && $term === '4K';

                return $this->cut ? false : parent::findsVideos($query, $term, $bounded);
            }

            public function lastCheckWasBounded(): bool
            {
                return $this->cut;
            }
        });

        $this->assertSame(['Japanese'], array_column(SpotlightChips::all(), 'label'), 'what the page found: never an empty chip');
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $this->assertSame(['Japanese', '4K Ultra HD'], array_column(SpotlightChips::all(), 'label'), 'every video checked: the chips 1.2.2 showed');

        // without a scheduler the page checks every video, as before 1.2.3
        Cache::flush();
        DiscoverySnapshots::$schedulerRuns = false;
        $this->assertSame(['Japanese', '4K Ultra HD'], array_column(SpotlightChips::all(), 'label'));
    }

    public function test_pages_write_only_their_own_keys_and_the_task_only_the_snapshots(): void
    {
        // a cron that runs as another system user never leaves a page a cache file it cannot replace
        $tag = Tag::query()->create(['name' => 'Counted', 'slug' => 'counted', 'usage_count' => 0]);
        $this->taggedVideos($tag, 2);
        $written = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
            $written[] = $event->key;
        });

        $this->tags();
        $this->travel(2)->minutes();
        $this->tags();
        $page = $written;
        $written = [];
        $this->artisan('discovery:snapshots')->assertSuccessful();
        $task = $written;
        $written = [];
        $this->tags();
        $page = array_merge($page, $written);

        $this->assertNotSame([], $page);
        $this->assertSame([], array_values(array_filter($page, fn (string $key): bool => str_starts_with($key, 'discovery-snapshots:')
            && ! preg_match('/(:page|:quick|:wanted)$/', $key))), 'a page writes its own keys only');
        $this->assertNotSame([], $task);
        $this->assertSame([], array_values(array_filter($task, fn (string $key): bool => str_starts_with($key, 'discovery-snapshots:')
            && ! preg_match('/^discovery-snapshots:v1:\w+:[0-9a-f]{32}:(snapshot|failed)$/', $key))), 'the task writes snapshots only');
    }

    /** @return array<string, int> the tags rail as Home shows it: name => count */
    private function tags(): array
    {
        return app(SmartDiscoveryRailService::class)->tags()->mapWithKeys(fn ($tag) => [$tag->name => (int) $tag->smart_discovery_videos_count])->all();
    }

    private function taggedVideos(Tag $tag, int $count): void
    {
        $creator = User::factory()->create();

        for ($i = 0; $i < $count; $i++) {
            Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
                'language' => null,
                'published_at' => now()->subHours(2 + $i),
                'uploaded_at' => now()->subHours(3 + $i),
            ])->tags()->attach($tag->id);
        }
    }
}
