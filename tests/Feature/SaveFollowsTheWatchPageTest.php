<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 2a R2A-061: Save to playlist (POST /api/playlists/save: the Save dialog, apps) accepted every public or unlisted
 * status without the watch page's checks: another member's video with a date to come, or still uploading, answered 201
 * and was saved while its page answered 404; the owner's banned video was saved (its page: 410); and a video that went
 * private after it was saved could not be taken out of the list any more.
 *
 * Now Save accepts exactly what the watch page shows the account (VideoPolicy::show, the rule Watch later uses since
 * R1-168), and a video already in the chosen playlist can always be taken out. The rule is one query: an id the account
 * may not see is refused exactly like an id that does not exist (same answer, same number of queries).
 */
class SaveFollowsTheWatchPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array']);
        $this->owner = User::factory()->create();
    }

    public function test_save_accepts_what_the_watch_page_shows_and_nothing_else(): void
    {
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $videos = $this->videosInEveryState();

        foreach (['another member' => $other, 'the owner' => $this->owner, 'an administrator' => $admin] as $who => $account) {
            $playlist = $this->playlistOf($account);

            foreach ($videos as $state => $video) {
                // round 3 R2R-06: another member's unlisted video only with proof of its link (below: the apps' video_uuid)
                $shown = Gate::forUser($account)->allows('show', $video)
                    && ! ($who === 'another member' && $video->status === VideoStatus::UNLISTED);
                $response = $this->actingAs($account, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id]);

                $this->assertSame($shown ? 201 : 422, $response->getStatusCode(), $who.', '.$state.': the watch page '.($shown ? 'shows it' : 'does not'));
                $this->assertSame($shown, $playlist->videos()->whereKey($video->id)->exists(), $who.', '.$state);

                if (! $shown) {
                    $response->assertJsonPath('message', __('This video can no longer be saved.'));
                }

                $playlist->videos()->detach();
            }
        }

        // another member's unlisted video with its link (the apps send video_uuid): saved
        $unlisted = collect($videos)->first(fn (Video $video) => $video->status === VideoStatus::UNLISTED);
        $playlist = $this->playlistOf($other);
        $this->actingAs($other, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $unlisted->id, 'playlist_id' => $playlist->id, 'video_uuid' => $unlisted->uuid])->assertStatus(201);
        $playlist->videos()->detach();

        // the cases the finding names: another member's video with a date to come, or still uploading
        foreach (['future date', 'still uploading'] as $state) {
            $this->actingAs($other, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $videos[$state]->id, 'playlist_id' => $playlist->id])->assertStatus(422);
        }
        $this->assertSame(0, $playlist->videos()->count());
    }

    public function test_an_imported_video_follows_the_watch_page_of_the_language(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlistOf($member);
        $video = Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create(['avdb_id' => 991234, 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        // no French text: since 1.2.3 (the decision of 29 Oct) the French watch page shows it too, as the French lists do
        // (with the video's own texts, not indexed there), so it is saved in French as in English
        app()->setLocale('fr');
        $this->assertTrue(Gate::forUser($member)->allows('show', $video), 'the French watch page shows it');
        app()->setLocale('en');
        $this->actingAs($member, 'sanctum')->withHeaders(['X-Locale' => 'fr'])->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id])->assertStatus(201);
        $playlist->videos()->detach();
        $this->actingAs($member, 'sanctum')->withHeaders(['X-Locale' => 'en'])->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id])->assertStatus(201);
        $playlist->videos()->detach();

        DB::table('video_translations')->insert(['video_id' => $video->id, 'locale' => 'fr', 'title' => 'Titre', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($member, 'sanctum')->withHeaders(['X-Locale' => 'fr'])->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id])->assertStatus(201);
    }

    public function test_a_video_that_went_private_can_still_be_taken_out_of_the_list(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlistOf($member);
        $video = Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);

        $this->actingAs($member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id])->assertStatus(201);
        $video->update(['status' => VideoStatus::PRIVATE->value]);

        // the toggle takes it out
        $this->actingAs($member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id])->assertStatus(201);
        $this->assertFalse($playlist->videos()->whereKey($video->id)->exists());

        // ... and does not put it back
        $this->actingAs($member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $playlist->id])->assertStatus(422);
        $this->assertFalse($playlist->videos()->whereKey($video->id)->exists());

        // another member's list holding it says nothing to this member: his own playlist is the only one that counts
        $someone = User::factory()->create();
        $theirs = $this->playlistOf($someone);
        $theirs->videos()->attach($video->id, ['position' => 1]);
        $this->actingAs($member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $video->id, 'playlist_id' => $theirs->id])->assertStatus(422)
            ->assertJsonPath('message', __('This video can no longer be saved.'));
        $this->assertTrue($theirs->videos()->whereKey($video->id)->exists());
    }

    public function test_a_hidden_id_is_answered_like_a_missing_one_with_the_same_work(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlistOf($member);
        $hidden = Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->addDays(30), 'uploaded_at' => now()->subDay()]);
        $missing = (int) Video::withTrashed()->max('id') + 1000;
        // the first request of the test also reads the blocked addresses once (kept for the next ones)
        $this->saveCountingQueries($member, $missing + 1, $playlist->id);

        [$hiddenAnswer, $hiddenQueries] = $this->saveCountingQueries($member, $hidden->id, $playlist->id);
        [$missingAnswer, $missingQueries] = $this->saveCountingQueries($member, $missing, $playlist->id);

        $this->assertSame(422, $hiddenAnswer->getStatusCode());
        $this->assertSame($missingAnswer->getStatusCode(), $hiddenAnswer->getStatusCode());
        $this->assertSame($missingAnswer->json(), $hiddenAnswer->json());
        $this->assertSame($missingQueries, $hiddenQueries, 'the same queries for a hidden and a missing id');
    }

    /** @return array<string, Video> */
    private function videosInEveryState(): array
    {
        $out = fn (array $extra = []) => array_merge(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()], $extra);
        $deletedOwner = User::factory()->create();

        $videos = [
            'public' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out()),
            'unlisted' => Video::factory()->for($this->owner)->withStatus(VideoStatus::UNLISTED)->create($out()),
            'planned, date reached' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PLANNED)->create($out(['scheduled_at' => now()->subHour()])),
            'planned, date to come' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PLANNED)->create($out(['scheduled_at' => now()->addDays(3)])),
            'future date' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out(['published_at' => now()->addDays(30)])),
            'still uploading' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out(['uploaded_at' => null])),
            'private' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PRIVATE)->create($out()),
            'draft' => Video::factory()->for($this->owner)->withStatus(VideoStatus::DRAFT)->create($out()),
            'failed' => Video::factory()->for($this->owner)->withStatus(VideoStatus::FAILED)->create($out()),
            'banned' => Video::factory()->for($this->owner)->withStatus(VideoStatus::BANNED)->banned()->create($out()),
            'channel deleted' => Video::factory()->for($deletedOwner)->withStatus(VideoStatus::PUBLIC)->create($out()),
        ];
        $deletedOwner->delete();
        $deleted = Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out());
        $deleted->delete();

        return array_map(fn (Video $video) => $video->fresh() ?? $video, $videos);
    }

    private function playlistOf(User $user): Playlist
    {
        return Playlist::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Mine '.Str::random(6),
            'user_id' => $user->id,
            'status' => 1,
            'sort' => 5,
        ]);
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: list<string>} */
    private function saveCountingQueries(User $member, int $videoId, int $playlistId): array
    {
        $this->actingAs($member, 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->postJson('/api/playlists/save', ['video_id' => $videoId, 'playlist_id' => $playlistId]);
        $queries = array_map(fn (array $q) => preg_replace('/\d+/', 'N', $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queries];
    }
}
