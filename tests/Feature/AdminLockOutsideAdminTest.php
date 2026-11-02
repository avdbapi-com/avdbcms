<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\AppearanceDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * R1-089 (round 1 judge): with the admin IP lock set (Settings > Security), an administrator outside the list got 403 on
 * /admin and 404 on another member's private playlist page, but /video/...?list=<that private playlist> still showed its
 * title, owner, count and videos: the watch page (ShowVideoAction), the playlist API resource, the appearance draft
 * preview and the maintenance bypass checked the raw is_admin. They now use AdminIpLock::adminPowersHere() like every
 * other administrator power outside the admin: from an address not on the list an administrator is an ordinary member.
 */
class AdminLockOutsideAdminTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_IP = '203.0.113.7';

    private const ELSEWHERE = '198.51.100.1';

    private const TITLE = 'Members private mixtape R1-089';

    public function test_the_watch_page_does_not_open_a_members_private_playlist_for_an_administrator_outside_the_lock(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP]);
        [$member, $video, $playlist, $privateVideo] = $this->privatePlaylistWithVideos();
        $admin = User::factory()->admin()->create();
        $url = $video->route.'?list='.$playlist->uuid;

        // Outside the lock: the watch page opens (the video is public) without the playlist, like for anybody else.
        $outside = $this->as($admin, self::ELSEWHERE)->get($url)->assertOk();
        $outside->assertDontSee(self::TITLE)->assertDontSee('avd-playlist-panel', false)->assertDontSee($privateVideo->title);

        // From an address on the list the administrator keeps the power, as before.
        $this->as($admin, self::OWNER_IP)->get($url)->assertOk()->assertSee(self::TITLE)->assertSee('avd-playlist-panel', false);

        // The owner sees it from anywhere; a guest, another member and the view-only demo administrator never do.
        $this->as($member, self::ELSEWHERE)->get($url)->assertOk()->assertSee(self::TITLE);
        $this->fresh();
        $this->withServerVariables(['REMOTE_ADDR' => self::ELSEWHERE])->get($url)->assertOk()->assertDontSee(self::TITLE);
        $this->as(User::factory()->create(), self::ELSEWHERE)->get($url)->assertOk()->assertDontSee(self::TITLE);
        $demo = User::factory()->admin()->create();
        $demo->forceFill(['is_view_only' => true])->save();
        $this->as($demo, self::ELSEWHERE)->get($url)->assertOk()->assertDontSee(self::TITLE);
    }

    public function test_the_playlist_api_shows_only_public_videos_to_an_administrator_outside_the_lock(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP]);
        [$member, $video, $playlist, $privateVideo] = $this->privatePlaylistWithVideos();
        $playlist->forceFill(['status' => PlaylistStatus::PUBLIC])->save();
        $admin = User::factory()->admin()->create();

        $outside = $this->as($admin, self::ELSEWHERE)->getJson(route('playlists.show', $playlist))->assertOk();
        $this->assertSame(1, $outside->json('videos_count'), 'outside the lock: only the public video counts');
        $this->assertSame([$video->uuid], array_column($outside->json('videos'), 'uuid'));

        $inside = $this->as($admin, self::OWNER_IP)->getJson(route('playlists.show', $playlist))->assertOk();
        $this->assertSame(2, $inside->json('videos_count'));
    }

    public function test_an_appearance_draft_link_is_not_applied_for_an_administrator_outside_the_lock(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP]);
        $admin = User::factory()->admin()->create();
        $values = ['home_intro_title' => 'Draft R1-089'];
        AppearanceDraft::put($admin->id, ['values' => $values, 'action' => null]);
        $url = '/?'.AppearanceDraft::QUERY.'='.urlencode(AppearanceDraft::sign($admin->id, $values));

        $this->as($admin, self::ELSEWHERE)->get($url)->assertOk()->assertDontSee('id="avd-draft-banner"', false);
        $this->assertNotSame('Draft R1-089', config('site.home_intro_title'));

        $this->as($admin, self::OWNER_IP)->get($url)->assertOk()->assertSee('id="avd-draft-banner"', false);
        $this->assertSame('Draft R1-089', config('site.home_intro_title'));
    }

    public function test_maintenance_mode_lets_an_administrator_through_only_from_an_address_on_the_list(): void
    {
        config(['site.admin_ip_whitelist' => self::OWNER_IP, 'site.maintenance_mode' => true, 'site.maintenance_allow_admins' => true]);
        $admin = User::factory()->admin()->create();

        $this->as($admin, self::ELSEWHERE)->get('/')->assertStatus(503);
        $this->as($admin, self::OWNER_IP)->get('/')->assertOk();

        // Without a list the administrator passes from anywhere, as before.
        config(['site.admin_ip_whitelist' => '']);
        $this->as($admin, self::ELSEWHERE)->get('/')->assertOk();
    }

    /**
     * Found while proving R1-089: when the next video of the list is one the viewer may not watch and the page has no
     * suggested video, "Up next" handed null to NextVideoDTO::fromSuggested(): the watch page answered 500.
     */
    public function test_a_list_whose_next_video_the_viewer_may_not_watch_and_no_suggestion_gives_no_up_next(): void
    {
        [$member, $video, $playlist, $privateVideo] = $this->privatePlaylistWithVideos();
        $viewer = User::factory()->create();
        $this->actingAs($viewer);
        $playlist->setAttribute('videos_count', 2);

        $next = app(\App\Services\VideoService::class)->getNextVideo(
            new \Illuminate\Database\Eloquent\Collection(),
            $playlist,
            new \Illuminate\Database\Eloquent\Collection([$video, $privateVideo->load('user')]),
            0
        );

        $this->assertNull($next);
    }

    /** @return array{0: User, 1: Video, 2: Playlist, 3: Video} the owner, a public video, their private playlist, a private video in it */
    private function privatePlaylistWithVideos(): array
    {
        $member = User::factory()->create();
        $video = Video::factory()->for($member)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay()]);
        $privateVideo = Video::factory()->for($member)->withStatus(VideoStatus::PRIVATE)->create(['title' => 'Hidden take R1-089']);
        $playlist = Playlist::factory()->for($member)->create(['status' => PlaylistStatus::PRIVATE, 'title' => self::TITLE]);
        $playlist->videos()->attach($video->id, ['position' => 1, 'added_at' => now()]);
        $playlist->videos()->attach($privateVideo->id, ['position' => 2, 'added_at' => now()]);

        return [$member, $video, $playlist, $privateVideo];
    }

    private function as(User $user, string $ip): static
    {
        $this->fresh();

        return $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function fresh(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
    }
}
