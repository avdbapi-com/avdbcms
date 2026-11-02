<?php

namespace Tests\Feature;

use App\Actions\Video\UpdateVideoAction;
use App\Enums\VideoStatus;
use App\Events\Video\VideoPublished;
use App\Http\Requests\Video\UpdateVideoRequest;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Support\VideoBans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The update to 1.2.3 on a site of 1.2.0 - 1.2.2. A scheduled video without a publish date gets its planned time, as the
 * studio stores it. A public video without one was never published: 1.2.2's Unban (or Make public) made it public, a
 * draft or a private video too. It stays as it is (dated, it would jump to the top of Newest), Newest lists it after the
 * dated videos, and Admin actions and Content > All videos tell the owner. The columns Unban reads
 * (videos.status_before_ban and its ban date) are added. All of it is safe to run twice, and a site whose update has not
 * run its last step yet still bans and unbans.
 */
class PublishDatesBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** The migration, found by its date: the same test runs against every name it had. */
    private const MIGRATION = '2026_10_26_100100_*';

    private const COLUMN_MIGRATION = '2026_10_26_100000_add_status_before_ban_to_videos_table';

    private const NEVER_PUBLISHED_TWO = '2 public videos were never published: an older version made them public without a publish date, so they come last among the newest videos. Make public publishes them now, Make private hides them.';

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::factory()->create();
    }

    public function test_scheduled_videos_get_their_planned_time_and_public_ones_without_a_date_stay_as_they_are(): void
    {
        $uploaded = now()->subDays(10)->startOfSecond();
        $created = now()->subDays(12)->startOfSecond();
        $scheduled = now()->addDays(2)->startOfSecond();
        $dated = now()->subDays(30)->startOfSecond();
        $ids = [
            'public' => $this->row(VideoStatus::PUBLIC, ['uploaded_at' => $uploaded, 'created_at' => $created]),
            'public, uploaded yesterday' => $this->row(VideoStatus::PUBLIC, ['uploaded_at' => now()->subDay()]),
            'public, not uploaded' => $this->row(VideoStatus::PUBLIC, ['uploaded_at' => null, 'created_at' => $created]),
            'scheduled' => $this->row(VideoStatus::PLANNED, ['scheduled_at' => $scheduled, 'uploaded_at' => $uploaded]),
            'scheduled, no planned time' => $this->row(VideoStatus::PLANNED, ['uploaded_at' => $uploaded]),
            'private' => $this->row(VideoStatus::PRIVATE, ['uploaded_at' => $uploaded]),
            'draft' => $this->row(VideoStatus::DRAFT, ['uploaded_at' => $uploaded]),
            'banned' => $this->row(VideoStatus::BANNED, ['uploaded_at' => $uploaded]),
            'dated' => $this->row(VideoStatus::PUBLIC, ['published_at' => $dated, 'uploaded_at' => $uploaded]),
        ];
        $stamps = DB::table('videos')->pluck('updated_at', 'id')->all();

        $this->migration()->up();

        $date = fn (string $name): ?string => DB::table('videos')->where('id', $ids[$name])->value('published_at');
        $this->assertTrue($scheduled->eq($date('scheduled')), 'its planned time, as the studio stores it');
        // Never published: a date now would list them as new. A scheduled video without a planned time gets one only when
        // it is given that time (VideoObserver), so it is announced then.
        foreach (['public', 'public, uploaded yesterday', 'public, not uploaded', 'scheduled, no planned time', 'private', 'draft', 'banned'] as $name) {
            $this->assertNull($date($name), "{$name}: left as it is");
        }
        $this->assertTrue($dated->eq($date('dated')), 'a date it had stays');
        $this->assertSame($stamps, DB::table('videos')->pluck('updated_at', 'id')->all(), 'no video counts as changed');

        // Tools > Logs > Admin actions: what got a date, and the public videos visitors see that were never published
        $this->assertSame([
            'update.publish_dates' => '1.2.3: 1 scheduled video got its planned time as its publish date',
            'update.never_published' => '1.2.3: 2 public videos were never published (no publish date): Newest lists them last. See Content > All videos.',
        ], $this->auditEntries());

        // a second run changes nothing and logs nothing new
        $after = DB::table('videos')->orderBy('id')->get()->all();
        $this->migration()->up();
        $this->migration()->down();
        $this->assertEquals($after, DB::table('videos')->orderBy('id')->get()->all());
        $this->assertSame(2, DB::table('admin_audit_logs')->whereIn('action', ['update.publish_dates', 'update.never_published'])->count());
    }

    public function test_a_big_videos_table_is_dated_a_batch_of_ids_at_a_time(): void
    {
        $count = $this->migration()::BATCH * 2 + 5;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['uuid' => (string) Str::uuid(), 'title' => 'Scheduled '.$i, 'slug' => 'scheduled-'.$i, 'original_file_name' => 'u.mp4',
                'user_id' => $this->creator->id, 'status' => VideoStatus::PLANNED->value, 'scheduled_at' => now()->addDay()->startOfSecond(),
                'uploaded_at' => now()->subDay(), 'created_at' => now()->subDays(2), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('videos')->insert($chunk);
        }
        foreach (range(1, 3) as $i) {
            $this->row(VideoStatus::PUBLIC, ['uploaded_at' => now()->subDays($i)]);
        }

        DB::enableQueryLog();
        $this->migration()->up();
        $updates = array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^update [`"]videos[`"] set [`"]published_at[`"] = scheduled_at\b/i', $query['query']) === 1);
        DB::disableQueryLog();

        $this->assertCount(3, $updates, 'three batches');
        $this->assertSame(0, DB::table('videos')->where('status', VideoStatus::PLANNED->value)->whereNull('published_at')->count());
        $this->assertSame(0, DB::table('videos')->where('status', VideoStatus::PLANNED->value)->whereColumn('published_at', '!=', 'scheduled_at')->count());
        $this->assertSame(3, DB::table('videos')->where('status', VideoStatus::PUBLIC->value)->whereNull('published_at')->count(), 'public ones are not dated');
        $this->assertSame([
            'update.publish_dates' => '1.2.3: '.$count.' scheduled videos got their planned time as their publish date',
            'update.never_published' => '1.2.3: 3 public videos were never published (no publish date): Newest lists them last. See Content > All videos.',
        ], $this->auditEntries());
    }

    public function test_one_video_reads_right_in_the_admin_log(): void
    {
        $this->row(VideoStatus::PUBLIC, ['uploaded_at' => now()->subDay()]);
        $this->row(VideoStatus::PLANNED, ['scheduled_at' => now()->addDay(), 'uploaded_at' => now()->subDay()]);

        $this->migration()->up();

        $this->assertSame([
            'update.publish_dates' => '1.2.3: 1 scheduled video got its planned time as its publish date',
            'update.never_published' => '1.2.3: 1 public video was never published (no publish date): Newest lists it last. See Content > All videos.',
        ], $this->auditEntries());
    }

    public function test_a_never_published_public_video_stays_off_the_first_page_of_newest(): void
    {
        // 1.2.2's Unban made a draft public without a publish date. Dated with the day it was uploaded (yesterday), it
        // became the first card of the guests' Newest, the home page's main feed. It stays without a date: Newest lists it
        // after every dated video, once.
        Cache::flush();
        config(['site.feed_cache_ttl' => 0]);
        $category = Category::query()->create(['title' => 'Dates', 'slug' => 'dates', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);
        foreach (range(2, 31) as $days) {
            Video::factory()->for($this->creator)->for($category)->create([
                'status' => VideoStatus::PUBLIC, 'published_at' => now()->subDays($days), 'uploaded_at' => now()->subDays($days), 'language' => null,
            ]);
        }
        $draft = Video::factory()->for($this->creator)->for($category)->create([
            'status' => VideoStatus::DRAFT, 'published_at' => null, 'uploaded_at' => now()->subDay(), 'language' => null,
        ]);
        DB::table('videos')->where('id', $draft->id)->update(['status' => VideoStatus::PUBLIC->value, 'published_at' => null]);

        $this->migration()->up();

        $this->assertNull(DB::table('videos')->where('id', $draft->id)->value('published_at'));
        $pages = $this->newest(12);
        $this->assertNotContains($draft->id, $pages[0], 'not on the first page');
        $listed = array_merge(...$pages);
        $this->assertCount(31, $listed);
        $this->assertSame([$draft->id], array_values(array_slice($listed, -1)), 'listed once, after every dated video');
        $this->assertSame(['1.2.3: 1 public video was never published (no publish date): Newest lists it last. See Content > All videos.'],
            DB::table('admin_audit_logs')->where('action', 'update.never_published')->pluck('details')->all());
    }

    public function test_content_all_videos_points_the_owner_to_the_public_videos_that_were_never_published(): void
    {
        config(['admin.ui' => 'v2']);
        Cache::flush();
        $admin = User::factory()->admin()->create();
        $dated = Video::factory()->for($this->creator)->create(['status' => VideoStatus::PUBLIC, 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $never = Video::factory()->count(2)->for($this->creator)->create(['status' => VideoStatus::PUBLIC, 'uploaded_at' => now()->subDay()])->pluck('id')->all();
        DB::table('videos')->whereIn('id', $never)->update(['published_at' => null]);
        $list = route('admin.videos.index', ['unpublished' => 1], false);

        $this->actingAs($admin)->get(route('admin.videos.index'))->assertOk()
            ->assertSee(self::NEVER_PUBLISHED_TWO)
            ->assertSee('href="'.$list.'"', false)
            ->assertSee('Show 2 videos');

        // their list: only they, the note without its link
        $html = $this->actingAs($admin)->get($list)->assertOk()->assertSee(self::NEVER_PUBLISHED_TWO)->assertDontSee('Show 2 videos')->getContent();
        foreach ($never as $id) {
            $this->assertStringContainsString('data-id="'.$id.'"', $html);
        }
        $this->assertStringNotContainsString('data-id="'.$dated->id.'"', $html);

        // Make public publishes them now, and the note is gone
        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['action' => 'make_public', 'ids' => $never])->assertSessionHas('success', 'Made 2 videos public.');
        $this->assertSame(0, Video::query()->whereIn('id', $never)->whereNull('published_at')->count());
        $this->actingAs($admin)->get(route('admin.videos.index'))->assertOk()->assertDontSee('never published');
    }

    public function test_a_scheduled_video_without_a_planned_time_keeps_no_date_and_is_announced_when_it_gets_one(): void
    {
        // 1.2.2's Visibility field could save "Scheduled" without a planned time. Dated with its upload date, it was never
        // announced: the studio keeps a past publish date for good, so a planned time given later never matched it
        // (video:published announces published_at = scheduled_at).
        $video = Video::factory()->for($this->creator)->create(['status' => VideoStatus::PRIVATE, 'published_at' => null, 'uploaded_at' => now()->subDays(10)]);
        DB::table('videos')->where('id', $video->id)->update(['status' => VideoStatus::PLANNED->value, 'scheduled_at' => null, 'published_at' => null]);

        $this->migration()->up();
        $this->assertNull(DB::table('videos')->where('id', $video->id)->value('published_at'));

        // the creator gives it a planned time in the studio (UpdateVideoAction's own rule for the date)
        $when = now()->addDay()->startOfMinute();
        $request = UpdateVideoRequest::create('/', 'PUT', ['status' => VideoStatus::PLANNED->value, 'scheduled_at' => $when->toDateTimeString()]);
        $publishedAt = (new ReflectionMethod(UpdateVideoAction::class, 'determinePublishedAt'))->invoke(new UpdateVideoAction(), $request, $video->fresh());
        $video->fresh()->update(['status' => VideoStatus::PLANNED, 'scheduled_at' => $when, 'published_at' => $publishedAt]);
        $this->assertTrue($when->eq($video->fresh()->published_at));

        Event::fake([VideoPublished::class]);
        $this->travelTo($when->copy()->addSeconds(5));
        $this->artisan('video:published')->assertExitCode(0);
        Event::assertDispatched(VideoPublished::class, fn (VideoPublished $event): bool => $event->video->id === $video->id);
    }

    public function test_a_public_video_without_a_publish_date_can_be_indexed_for_search(): void
    {
        // a site with Meilisearch indexes every public video (Video::toSearchableArray); one without a publish date
        // stopped the indexing with an error (and the save of the video with it)
        $video = Video::factory()->for($this->creator)->create(['status' => VideoStatus::PUBLIC, 'uploaded_at' => now()->subDay()]);
        DB::table('videos')->where('id', $video->id)->update(['published_at' => null]);
        $video = $video->fresh();

        $this->assertTrue($video->shouldBeSearchable());
        $this->assertNull($video->toSearchableArray()['published_at']);
    }

    public function test_the_ban_columns_are_added_once_and_a_site_before_the_last_update_step_still_bans_and_unbans(): void
    {
        // 1.2.2's database while the new files run before "Finish update": no status_before_ban (nor its date) yet
        $columns = array_values(array_filter([VideoBans::COLUMN, VideoBans::COLUMN.'_at'], fn (string $column): bool => Schema::hasColumn('videos', $column)));
        Schema::table('videos', fn (Blueprint $table) => $table->dropColumn($columns));
        $this->assertFalse(VideoBans::available());
        $admin = User::factory()->admin()->create();
        $video = Video::factory()->for($this->creator)->create(['status' => VideoStatus::UNLISTED]);
        $other = Video::factory()->for($this->creator)->create(['status' => VideoStatus::PRIVATE]);

        $this->actingAs($admin)->post(route('admin.videos.ban', $video))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['action' => 'ban', 'ids' => [$other->id]])->assertSessionHas('success', 'Banned 1 video.');
        $this->assertSame([VideoStatus::BANNED, VideoStatus::BANNED], [$video->fresh()->status, $other->fresh()->status]);

        // no status was kept: they come back Private, the safe way
        $this->actingAs($admin)->post(route('admin.videos.unban', $video))->assertSessionHas('success', 'Video unbanned. It is private now, because its status from before the ban is not known. You can make it public on its page.');
        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => [$other->id]])->assertSessionHas('success', 'Unbanned 1 video. 1 video is private now, because its status from before the ban is not known.');
        $this->assertSame([VideoStatus::PRIVATE, VideoStatus::PRIVATE], [$video->fresh()->status, $other->fresh()->status]);

        // the update's last step adds both columns, once
        $column = $this->migration(self::COLUMN_MIGRATION);
        $column->up();
        $column->up();
        $this->assertTrue(Schema::hasColumns('videos', [VideoBans::COLUMN, VideoBans::COLUMN.'_at']));
        $this->assertTrue(VideoBans::available());
        $column->down();
        $this->assertTrue(VideoBans::available(), 'down() keeps the statuses the columns hold');
    }

    /** @param array<string, mixed> $values */
    private function row(VideoStatus $status, array $values = []): int
    {
        return (int) DB::table('videos')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'title' => 'Backfill '.$status->name, 'slug' => 'backfill', 'original_file_name' => 'b.mp4',
            'user_id' => $this->creator->id, 'status' => $status->value, 'published_at' => null, 'scheduled_at' => null,
            'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(5)->startOfSecond(),
        ], $values));
    }

    /** @return array<string, string> the update's entries in Tools > Logs > Admin actions, action => details */
    private function auditEntries(): array
    {
        return DB::table('admin_audit_logs')->whereIn('action', ['update.publish_dates', 'update.never_published'])->orderBy('id')->pluck('details', 'action')->all();
    }

    /** @return list<list<int>> the ids of each page of the guests' Newest, following next_cursor */
    private function newest(int $perPage): array
    {
        $pages = [];
        $cursor = null;

        do {
            $page = $this->getJson(route('videos.index', array_filter(['per_page' => $perPage, 'cursor' => $cursor])))->assertOk();
            $pages[] = array_column($page->json('data'), 'id');
            $cursor = $page->json('meta.next_cursor');
        } while (is_string($cursor) && count($pages) < 20);

        return $pages;
    }

    private function migration(string $name = self::MIGRATION): Migration
    {
        $files = glob(database_path('migrations/'.$name.'.php')) ?: [];
        $this->assertCount(1, $files, $name);

        return require $files[0];
    }
}
