<?php

namespace Tests\Feature;

use App\Enums\CustomPlaylistType;
use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Playlists\PlaylistManager;
use App\Support\LinkProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 R2R-06 (decided: the safest default): another member's UNLISTED video could be saved by its NUMBER - Save to
 * playlist (the watch page's dialog and the apps), Watch later, the report of the video or of a comment under it - and the
 * member's own playlist, Watch later or Reports page then showed its link: a member who tried numbers collected the links
 * of unlisted videos. The same for Save of another member's unlisted playlist (the Library showed its link). Now that
 * needs proof that the member has the link (App\Support\LinkProof): the video's watch or embed page (or the playlist's page)
 * opened in this browser, its link token sent by the apps (video_uuid / playlist_uuid), or the member's own lists or
 * history showing it already. Without proof the answer is the one of a missing id (status, body and the same queries).
 * The member's own unlisted videos, public ones, an administrator and the rows saved before are unchanged.
 */
class UnlistedLinkProofTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array', 'site.captcha_provider' => 'none']);
        Cache::flush();
        $this->owner = User::factory()->create(['email_verified_at' => now()]);
        $this->member = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_without_proof_of_its_link_every_save_answers_like_a_missing_id(): void
    {
        $unlisted = $this->video(VideoStatus::UNLISTED);
        $comment = $this->comment($unlisted, $this->owner);
        $list = $this->playlistOf($this->member);
        $missingVideo = (int) Video::withTrashed()->max('id') + 1000;
        $missingComment = (int) Comment::withTrashed()->max('id') + 1000;

        $pairs = [
            'Save to playlist (the dialog)' => fn (int $id) => $this->stateful('POST', '/api/playlists/save', ['video_id' => $id, 'playlist_id' => $list->id]),
            'Save to playlist (the apps)' => fn (int $id) => $this->app_('POST', '/api/playlists/save', ['video_id' => $id, 'playlist_id' => $list->id]),
            'Watch later' => fn (int $id) => $this->web('POST', '/user/playlists/watch-later/'.$id, ['state' => 'on']),
            'Watch later (the old toggle)' => fn (int $id) => $this->web('POST', '/user/playlists/watch-later/'.$id),
            'report the video' => fn (int $id) => $this->stateful('POST', '/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Video::class, 'id' => $id]),
        ];
        foreach ($pairs as $path => $send) {
            $hidden = $send($unlisted->id);
            $missing = $send($missingVideo);
            $this->assertNotSame(2, intdiv($hidden->getStatusCode(), 100), $path.': refused');
            $this->assertSame($missing->getStatusCode(), $hidden->getStatusCode(), $path.': status');
            $this->assertSame($this->body($missing, $missingVideo), $this->body($hidden, $unlisted->id), $path.': body');
        }

        $hidden = $this->stateful('POST', '/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Comment::class, 'id' => $comment->id]);
        $missing = $this->stateful('POST', '/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Comment::class, 'id' => $missingComment]);
        $this->assertSame(422, $hidden->getStatusCode(), 'the report of a comment under it');
        $this->assertSame($missing->json(), $hidden->json());

        // the studio editor's add never took another member's unlisted video (it adds what is out for everybody)
        $this->actingAs($this->member)->from('/profile/playlists/'.$list->id.'/edit')
            ->put('/profile/playlists/'.$list->id, ['title' => 'Mine', 'status' => PlaylistStatus::PRIVATE->value, 'sort' => 0, 'videos' => [$unlisted->id]])
            ->assertSessionHasErrors(['videos.0' => __('One of the videos is not available any more. Remove it and save again.')]);

        // nothing saved, nothing reported: the link is on none of the member's pages
        $this->assertSame(0, DB::table('playlist_has_videos')->where('video_id', $unlisted->id)->count());
        $this->assertSame(0, DB::table('reports')->count());
        $this->guestAgain();
        foreach (['/playlist/'.$list->uuid, '/playlist/watch-later', '/profile/reports'] as $page) {
            $this->assertStringNotContainsString((string) $unlisted->uuid, (string) $this->actingAs($this->member)->get($page)->getContent(), $page);
        }
    }

    public function test_save_takes_the_same_queries_for_an_unlisted_video_without_proof_and_a_missing_one(): void
    {
        $unlisted = $this->video(VideoStatus::UNLISTED);
        $list = $this->playlistOf($this->member);
        $missing = (int) Video::withTrashed()->max('id') + 1000;
        $this->savingCountingQueries($list, $missing + 1); // the first request reads what is kept for the next ones

        [$hiddenAnswer, $hiddenQueries] = $this->savingCountingQueries($list, $unlisted->id);
        [$missingAnswer, $missingQueries] = $this->savingCountingQueries($list, $missing);

        $this->assertSame(422, $hiddenAnswer->getStatusCode());
        $this->assertSame($missingAnswer->json(), $hiddenAnswer->json());
        $this->assertSame($missingQueries, $hiddenQueries, 'the same queries');
    }

    public function test_the_watch_page_and_the_embed_page_give_this_browser_the_proof(): void
    {
        $watched = $this->video(VideoStatus::UNLISTED);
        $embedded = $this->video(VideoStatus::UNLISTED);
        $list = $this->playlistOf($this->member);

        // opened by its link (also before signing in: the session keeps it)
        $this->get(parse_url($watched->route, PHP_URL_PATH))->assertOk();
        $this->get(route('video.embed', $embedded, false))->assertOk();
        $this->assertSame([$watched->id, $embedded->id], session('link_proof.videos'));

        $this->stateful('POST', '/api/playlists/save', ['video_id' => $watched->id, 'playlist_id' => $list->id], keepSession: true)->assertCreated();
        $this->web('POST', '/user/playlists/watch-later/'.$embedded->id, ['state' => 'on'], keepSession: true)->assertOk()->assertJsonPath('status', 'added');
        $this->stateful('POST', '/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Video::class, 'id' => $watched->id], keepSession: true)->assertNoContent();
        $this->assertSame(1, DB::table('playlist_has_videos')->where('playlist_id', $list->id)->where('video_id', $watched->id)->count());

        // the member's own video and public videos never needed it; the session keeps the newest KEEP links
        $this->assertTrue(LinkProof::allowsVideo($this->video(VideoStatus::PUBLIC), $this->member));
        $own = Video::factory()->for($this->member)->withStatus(VideoStatus::UNLISTED)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $this->assertTrue(LinkProof::allowsVideo($own, $this->member));
        session(['link_proof.videos' => range(1, LinkProof::KEEP)]);
        $this->get(parse_url($watched->route, PHP_URL_PATH))->assertOk();
        $this->assertCount(LinkProof::KEEP, session('link_proof.videos'));
        $this->assertSame($watched->id, last(session('link_proof.videos')));
    }

    public function test_the_apps_send_the_link_and_the_members_own_lists_and_history_are_proof(): void
    {
        $unlisted = $this->video(VideoStatus::UNLISTED);
        $savedBefore = $this->video(VideoStatus::UNLISTED);
        $watchedBefore = $this->video(VideoStatus::UNLISTED);
        $list = $this->playlistOf($this->member);
        $old = $this->playlistOf($this->member);
        DB::table('playlist_has_videos')->insert(['playlist_id' => $old->id, 'video_id' => $savedBefore->id, 'position' => 1, 'added_at' => now()]);
        DB::table('views')->insert(['video_id' => $watchedBefore->id, 'user_id' => $this->member->id, 'ip' => '127.0.0.1', 'view_at' => now()]);

        // the apps: the video's link token with the number (a wrong one is no proof)
        $this->app_('POST', '/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $list->id, 'video_uuid' => (string) Str::uuid()])->assertStatus(422);
        $this->app_('POST', '/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $list->id, 'video_uuid' => $unlisted->uuid])->assertCreated();
        $this->app_('POST', '/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $list->id, 'video_uuid' => ['x']])->assertCreated(); // the toggle takes it out again
        $this->assertSame(0, DB::table('playlist_has_videos')->where('playlist_id', $list->id)->count());

        // the apps' Watch later is the member's Watch later playlist (listed by /api/me/playlists) through the same
        // address; the site's own Watch later button and Save of a playlist are browser routes (session and page token:
        // an app's token is refused there), whose proof is the browser's session (SV-RV-02)
        $this->actingAs($this->member);
        $watchLater = PlaylistManager::get(CustomPlaylistType::WATCH_LATER)->getPlaylist();
        $this->app_('POST', '/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $watchLater->id])->assertStatus(422);
        $this->app_('POST', '/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $watchLater->id, 'video_uuid' => $unlisted->uuid])->assertCreated();
        $this->assertTrue(DB::table('playlist_has_videos')->where('playlist_id', $watchLater->id)->where('video_id', $unlisted->id)->exists());
        $this->app_('POST', '/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $watchLater->id])->assertCreated(); // out again
        $this->assertFalse(DB::table('playlist_has_videos')->where('playlist_id', $watchLater->id)->where('video_id', $unlisted->id)->exists());
        $this->assertContains('api', Route::getRoutes()->getByName('playlists.save')->gatherMiddleware());
        foreach (['custom-playlist.watch-later.toggle', 'user.playlists.favorite'] as $browserRoute) {
            $this->assertContains('web', Route::getRoutes()->getByName($browserRoute)->gatherMiddleware(), $browserRoute);
        }

        // in one of the member's playlists already (saved before the update), or in the member's history
        $this->app_('POST', '/api/playlists/save', ['video_id' => $savedBefore->id, 'playlist_id' => $list->id])->assertCreated();
        $this->web('POST', '/user/playlists/watch-later/'.$watchedBefore->id, ['state' => 'on'])->assertOk();

        // what is in a list can always be taken out again
        $this->web('POST', '/user/playlists/watch-later/'.$watchedBefore->id, ['state' => 'off'])->assertOk()->assertJsonPath('status', 'removed');
        $this->app_('POST', '/api/playlists/save', ['video_id' => $savedBefore->id, 'playlist_id' => $old->id])->assertCreated(); // out
        $this->assertSame(0, DB::table('playlist_has_videos')->where('playlist_id', $old->id)->count());

        // an administrator sees every video
        $admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->assertTrue(LinkProof::allowsVideo($unlisted, $admin));
    }

    public function test_another_members_unlisted_playlist_is_saved_only_with_proof_of_its_link(): void
    {
        $unlisted = Playlist::factory()->create(['user_id' => $this->owner->id, 'status' => PlaylistStatus::UNLISTED, 'is_deletable' => true]);
        $public = Playlist::factory()->create(['user_id' => $this->owner->id, 'status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);
        $missing = (int) Playlist::query()->max('id') + 1000;

        // by its number only: missing, as an id that does not exist
        $hidden = $this->web('POST', '/profile/playlists/'.$unlisted->id.'/favorite', [], json: true);
        $absent = $this->web('POST', '/profile/playlists/'.$missing.'/favorite', [], json: true);
        $this->assertSame(404, $hidden->getStatusCode());
        $this->assertSame($this->body($absent, $missing), $this->body($hidden, $unlisted->id));
        $this->assertSame(0, DB::table('favorites_playlist')->count());

        // a public playlist as before; the unlisted one after its page was opened by its link, or with its link token
        $this->web('POST', '/profile/playlists/'.$public->id.'/favorite', [], json: true)->assertNoContent();
        $this->actingAs($this->member)->get('/playlist/'.$unlisted->uuid)->assertOk();
        $this->web('POST', '/profile/playlists/'.$unlisted->id.'/favorite', [], json: true, keepSession: true)->assertNoContent();
        $this->assertSame(2, DB::table('favorites_playlist')->where('user_id', $this->member->id)->count());
        // saved already: taking it out stays open in a new browser
        $this->web('POST', '/profile/playlists/'.$unlisted->id.'/favorite', [], json: true)->assertNoContent();
        $this->web('POST', '/profile/playlists/'.$unlisted->id.'/favorite', ['playlist_uuid' => $unlisted->uuid], json: true)->assertNoContent();
        $this->assertSame(2, DB::table('favorites_playlist')->where('user_id', $this->member->id)->count());
    }

    private function savingCountingQueries(Playlist $list, int $id): array
    {
        $this->guestAgain();
        Cache::store('array')->flush();
        $this->actingAs($this->member, 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->postJson('/api/playlists/save', ['video_id' => $id, 'playlist_id' => $list->id]);
        $queries = array_map(fn (array $q) => preg_replace('/\d+/', 'N', $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queries];
    }

    /** The site's own script on one of its pages: the session's member, the page's address as Referer (Sanctum). */
    private function stateful(string $method, string $url, array $data, bool $keepSession = false): TestResponse
    {
        $keepSession || $this->guestAgain();
        Cache::store('array')->flush();

        return $this->actingAs($this->member)->withHeaders(['Referer' => rtrim((string) config('app.url'), '/').'/'])->json($method, $url, $data);
    }

    /** The apps: the member's token, no session. */
    private function app_(string $method, string $url, array $data): TestResponse
    {
        $this->guestAgain();
        Cache::store('array')->flush();

        return $this->actingAs($this->member, 'sanctum')->json($method, $url, $data);
    }

    private function web(string $method, string $url, array $data = [], bool $keepSession = false, bool $json = true): TestResponse
    {
        $keepSession || $this->guestAgain();
        Cache::store('array')->flush();

        return $this->actingAs($this->member)->json($method, $url, $data);
    }

    private function guestAgain(): void
    {
        $this->app['auth']->forgetGuards();
        config(['auth.defaults.guard' => 'web']);
        $this->flushSession();
        $this->flushHeaders();
    }

    private function body(TestResponse $response, int $id): string
    {
        return (string) preg_replace('#(/|%2F|\\\\/)'.$id.'(?=[/?"&%\s\\\\<]|$)#', '$1<ID>', (string) $response->getContent());
    }

    private function video(VideoStatus $status): Video
    {
        return Video::factory()->for($this->owner)->withStatus($status)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay(), 'allow_comments' => true]);
    }

    private function comment(Video $video, User $author): Comment
    {
        return Comment::withoutEvents(fn () => Comment::factory()->create(['video_id' => $video->id, 'user_id' => $author->id]));
    }

    private function playlistOf(User $member): Playlist
    {
        return Playlist::factory()->create(['user_id' => $member->id, 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => true, 'title' => 'List '.Str::random(6)]);
    }
}
