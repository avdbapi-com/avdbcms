<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 2 review R2CX-REV-02 (the rest of round 2a R2A-062): like and dislike (POST /api/like and /api/dislike, the siblings
 * of R1-090's route, the id in the body) still told whether a video id exists: a missing id answered 422, another member's
 * private video, draft, video planned for later, still uploading, failed or banned 403 "You are not authorized to
 * interact with this video", a deleted one 404. A comment under such a video, under a video with comments turned off,
 * or under a comment a moderator hid was even liked (204).
 *
 * Now only what the site shows the member can be liked (the watch page's rule for a video, the comment list's for a
 * comment: App\Support\ShownToMember); every other id is refused exactly like one that does not exist, with the same
 * status, body and headers and the same queries. What the site shows is liked as before.
 *
 * Report reads a comment with the same rule: a comment a moderator hid answered 403 against 422 for a missing one, and a
 * reply under it or a comment under a video with comments turned off (both shown nowhere) could still be reported.
 */
class LikesAndReportsAnswerHiddenIdsLikeMissingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array']);
        Cache::flush();
        $this->owner = User::factory()->create();
    }

    public function test_a_video_the_member_may_not_see_answers_like_a_missing_id(): void
    {
        $member = User::factory()->create();
        $missing = (int) Video::withTrashed()->max('id') + 1000;

        foreach ($this->hiddenVideos() as $state => $video) {
            foreach (['/api/like', '/api/dislike'] as $route) {
                $answerMissing = $this->interact($member, $route, Video::class, $missing);
                $answerHidden = $this->interact($member, $route, Video::class, $video->id);
                $context = $route.', another member\'s video: '.$state;

                $this->assertSame(422, $answerHidden->getStatusCode(), $context);
                $this->assertSame($answerMissing->getStatusCode(), $answerHidden->getStatusCode(), $context.': status');
                $this->assertSame($answerMissing->json(), $answerHidden->json(), $context.': body');
                $this->assertSame($this->headers($answerMissing), $this->headers($answerHidden), $context.': headers');
            }
        }

        $this->assertSame(0, DB::table('interactions')->count(), 'nothing was liked');
    }

    public function test_a_comment_the_member_may_not_see_answers_like_a_missing_id(): void
    {
        $member = User::factory()->create();
        $public = $this->publicVideo();
        $hidden = [];

        foreach ($this->hiddenVideos() as $state => $video) {
            $hidden['under a video that is '.$state] = $this->commentOn($video, $this->owner);
        }

        $hidden['hidden by a moderator'] = $this->commentOn($public, $this->owner, ['banned_at' => now()]);
        $hidden['a reply under a comment a moderator hid'] = $this->commentOn($public, $this->owner, ['parent_id' => $hidden['hidden by a moderator']->id]);
        $deletedParent = $this->commentOn($public, $this->owner);
        $hidden['a reply under a deleted comment'] = $this->commentOn($public, $this->owner, ['parent_id' => $deletedParent->id]);
        $deletedParent->delete();
        $hidden['deleted'] = tap($this->commentOn($public, $this->owner))->delete();
        $commentsOff = $this->publicVideo(['allow_comments' => false]);
        $hidden['under a video with comments turned off'] = $this->commentOn($commentsOff, $this->owner);
        // the member's own comment under a video that went private: the list no longer shows it
        $wentPrivate = $this->publicVideo();
        $hidden['the member\'s own, under a video that went private'] = $this->commentOn($wentPrivate, $member);
        $wentPrivate->update(['status' => VideoStatus::PRIVATE->value]);

        $missing = (int) Comment::withTrashed()->max('id') + 1000;

        foreach ($hidden as $state => $comment) {
            foreach (['/api/like', '/api/dislike'] as $route) {
                $answerMissing = $this->interact($member, $route, Comment::class, $missing);
                $answerHidden = $this->interact($member, $route, Comment::class, $comment->id);
                $context = $route.', a comment '.$state;

                $this->assertSame(422, $answerHidden->getStatusCode(), $context);
                $this->assertSame($answerMissing->getStatusCode(), $answerHidden->getStatusCode(), $context.': status');
                $this->assertSame($answerMissing->json(), $answerHidden->json(), $context.': body');
                $this->assertSame($this->headers($answerMissing), $this->headers($answerHidden), $context.': headers');
            }
        }

        $this->assertSame(0, DB::table('interactions')->count(), 'nothing was liked');
    }

    public function test_what_the_site_shows_is_liked_as_before(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $public = $this->publicVideo();
        $plannedReached = Video::factory()->for($this->owner)->withStatus(VideoStatus::PLANNED)->create(['scheduled_at' => now()->subHour(), 'uploaded_at' => now()->subDay()]);
        // comments on (the factory turns them off for one video in ten: the comment under it would then be shown nowhere)
        $unlisted = Video::factory()->for($this->owner)->withStatus(VideoStatus::UNLISTED)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay(), 'allow_comments' => true]);
        $private = Video::factory()->for($this->owner)->withStatus(VideoStatus::PRIVATE)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $comment = $this->commentOn($public, $this->owner);
        $reply = $this->commentOn($public, $this->owner, ['parent_id' => $comment->id]);
        $onUnlisted = $this->commentOn($unlisted, $this->owner);
        $onPrivate = $this->commentOn($private, $this->owner);

        // another member: a public video (and one planned whose time came), its comments and replies, the comments of an
        // unlisted video; a like, then the same click again takes it back
        foreach ([[Video::class, $public], [Video::class, $plannedReached], [Comment::class, $comment], [Comment::class, $reply], [Comment::class, $onUnlisted]] as [$model, $likeable]) {
            $this->interact($member, '/api/like', $model, $likeable->id)->assertNoContent();
            $this->assertSame(1, $this->likesOf($member, $likeable));
            $this->interact($member, '/api/like', $model, $likeable->id)->assertNoContent();
            $this->assertSame(0, $this->likesOf($member, $likeable));
            $this->interact($member, '/api/dislike', $model, $likeable->id)->assertNoContent();
            $this->interact($member, '/api/dislike', $model, $likeable->id)->assertNoContent();
        }
        $this->assertSame(0, (int) $public->fresh()->likes_count);

        // an unlisted video is shown to anyone with its link, and its own refusal of a like stays as it was (the policy's)
        $this->interact($member, '/api/like', Video::class, $unlisted->id)->assertForbidden()
            ->assertJsonPath('message', __('You are not authorized to interact with this video'));

        // the channel: its own private video and the comments under it
        $this->interact($this->owner, '/api/like', Video::class, $private->id)->assertNoContent();
        $this->interact($this->owner, '/api/like', Comment::class, $onPrivate->id)->assertNoContent();

        // an administrator sees every video: the policy decides, as before (no like of another member's private video,
        // a like of a comment under it)
        $this->interact($admin, '/api/like', Video::class, $private->id)->assertForbidden();
        $this->interact($admin, '/api/like', Comment::class, $onPrivate->id)->assertNoContent();
    }

    public function test_a_hidden_id_takes_the_same_queries_as_a_missing_one(): void
    {
        $member = User::factory()->create();
        $hiddenVideo = Video::factory()->for($this->owner)->withStatus(VideoStatus::PRIVATE)->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $hiddenComment = $this->commentOn($hiddenVideo, $this->owner);
        $missingVideo = (int) Video::withTrashed()->max('id') + 1000;
        $missingComment = (int) Comment::withTrashed()->max('id') + 1000;
        // the first request of the test also reads what is kept for the next ones (the blocked addresses)
        $this->interactCountingQueries($member, Video::class, $missingVideo + 1);

        foreach ([[Video::class, $hiddenVideo->id, $missingVideo], [Comment::class, $hiddenComment->id, $missingComment]] as [$model, $hidden, $missing]) {
            [$hiddenAnswer, $hiddenQueries] = $this->interactCountingQueries($member, $model, $hidden);
            [$missingAnswer, $missingQueries] = $this->interactCountingQueries($member, $model, $missing);

            $this->assertSame(422, $hiddenAnswer->getStatusCode(), $model);
            $this->assertSame($missingAnswer->json(), $hiddenAnswer->json(), $model);
            $this->assertSame($missingQueries, $hiddenQueries, $model.': the same queries for a hidden and a missing id');
        }
    }

    public function test_report_reads_a_comment_with_the_same_rule(): void
    {
        $member = User::factory()->create();
        $writer = User::factory()->create();
        $public = $this->publicVideo();
        $hiddenByAModerator = $this->commentOn($public, $writer, ['banned_at' => now()]);
        $hidden = [
            'hidden by a moderator' => $hiddenByAModerator,
            'a reply under a comment a moderator hid' => $this->commentOn($public, $writer, ['parent_id' => $hiddenByAModerator->id]),
            'under a video with comments turned off' => $this->commentOn($this->publicVideo(['allow_comments' => false]), $writer),
        ];
        $missing = (int) Comment::withTrashed()->max('id') + 1000;

        foreach ($hidden as $state => $comment) {
            $answerHidden = $this->reportComment($member, $comment->id);
            $answerMissing = $this->reportComment($member, $missing);

            $this->assertSame(422, $answerHidden->getStatusCode(), $state);
            $this->assertSame($answerMissing->getStatusCode(), $answerHidden->getStatusCode(), $state.': status');
            $this->assertSame($answerMissing->json(), $answerHidden->json(), $state.': body');
            $this->assertSame($this->headers($answerMissing), $this->headers($answerHidden), $state.': headers');
        }
        $this->assertSame(__('This comment can no longer be reported.'), $answerMissing->json('message'));
        $this->assertSame(0, DB::table('reports')->count(), 'nothing was reported');

        // what the comment list shows can be reported as before: a comment and a reply under a public video
        $comment = $this->commentOn($public, $writer);
        $this->reportComment($member, $comment->id)->assertNoContent();
        $this->reportComment($member, $this->commentOn($public, $writer, ['parent_id' => $comment->id])->id)->assertNoContent();
        $this->assertSame(2, DB::table('reports')->count());
    }

    public function test_a_model_sent_as_a_list_is_refused_not_a_crash(): void
    {
        $member = User::factory()->create();

        $this->interact($member, '/api/like', [Video::class], $this->publicVideo()->id)->assertStatus(422)->assertJsonValidationErrors('model');
        $this->interact($member, '/api/like', 'App\\Models\\User', $member->id)->assertStatus(422)->assertJsonValidationErrors('model');
        $this->assertSame(0, DB::table('interactions')->count());
    }

    /** @return array<string, Video> another member's videos in every state the watch page does not show */
    private function hiddenVideos(): array
    {
        $out = fn (array $extra = []) => array_merge(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()], $extra);
        $deletedChannel = User::factory()->create();

        $videos = [
            'private' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PRIVATE)->create($out()),
            'a draft' => Video::factory()->for($this->owner)->withStatus(VideoStatus::DRAFT)->create($out()),
            'planned for later' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PLANNED)->create($out(['scheduled_at' => now()->addDays(3)])),
            'public with a date to come' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out(['published_at' => now()->addDays(30)])),
            'still uploading' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out(['uploaded_at' => null])),
            'failed' => Video::factory()->for($this->owner)->withStatus(VideoStatus::FAILED)->create($out()),
            'banned' => Video::factory()->for($this->owner)->withStatus(VideoStatus::BANNED)->banned()->create($out()),
            'of a deleted channel' => Video::factory()->for($deletedChannel)->withStatus(VideoStatus::PUBLIC)->create($out()),
            'deleted' => Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)->create($out()),
        ];
        $videos['deleted']->delete();
        $deletedChannel->delete();

        return $videos;
    }

    private function publicVideo(array $extra = []): Video
    {
        return Video::factory()->for($this->owner)->withStatus(VideoStatus::PUBLIC)
            ->create(array_merge(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay(), 'allow_comments' => true], $extra));
    }

    private function commentOn(Video $video, User $author, array $extra = []): Comment
    {
        // a stored comment as it is (no new-comment mail: one of the videos belongs to a deleted channel)
        return Comment::withoutEvents(fn () => Comment::factory()->create(array_merge(['video_id' => $video->id, 'user_id' => $author->id], $extra)));
    }

    private function interact(User $account, string $route, mixed $model, int $id): TestResponse
    {
        // every answer starts from the same limiter state
        Cache::store('array')->flush();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($account, 'sanctum')->postJson($route, ['model' => $model, 'id' => $id]);
    }

    private function reportComment(User $account, int $id): TestResponse
    {
        Cache::store('array')->flush();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($account, 'sanctum')->postJson('/api/report', ['type' => Comment::class, 'id' => $id, 'reason' => 1]);
    }

    private function likesOf(User $account, Video|Comment $likeable): int
    {
        return DB::table('interactions')->where('user_id', $account->id)->where('likeable_type', $likeable::class)
            ->where('likeable_id', $likeable->id)->where('status', true)->count();
    }

    /** @return array{0: TestResponse, 1: list<string>} */
    private function interactCountingQueries(User $member, string $model, int $id): array
    {
        Cache::store('array')->flush();
        $this->app['auth']->forgetGuards();
        $this->actingAs($member, 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->postJson('/api/like', ['model' => $model, 'id' => $id]);
        $queries = array_map(fn (array $q) => preg_replace('/\d+/', 'N', $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return [$response, $queries];
    }

    /** @return array<string, string> every header but the date, the cookies and the length */
    private function headers(TestResponse $response): array
    {
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            if (in_array($name, ['date', 'set-cookie', 'content-length'], true)) {
                continue;
            }

            $headers[$name] = implode(', ', $values);
        }

        ksort($headers);

        return $headers;
    }
}
