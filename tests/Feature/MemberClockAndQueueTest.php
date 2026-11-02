<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Services\ViewService;
use App\Support\WatchHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fixes in files outside the member pages (1.2.3): the clock of a history row and of a
 * saved playlist, "Pause history" when a view is recorded, and the watch page's queue of the viewer's Liked videos.
 * Each test fails on the files as they were and passes with the fixes.
 */
class MemberClockAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['app.timezone' => 'Europe/Paris', 'site.view_cooldown_minutes' => 0]);
        date_default_timezone_set('Europe/Paris');
        $this->member = User::factory()->create();
        $this->channel = User::factory()->create(['username' => 'clock_channel']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_a_recorded_view_carries_the_applications_clock_and_lands_on_the_sites_day(): void
    {
        // a Sunday evening on the site's clock; the database's own default would write its server's (or UTC) time. The
        // clock is set before the video is made: a video published a real day before the run was "not yet published"
        // at this time from the evening of 26 October 2026 on, and History left it out
        Carbon::setTestNow(Carbon::create(2026, 10, 25, 20, 46, 0, 'Europe/Paris'));
        $video = $this->video();

        $this->actingAs($this->member);
        app(ViewService::class)->incrementView($video);

        $stored = (string) DB::table('views')->where('user_id', $this->member->id)->value('view_at');
        $this->assertSame('2026-10-25 20:46:00', substr($stored, 0, 19));

        $days = WatchHistory::days(WatchHistory::page($this->member, null, WatchHistory::FIRST_PAGE)->items());
        $this->assertSame('2026-10-25', $days->first()['date']);
        $this->assertSame('Today', $days->first()['label']);
    }

    public function test_a_member_who_paused_their_history_gets_no_row_and_the_video_still_counts_the_view(): void
    {
        $video = $this->video();
        DB::table('users')->where('id', $this->member->id)->update(['history_paused_at' => now()]);

        $this->actingAs($this->member->fresh());
        app(ViewService::class)->incrementView($video);

        $this->assertSame(0, DB::table('views')->where('user_id', $this->member->id)->count());
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('views'));

        // turned back on: the next view is in the history again
        DB::table('users')->where('id', $this->member->id)->update(['history_paused_at' => null]);
        $this->actingAs($this->member->fresh());
        app(ViewService::class)->incrementView($video);
        $this->assertSame(1, DB::table('views')->where('user_id', $this->member->id)->count());
    }

    public function test_a_saved_playlist_keeps_the_applications_clock(): void
    {
        $video = $this->video();
        $playlist = Playlist::factory()->for($this->channel)->create(['title' => 'Clock list', 'status' => PlaylistStatus::PUBLIC]);
        $playlist->videos()->attach($video->id, ['position' => 1]);
        Carbon::setTestNow(Carbon::create(2026, 10, 25, 20, 50, 0, 'Europe/Paris'));

        $this->actingAs($this->member)->postJson(route('user.playlists.favorite', $playlist))->assertNoContent();

        $stored = (string) DB::table('favorites_playlist')->where('user_id', $this->member->id)->value('added_at');
        $this->assertSame('2026-10-25 20:50:00', substr($stored, 0, 19));
    }

    public function test_play_all_of_liked_videos_plays_the_likes_in_their_order(): void
    {
        $liked = Playlist::factory()->for($this->member)->create(['title' => LikedVideosPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        $videos = [$this->video('First liked'), $this->video('Second liked'), $this->video('Third liked')];
        foreach ($videos as $n => $video) {
            DB::table('interactions')->insert(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()->subMinutes($n)]);
        }

        // the list has no rows of its own (likes are interactions): its queue was empty, so Play all played one video
        $this->actingAs($this->member)->get($videos[0]->routeWithParams(['list' => $liked->uuid]))
            ->assertOk()
            ->assertViewHas('playlistVideos', fn ($queue) => $queue !== null && $queue->pluck('id')->all() === array_map(fn (Video $video) => $video->id, $videos))
            ->assertViewHas('currentIndex', 0);

        // another member's Liked videos is never a queue of the viewer's likes
        $this->actingAs(User::factory()->create())->get($videos[1]->routeWithParams(['list' => $liked->uuid]))
            ->assertOk()
            ->assertViewHas('playlistVideos', fn ($queue) => $queue === null || $queue->isEmpty());
    }

    private function video(string $title = 'Clock clip'): Video
    {
        return Video::factory()->for($this->channel)->withStatus(VideoStatus::PUBLIC)->create([
            'title' => $title,
            'views' => 0,
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
        ]);
    }
}
