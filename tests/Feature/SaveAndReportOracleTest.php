<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 1 review of lane FX, the neighbours of R1-168 (Watch later): two more member requests told which hidden video
 * ids exist.
 *  - R1FX-REV-5, Save to playlist (POST /api/playlists/save): a deleted video answered 404 with another text where a
 *    missing, private or not yet published one answered 422. Now a deleted one gets the missing id's answer too.
 *  - R1FX-REV-6, Report (POST /api/report): another member's private video answered 403 "You are not authorized to
 *    report this video" (a deleted one 404) against 422 "This id is not found in ours records" for a missing id. Now
 *    an id the member may not see (a deleted one, a video the member may not watch, a comment under such a video) gets
 *    exactly the answer of a missing id, in the member's language.
 * Guests and the view-only demo account learn nothing either; what a member may see works as before.
 */
class SaveAndReportOracleTest extends TestCase
{
    use RefreshDatabase;

    // Save to playlist (R1FX-REV-5)

    public function test_save_to_playlist_answers_a_deleted_video_like_a_missing_one(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $playlist = $this->playlistOf($member);
        $hidden = [
            'deleted public video' => $this->publicVideo($owner, deleted: true),
            'private video' => Video::factory()->for($owner)->withStatus(VideoStatus::PRIVATE)->create(),
            'not yet published' => Video::factory()->for($owner)->withStatus(VideoStatus::PLANNED)->create(['scheduled_at' => now()->addDay()]),
            'own deleted video' => $this->publicVideo($member, deleted: true),
        ];

        foreach ($hidden as $kind => $video) {
            $this->assertLikeAMissingVideo(fn (int $id) => $this->save($member, $id, $playlist->id), $video->id, 'save: '.$kind);
        }

        $this->assertSame(0, $playlist->videos()->count(), 'nothing was saved');
        $missing = $this->save($member, $this->missingVideoId(), $playlist->id)->assertUnprocessable();
        $this->assertSame(__('This video can no longer be saved.'), $missing->json('message'));
    }

    public function test_save_to_playlist_still_saves_and_removes_what_the_member_may_watch(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlistOf($member);
        $public = $this->publicVideo(User::factory()->create());
        $own = Video::factory()->for($member)->withStatus(VideoStatus::PRIVATE)->create();

        $this->save($member, $public->id, $playlist->id)->assertCreated();
        $this->save($member, $own->id, $playlist->id)->assertCreated();
        $this->assertSame(2, $playlist->videos()->count());
        $this->save($member, $public->id, $playlist->id)->assertCreated();
        $this->assertSame(1, $playlist->videos()->count(), 'the second Save takes it out again');

        // Another member's playlist is answered like a playlist that does not exist.
        $foreign = $this->playlistOf(User::factory()->create());
        $answer = $this->save($member, $public->id, $foreign->id)->assertUnprocessable();
        $this->assertSame(__('This playlist no longer exists.'), $answer->json('message'));
        $this->assertSame($answer->getContent(), $this->save($member, $public->id, Playlist::query()->max('id') + 1000)->getContent());
    }

    public function test_save_to_playlist_tells_a_guest_and_the_view_only_demo_account_nothing(): void
    {
        $owner = User::factory()->create();
        $deleted = $this->publicVideo($owner, deleted: true);
        $missing = $this->missingVideoId();

        $this->fresh();
        $this->postJson('/api/playlists/save', ['video_id' => $deleted->id, 'playlist_id' => 1])->assertUnauthorized();
        $this->fresh();
        $this->postJson('/api/playlists/save', ['video_id' => $missing, 'playlist_id' => 1])->assertUnauthorized();

        $demo = $this->viewOnlyDemo();
        $playlist = $this->playlistOf($demo);
        $this->assertLikeAMissingVideo(fn (int $id) => $this->save($demo, $id, $playlist->id), $deleted->id, 'save: view-only demo');
    }

    // Report (R1FX-REV-6)

    public function test_report_answers_every_video_the_member_may_not_see_like_a_missing_one(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $hidden = [
            'private' => Video::factory()->for($owner)->withStatus(VideoStatus::PRIVATE)->create(),
            'draft' => Video::factory()->for($owner)->withStatus(VideoStatus::DRAFT)->create(),
            'banned' => Video::factory()->for($owner)->withStatus(VideoStatus::BANNED)->banned()->create(),
            'not yet published' => Video::factory()->for($owner)->withStatus(VideoStatus::PLANNED)->create(['scheduled_at' => now()->addDay()]),
            'deleted public video' => $this->publicVideo($owner, deleted: true),
        ];

        foreach ($hidden as $kind => $video) {
            $this->assertLikeAMissingVideo(fn (int $id) => $this->report($member, Video::class, $id), $video->id, 'report video: '.$kind);
        }

        $missing = $this->report($member, Video::class, $this->missingVideoId())->assertUnprocessable()->assertJsonValidationErrorFor('id');
        $this->assertSame(__('This video can no longer be reported.'), $missing->json('message'));
        $this->assertSame(0, Report::query()->count(), 'nothing was reported');
    }

    public function test_report_answers_comments_under_hidden_videos_and_deleted_ones_like_missing_ones(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $writer = User::factory()->create();
        $private = Video::factory()->for($owner)->withStatus(VideoStatus::PRIVATE)->create();
        $deletedVideo = $this->publicVideo($owner);
        $public = $this->publicVideo($owner);
        $hidden = [
            'comment under a private video' => Comment::factory()->createQuietly(['user_id' => $writer->id, 'video_id' => $private->id]),
            'comment under a deleted video' => Comment::factory()->createQuietly(['user_id' => $writer->id, 'video_id' => $deletedVideo->id]),
            'deleted comment' => tap(Comment::factory()->createQuietly(['user_id' => $writer->id, 'video_id' => $public->id]))->delete(),
        ];
        $deletedVideo->delete();
        $missingComment = (int) Comment::withTrashed()->max('id') + 1000;

        foreach ($hidden as $kind => $comment) {
            $response = $this->report($member, Comment::class, $comment->id);
            $reference = $this->report($member, Comment::class, $missingComment);
            $this->assertSame($reference->getStatusCode(), $response->getStatusCode(), $kind);
            $this->assertSame($reference->getContent(), $response->getContent(), $kind.': the answer of a missing comment');
        }

        $this->assertSame(422, $reference->getStatusCode());
        $this->assertSame(__('This comment can no longer be reported.'), $reference->json('message'));
        $this->assertSame(0, Report::query()->count());

        // A comment under a video the member may watch can be reported, as before.
        $visible = Comment::factory()->createQuietly(['user_id' => $writer->id, 'video_id' => $public->id]);
        $this->report($member, Comment::class, $visible->id)->assertNoContent();
    }

    public function test_report_answers_a_deleted_channel_like_a_missing_one(): void
    {
        $member = User::factory()->create();
        $deleted = tap(User::factory()->create())->delete();
        $missing = (int) User::withTrashed()->max('id') + 1000;

        $response = $this->report($member, User::class, $deleted->id);
        $reference = $this->report($member, User::class, $missing)->assertUnprocessable();
        $this->assertSame($reference->getContent(), $response->getContent());
        $this->assertSame(__('This channel can no longer be reported.'), $reference->json('message'));
    }

    public function test_report_still_works_for_what_the_member_may_see_and_keeps_its_other_refusals(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $public = $this->publicVideo($other);
        $unlisted = Video::factory()->for($other)->withStatus(VideoStatus::UNLISTED)->create();
        $own = $this->publicVideo($member);

        $this->report($member, Video::class, $public->id)->assertNoContent();
        // round 3 R2R-06: another member's unlisted video by its number needs proof of its link (here the apps' video_uuid)
        $this->report($member, Video::class, $unlisted->id)->assertStatus(422);
        $this->fresh();
        $this->actingAs($member)->postJson('/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Video::class, 'id' => $unlisted->id, 'video_uuid' => $unlisted->uuid])->assertNoContent();
        $this->report($member, Video::class, $public->id)->assertForbidden();   // already reported: the member knows it exists
        $this->report($member, Video::class, $own->id)->assertForbidden();      // the member's own video
        $this->report($member, User::class, $other->id)->assertNoContent();
        $this->assertSame(3, Report::query()->count());
    }

    public function test_report_tells_a_guest_and_the_view_only_demo_account_nothing(): void
    {
        $private = Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PRIVATE)->create();
        $missing = $this->missingVideoId();
        $payload = fn (int $id) => ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Video::class, 'id' => $id];

        $this->fresh();
        $this->postJson('/api/report', $payload($private->id))->assertUnauthorized();
        $this->fresh();
        $this->postJson('/api/report', $payload($missing))->assertUnauthorized();

        $demo = $this->viewOnlyDemo();
        $this->assertLikeAMissingVideo(fn (int $id) => $this->report($demo, Video::class, $id), $private->id, 'report: view-only demo');
        $this->assertSame(0, Report::query()->count());
    }

    public function test_the_answer_is_in_the_members_language(): void
    {
        $member = User::factory()->create();
        $private = Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PRIVATE)->create();

        foreach (['vi', 'ar', 'de'] as $locale) {
            $hidden = $this->report($member, Video::class, $private->id, $locale)->assertUnprocessable();
            $missing = $this->report($member, Video::class, $this->missingVideoId(), $locale)->assertUnprocessable();
            $this->assertSame(__('This video can no longer be reported.', [], $locale), $hidden->json('message'), $locale);
            $this->assertSame($missing->getContent(), $hidden->getContent(), $locale);
        }
    }

    public function test_lists_sent_instead_of_one_id_or_type_get_a_validation_answer_never_a_500(): void
    {
        $member = User::factory()->create();
        $playlist = $this->playlistOf($member);
        $public = $this->publicVideo(User::factory()->create());

        // Save: a list passed "exists" and broke the toggle; Report: a list as the type reached tableFor(?string).
        $this->fresh();
        $this->actingAs($member)->postJson('/api/playlists/save', ['video_id' => [$public->id], 'playlist_id' => $playlist->id])
            ->assertUnprocessable()->assertJsonValidationErrorFor('video_id');
        $this->fresh();
        $this->actingAs($member)->postJson('/api/playlists/save', ['video_id' => $public->id, 'playlist_id' => [$playlist->id]])
            ->assertUnprocessable()->assertJsonValidationErrorFor('playlist_id');
        $this->fresh();
        $this->actingAs($member)->postJson('/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => [Video::class], 'id' => $public->id])
            ->assertUnprocessable()->assertJsonValidationErrorFor('type');
        $this->fresh();
        $this->actingAs($member)->postJson('/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => Video::class, 'id' => [$public->id]])
            ->assertUnprocessable()->assertJsonValidationErrorFor('id');

        $this->assertSame(0, $playlist->videos()->count());
        $this->assertSame(0, Report::query()->count());
    }

    private function assertLikeAMissingVideo(callable $send, int $id, string $label): void
    {
        $missing = $this->missingVideoId();
        $reference = $send($missing);
        $response = $send($id);

        $this->assertSame($reference->getStatusCode(), $response->getStatusCode(), $label);
        $this->assertSame(
            str_replace((string) $missing, '{id}', (string) $reference->getContent()),
            str_replace((string) $id, '{id}', (string) $response->getContent()),
            $label.': the answer must be the one of a missing id'
        );
    }

    private function save(User $member, int $videoId, int $playlistId): TestResponse
    {
        $this->fresh();

        return $this->actingAs($member)->postJson('/api/playlists/save', ['video_id' => $videoId, 'playlist_id' => $playlistId]);
    }

    private function report(User $member, string $type, int $id, ?string $locale = null): TestResponse
    {
        $this->fresh();

        return $this->actingAs($member)
            ->withHeaders($locale ? ['X-Locale' => $locale] : [])
            ->postJson('/api/report', ['reason' => ReportReason::SEXUAL_CONTENT->value, 'type' => $type, 'id' => $id]);
    }

    private function publicVideo(User $owner, bool $deleted = false): Video
    {
        // Comments on: VideoFactory turns them off for about one video in ten (allow_comments: fake()->boolean(90)), and
        // a comment under such a video is not shown to members, so it could not be reported: the test failed at random.
        $video = Video::factory()->for($owner)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay(), 'allow_comments' => true]);

        if ($deleted) {
            $video->delete();
        }

        return $video;
    }

    private function playlistOf(User $member): Playlist
    {
        return Playlist::factory()->create(['user_id' => $member->id, 'title' => 'My list '.$member->id, 'status' => PlaylistStatus::PRIVATE]);
    }

    private function missingVideoId(): int
    {
        return (int) Video::withTrashed()->max('id') + 1000;
    }

    private function viewOnlyDemo(): User
    {
        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();

        return $demo;
    }

    /** A new request: no signed-in guard, session or rate limit counts left from the previous one. */
    private function fresh(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
        Cache::flush();
    }
}
