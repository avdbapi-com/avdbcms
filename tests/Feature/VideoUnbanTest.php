<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Support\VideoBans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin > Videos > Unban (and the bulk bar's Unban, Members > Ban with the member's videos) gives a video back the status
 * it had before the ban. 1.2.2 made every unbanned video public: a private video or a draft too (its creator never chose
 * that), and one banned before it was ever published became public without a publish date, which broke "load more" of
 * Most viewed and Trending for every visitor. A video banned before 1.2.3 kept no status: it comes back Private, and
 * the answer says so (App\Support\VideoBans::LEGACY_STATUS).
 */
class VideoUnbanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $creator;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['site.feed_cache_ttl' => 0]);
        $this->admin = User::factory()->admin()->create();
        $this->creator = User::factory()->create();
        $this->category = Category::query()->create(['title' => 'Unban', 'slug' => 'unban', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);
    }

    /** @return array<string, array{0: int}> */
    public static function statuses(): array
    {
        return [
            'public' => [VideoStatus::PUBLIC->value],
            'private' => [VideoStatus::PRIVATE->value],
            'unlisted' => [VideoStatus::UNLISTED->value],
            'scheduled' => [VideoStatus::PLANNED->value],
            'draft' => [VideoStatus::DRAFT->value],
            'failed' => [VideoStatus::FAILED->value],
        ];
    }

    #[DataProvider('statuses')]
    public function test_unban_gives_the_video_the_status_it_had_before_the_ban(int $value): void
    {
        $status = VideoStatus::from($value);
        $video = $this->video($status);
        $publishedAt = $video->published_at?->toDateTimeString();

        $this->actingAs($this->admin)->post(route('admin.videos.ban', $video))->assertRedirect(route('admin.videos.index'));
        $video->refresh();
        $this->assertSame(VideoStatus::BANNED, $video->status);
        $this->assertNotNull($video->banned_at);
        $this->assertSame($value, (int) $video->getAttribute(VideoBans::COLUMN), 'the ban keeps the status');

        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video))
            ->assertRedirect(route('admin.videos.index'))
            ->assertSessionHas('success', 'Video unbanned. It is back to its status from before the ban: '.VideoBans::label($status).'.');

        $video->refresh();
        $this->assertSame($status, $video->status);
        $this->assertNull($video->banned_at);
        $this->assertNull($video->getAttribute(VideoBans::COLUMN));
        $this->assertSame($publishedAt, $video->published_at?->toDateTimeString(), 'its publish date is the one it had');
    }

    public function test_a_private_video_stays_hidden_after_an_unban(): void
    {
        $video = $this->video(VideoStatus::PRIVATE);

        $this->actingAs($this->admin)->post(route('admin.videos.ban', $video));
        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video));
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
        $this->assertNotContains($video->id, array_column($this->getJson(route('videos.index', ['per_page' => 50]))->assertOk()->json('data'), 'id'));
    }

    public function test_a_video_banned_before_this_update_comes_back_private_and_the_answer_says_so(): void
    {
        // a 1.2.2 ban: no status kept
        $video = $this->video(VideoStatus::PUBLIC);
        DB::table('videos')->where('id', $video->id)->update(['status' => VideoStatus::BANNED->value, 'banned_at' => now()->subWeek(), VideoBans::COLUMN => null]);

        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video))
            ->assertSessionHas('success', 'Video unbanned. It is private now, because its status from before the ban is not known. You can make it public on its page.');

        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
        $this->assertNull($video->fresh()->banned_at);
    }

    public function test_a_public_video_without_a_publish_date_gets_one_when_it_is_unbanned(): void
    {
        // public without a date (what 1.2.2's Unban left behind), banned again: it comes back public, dated, and listed
        $video = $this->video(VideoStatus::PUBLIC, ['views' => 900]);
        DB::table('videos')->where('id', $video->id)->update(['published_at' => null]);
        $this->video(VideoStatus::PUBLIC, ['views' => 10]);
        $this->video(VideoStatus::PUBLIC, ['views' => 5]);

        $this->actingAs($this->admin)->post(route('admin.videos.ban', $video->fresh()));
        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video->fresh()));
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $video->refresh();
        $this->assertSame(VideoStatus::PUBLIC, $video->status);
        $this->assertNotNull($video->published_at);

        // Most viewed: it is the first card, and the next pages load (1.2.2: HTTP 500 for every visitor)
        $first = $this->getJson(route('videos.index', ['source' => 'most_viewed', 'per_page' => 1]))->assertOk();
        $this->assertSame([$video->id], array_column($first->json('data'), 'id'));
        $this->getJson(route('videos.index', ['source' => 'most_viewed', 'per_page' => 1, 'cursor' => $first->json('meta.next_cursor')]))->assertOk()->assertJsonCount(1, 'data');
        // Newest lists it again (a video without a date never was)
        $this->assertContains($video->id, array_column($this->getJson(route('videos.index', ['per_page' => 10]))->assertOk()->json('data'), 'id'));
    }

    public function test_unban_leaves_a_video_that_is_not_banned_as_it_is(): void
    {
        // a page opened before someone else unbanned it: 1.2.2 made the video public
        $video = $this->video(VideoStatus::PRIVATE);

        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video))
            ->assertSessionHas('success', 'This video is not banned: nothing was changed.');

        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);
    }

    public function test_the_bulk_bar_gives_each_video_its_own_status_back(): void
    {
        $private = $this->video(VideoStatus::PRIVATE);
        $unlisted = $this->video(VideoStatus::UNLISTED);
        $draft = $this->video(VideoStatus::DRAFT);
        $public = $this->video(VideoStatus::PUBLIC);
        $old = $this->video(VideoStatus::PUBLIC);
        DB::table('videos')->where('id', $old->id)->update(['status' => VideoStatus::BANNED->value, 'banned_at' => now()->subWeek(), VideoBans::COLUMN => null]);
        $ids = [$private->id, $unlisted->id, $draft->id, $public->id, $old->id];

        // one banned already keeps its ban as it was (date, no status)
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'ban', 'ids' => $ids])
            ->assertSessionHas('success', 'Banned 4 videos.');
        $this->assertNull(DB::table('videos')->where('id', $old->id)->value(VideoBans::COLUMN));
        $this->assertTrue(now()->subDays(6)->gt(DB::table('videos')->where('id', $old->id)->value('banned_at')));

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => $ids])
            ->assertSessionHas('success', 'Unbanned 5 videos. 1 video is private now, because its status from before the ban is not known.');

        $this->assertSame(
            [VideoStatus::PRIVATE, VideoStatus::UNLISTED, VideoStatus::DRAFT, VideoStatus::PUBLIC, VideoStatus::PRIVATE],
            array_map(fn (Video $video) => $video->fresh()->status, [$private, $unlisted, $draft, $public, $old]),
        );
        $this->assertSame(0, Video::query()->whereIn('id', $ids)->where(fn ($query) => $query->whereNotNull('banned_at')->orWhereNotNull(VideoBans::COLUMN))->count());

        // nothing banned in the selection: nothing to unban
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => $ids])
            ->assertSessionHas('success', 'Unbanned 0 videos.');
    }

    public function test_the_bulk_bar_of_the_new_admin_lifts_the_bans_of_a_member_banned_with_their_videos(): void
    {
        // Members > Ban with the member's videos bans them all in one click. The new admin's bulk bar (the default admin)
        // had no Unban, and its Make public no longer lifts a ban: one row menu per video was the only way back.
        config(['admin.ui' => 'v2']);
        $public = $this->video(VideoStatus::PUBLIC);
        $private = $this->video(VideoStatus::PRIVATE);
        $draft = $this->video(VideoStatus::DRAFT);
        $unban = 'form="ad-videos-bulk" name="action" value="unban"';

        // nothing is banned: the bar has no Unban
        $this->actingAs($this->admin)->get(route('admin.videos.index'))->assertOk()->assertDontSee($unban, false);

        $this->actingAs($this->admin)->post(route('admin.users.ban', $this->creator), ['ban_videos' => 1])->assertRedirect();
        $this->actingAs($this->admin)->get(route('admin.videos.index', ['status' => VideoStatus::BANNED->value]))->assertOk()->assertSee($unban, false);

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => [$public->id, $private->id, $draft->id]])
            ->assertRedirect(route('admin.videos.index'))
            ->assertSessionHas('success', 'Unbanned 3 videos.');
        $this->assertSame([VideoStatus::PUBLIC, VideoStatus::PRIVATE, VideoStatus::DRAFT], [$public->fresh()->status, $private->fresh()->status, $draft->fresh()->status]);
    }

    public function test_the_row_menu_of_the_new_admin_says_what_each_unban_gives_back(): void
    {
        // The row menu's Unban answers with a toast (resources/admin/modules/list-rows.ts), and the server's own message
        // never reached it: a video banned before 1.2.3 turned private under "The video is unbanned". Each banned row
        // carries the outcome of its Unban now, the same words the server answers.
        config(['admin.ui' => 'v2']);
        $kept = $this->video(VideoStatus::UNLISTED);
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $kept))->assertRedirect();
        $old = $this->video(VideoStatus::PUBLIC);
        DB::table('videos')->where('id', $old->id)->update(['status' => VideoStatus::BANNED->value, 'banned_at' => now()->subWeek()]);

        $html = $this->actingAs($this->admin)->get(route('admin.videos.index', ['status' => VideoStatus::BANNED->value]))->assertOk()->getContent();

        // the template's Unban takes the toast of the row it opens from (its __UNBANNED__ is the row's data-unbanned)
        $this->assertMatchesRegularExpression('/<button type="submit" form="ad-video-unban"[^>]*\bdata-ad-done="__UNBANNED__"/', $html);
        $this->assertSame('Video unbanned. It is back to its status from before the ban: Unlisted.', $this->rowData($html, $kept, 'unbanned'));
        $this->assertSame('Video unbanned. It is private now, because its status from before the ban is not known. You can make it public on its page.', $this->rowData($html, $old, 'unbanned'));

        // what the server answers is what the toast says
        foreach ([$kept, $old] as $video) {
            $this->actingAs($this->admin)->post(route('admin.videos.unban', $video))->assertSessionHas('success', $this->rowData($html, $video, 'unbanned'));
        }
        $this->assertSame([VideoStatus::UNLISTED, VideoStatus::PRIVATE], [$kept->fresh()->status, $old->fresh()->status]);
    }

    public function test_a_status_kept_by_an_earlier_ban_is_not_trusted_after_an_older_version_banned_the_video_again(): void
    {
        // The update's Undo puts the files back, never the database: 1.2.2 then runs on it and neither reads nor clears the
        // kept status. Its Unban, the creator's change to private and its Ban leave the status kept by the first ban
        // behind; after the next update the Unban of 1.2.3 trusted it and made the private video public.
        $single = $this->video(VideoStatus::PUBLIC);
        $bulk = $this->video(VideoStatus::PUBLIC);
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'ban', 'ids' => [$single->id, $bulk->id]])
            ->assertSessionHas('success', 'Banned 2 videos.');
        $this->assertSame([VideoStatus::PUBLIC->value, VideoStatus::PUBLIC->value], DB::table('videos')->whereIn('id', [$single->id, $bulk->id])->orderBy('id')->pluck(VideoBans::COLUMN)->map(fn ($value) => (int) $value)->all());

        // 1.2.2's own updates (its observer never touches the column): Unban, the studio's change to private, then Ban
        $this->travel(10)->minutes();
        DB::table('videos')->whereIn('id', [$single->id, $bulk->id])->update(['status' => VideoStatus::PUBLIC->value, 'banned_at' => null, 'updated_at' => now()]);
        DB::table('videos')->whereIn('id', [$single->id, $bulk->id])->update(['status' => VideoStatus::PRIVATE->value, 'updated_at' => now()]);
        $this->travel(10)->minutes();
        DB::table('videos')->whereIn('id', [$single->id, $bulk->id])->update(['status' => VideoStatus::BANNED->value, 'banned_at' => now(), 'updated_at' => now()]);

        // updated to 1.2.3 again: the column's migration ran before and changes nothing
        (require database_path('migrations/2026_10_26_100000_add_status_before_ban_to_videos_table.php'))->up();

        $this->actingAs($this->admin)->post(route('admin.videos.unban', $single))
            ->assertSessionHas('success', 'Video unbanned. It is private now, because its status from before the ban is not known. You can make it public on its page.');
        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => [$bulk->id]])
            ->assertSessionHas('success', 'Unbanned 1 video. 1 video is private now, because its status from before the ban is not known.');
        $this->assertSame([VideoStatus::PRIVATE, VideoStatus::PRIVATE], [$single->fresh()->status, $bulk->fresh()->status]);

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $listed = array_column($this->getJson(route('videos.index', ['per_page' => 50]))->assertOk()->json('data'), 'id');
        $this->assertSame([], array_values(array_intersect([$single->id, $bulk->id], $listed)), 'neither is shown to visitors');
    }

    public function test_banning_a_banned_video_again_keeps_its_status_for_unban(): void
    {
        // a second Ban of a banned video (a page opened before the first one) leaves the ban as it is (judge R2CD-RV-03:
        // no new date, no second notice to its creator, nothing in Admin actions, as the bulk bar's Ban): the status kept
        // by the first one stays with it
        $video = $this->video(VideoStatus::UNLISTED);
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $video))->assertRedirect();
        $bannedAt = $video->fresh()->banned_at->toDateTimeString();
        $this->travel(5)->minutes();
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $video->fresh()))
            ->assertSessionHas('success', 'This video is already banned: nothing was changed.');
        $this->assertSame($bannedAt, $video->fresh()->banned_at->toDateTimeString(), 'the ban keeps its date');

        $this->actingAs($this->admin)->post(route('admin.videos.unban', $video->fresh()))
            ->assertSessionHas('success', 'Video unbanned. It is back to its status from before the ban: Unlisted.');
        $this->assertSame(VideoStatus::UNLISTED, $video->fresh()->status);
    }

    public function test_the_bulk_unban_dates_only_the_videos_it_unbans(): void
    {
        // A public video an older version made public without publishing it (no publish date) is chosen with a banned
        // one: the Unban leaves it as it is (it was not banned), it does not publish it now at the top of Newest.
        $banned = $this->video(VideoStatus::PUBLIC);
        $publishedAt = $banned->published_at->toDateTimeString();
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $banned))->assertRedirect();
        $undated = $this->video(VideoStatus::PUBLIC);
        DB::table('videos')->where('id', $undated->id)->update(['published_at' => null]);

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => [$banned->id, $undated->id]])
            ->assertSessionHas('success', 'Unbanned 1 video.');

        $this->assertSame(VideoStatus::PUBLIC, $banned->fresh()->status);
        $this->assertSame($publishedAt, $banned->fresh()->published_at?->toDateTimeString(), 'its own date');
        $this->assertNull($undated->fresh()->published_at, 'a video the Unban did not change keeps its dates');
    }

    public function test_a_member_banned_with_their_videos_gets_each_status_back_by_unban(): void
    {
        $public = $this->video(VideoStatus::PUBLIC);
        $private = $this->video(VideoStatus::PRIVATE);
        $draft = $this->video(VideoStatus::DRAFT);

        $this->actingAs($this->admin)->post(route('admin.users.ban', $this->creator), ['ban_videos' => 1])->assertRedirect();

        foreach ([[$public, VideoStatus::PUBLIC], [$private, VideoStatus::PRIVATE], [$draft, VideoStatus::DRAFT]] as [$video, $status]) {
            $video->refresh();
            $this->assertSame(VideoStatus::BANNED, $video->status);
            $this->assertSame($status->value, (int) $video->getAttribute(VideoBans::COLUMN));
        }

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'unban', 'ids' => [$public->id, $private->id, $draft->id]])
            ->assertSessionHas('success', 'Unbanned 3 videos.');
        $this->assertSame([VideoStatus::PUBLIC, VideoStatus::PRIVATE, VideoStatus::DRAFT], [$public->fresh()->status, $private->fresh()->status, $draft->fresh()->status]);
    }

    public function test_make_public_dates_the_videos_and_only_unban_lifts_a_ban(): void
    {
        $private = $this->video(VideoStatus::PRIVATE);
        $scheduled = $this->video(VideoStatus::PLANNED);
        $banned = $this->video(VideoStatus::PRIVATE);
        $this->actingAs($this->admin)->post(route('admin.videos.ban', $banned));
        $ids = [$private->id, $scheduled->id, $banned->id];

        $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => 'make_public', 'ids' => $ids])
            ->assertSessionHas('success', 'Made 2 videos public. 1 banned video was left as it is: unban it first.');

        foreach ([$private, $scheduled] as $video) {
            $video->refresh();
            $this->assertSame(VideoStatus::PUBLIC, $video->status);
            $this->assertNotNull($video->published_at);
            $this->assertTrue($video->published_at->lte(now()), 'published now, a scheduled one too');
        }
        $this->assertSame(VideoStatus::BANNED, $banned->fresh()->status);

        foreach (['make_private' => 'Made 2 videos private.', 'make_unlisted' => 'Made 2 videos unlisted.'] as $action => $line) {
            $this->actingAs($this->admin)->post(route('admin.bulk.videos'), ['action' => $action, 'ids' => $ids])
                ->assertSessionHas('success', $line.' 1 banned video was left as it is: unban it first.');
            $this->assertSame(VideoStatus::BANNED, $banned->fresh()->status);
        }
    }

    public function test_the_visibility_field_bans_and_lifts_a_ban_like_the_buttons(): void
    {
        $video = $this->video(VideoStatus::UNLISTED);

        $this->actingAs($this->admin)->put(route('admin.videos.update', $video), $this->form($video, VideoStatus::BANNED))->assertSessionHasNoErrors();
        $video->refresh();
        $this->assertSame(VideoStatus::BANNED, $video->status);
        $this->assertNotNull($video->banned_at);
        $this->assertSame(VideoStatus::UNLISTED->value, (int) $video->getAttribute(VideoBans::COLUMN));

        $this->actingAs($this->admin)->put(route('admin.videos.update', $video), $this->form($video, VideoStatus::PRIVATE))->assertSessionHasNoErrors();
        $video->refresh();
        $this->assertSame(VideoStatus::PRIVATE, $video->status);
        $this->assertNull($video->banned_at);
        $this->assertNull($video->getAttribute(VideoBans::COLUMN));
    }

    public function test_the_visibility_field_takes_a_known_status_and_scheduled_only_with_a_planned_time(): void
    {
        // 1.2.2: "Scheduled" on a video without a planned time answered HTTP 500, and Admin > Videos stayed 500 after it;
        // a number that is no status (a crafted form) was a server error too
        config(['admin.ui' => 'v1']);
        $video = $this->video(VideoStatus::PRIVATE);

        $this->actingAs($this->admin)->put(route('admin.videos.update', $video), $this->form($video, VideoStatus::PLANNED))
            ->assertSessionHasErrors(['status' => 'A scheduled video needs a planned date and time, which this page cannot set: choose another visibility.']);
        $this->actingAs($this->admin)->put(route('admin.videos.update', $video), ['status' => '9'] + $this->form($video, VideoStatus::PRIVATE))
            ->assertSessionHasErrors('status');
        $this->assertSame(VideoStatus::PRIVATE, $video->fresh()->status);

        // a video that has its planned time keeps it
        $scheduled = $this->video(VideoStatus::PLANNED);
        $this->actingAs($this->admin)->put(route('admin.videos.update', $scheduled), $this->form($scheduled, VideoStatus::PLANNED))->assertSessionHasNoErrors();

        // one an older version left without a time is neither shown nor due, and the admin lists open
        DB::table('videos')->where('id', $video->id)->update(['status' => VideoStatus::PLANNED->value]);
        $this->assertFalse($video->fresh()->is_active);
        $this->assertFalse($video->fresh()->is_planned);
        $this->actingAs($this->admin)->get(route('admin.videos.index'))->assertOk();
        config(['admin.ui' => 'v2']);
        $this->actingAs($this->admin)->get(route('admin.videos.index'))->assertOk()
            // the row menu's Unban no longer promises "public again": its toast is the row's own outcome
            ->assertSee('data-ad-done="__UNBANNED__"', false)
            ->assertDontSee('The video is public again');
    }

    public function test_a_video_that_becomes_public_or_scheduled_by_any_save_has_a_publish_date(): void
    {
        $private = $this->video(VideoStatus::PRIVATE);
        $this->assertNull($private->published_at);
        $private->update(['status' => VideoStatus::PUBLIC]);
        $this->assertNotNull($private->fresh()->published_at);

        $draft = $this->video(VideoStatus::DRAFT);
        $planned = now()->addDays(2)->startOfSecond();
        $draft->update(['status' => VideoStatus::PLANNED, 'scheduled_at' => $planned]);
        $this->assertTrue($planned->eq($draft->fresh()->published_at), 'a scheduled video is published at its planned time');

        $created = Video::factory()->for($this->creator)->for($this->category)->create(['status' => VideoStatus::PUBLIC, 'published_at' => null]);
        $this->assertNotNull($created->fresh()->published_at);

        // a video that stays private keeps no date
        $hidden = $this->video(VideoStatus::PRIVATE);
        $hidden->update(['title' => 'Still private']);
        $this->assertNull($hidden->fresh()->published_at);
    }

    /** @param array<string, mixed> $attributes */
    private function video(VideoStatus $status, array $attributes = []): Video
    {
        $published = match ($status) {
            VideoStatus::PUBLIC => now()->subDays(3)->startOfSecond(),
            VideoStatus::PLANNED => now()->addDays(3)->startOfSecond(),
            default => null,
        };

        return Video::factory()->for($this->creator)->for($this->category)->create(array_merge([
            'status' => $status,
            'published_at' => $published,
            'scheduled_at' => $status === VideoStatus::PLANNED ? $published : null,
            'uploaded_at' => now()->subDays(4),
            'language' => null,
        ], $attributes));
    }

    /** A data-* value of a video's row menu button in the new admin's list (null when the button has none). */
    private function rowData(string $html, Video $video, string $name): ?string
    {
        if (preg_match('/<button type="button" class="ad-iconbtn is-more"[^>]*\bdata-id="'.$video->id.'"[^>]*>/', $html, $button) !== 1
            || preg_match('/\bdata-'.$name.'="([^"]*)"/', $button[0], $value) !== 1) {
            return null;
        }

        return html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5);
    }

    /** @return array<string, mixed> the admin's video form (Content > All videos > one video) */
    private function form(Video $video, VideoStatus $status): array
    {
        return [
            'title' => $video->title,
            'description' => (string) $video->description,
            'category_id' => $video->category_id,
            'status' => (string) $status->value,
            'language' => '',
        ];
    }
}
