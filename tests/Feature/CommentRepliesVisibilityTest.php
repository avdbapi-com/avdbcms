<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security plan S05: the replies of a comment follow the rules of the comment list. A private video or one with
 * comments turned off answers 404 to everybody but its owner (and an administrator), a reply a moderator hid is missing
 * from the list and from its total, and the replies of a hidden comment answer 404.
 */
class CommentRepliesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_never_reads_the_replies_under_a_private_video(): void
    {
        [$owner, $video, $thread] = $this->thread(VideoStatus::PRIVATE, true);

        $this->getJson($this->repliesUrl($video, $thread))->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($this->repliesUrl($video, $thread))->assertNotFound();

        // Its owner still reads them.
        $this->actingAs($owner)->getJson($this->repliesUrl($video, $thread))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_visitor_never_reads_the_replies_when_comments_are_turned_off(): void
    {
        [$owner, $video, $thread] = $this->thread(VideoStatus::PUBLIC, false);

        $this->getJson($this->repliesUrl($video, $thread))->assertNotFound();
        $this->actingAs($owner)->getJson($this->repliesUrl($video, $thread))->assertOk();
    }

    public function test_a_hidden_reply_is_missing_from_the_list_and_the_total(): void
    {
        [, $video, $thread] = $this->thread(VideoStatus::PUBLIC, true);
        $hidden = Comment::factory()->for(User::factory()->create(), 'user')->for($video)->banned()
            ->createQuietly(['parent_id' => $thread->id, 'content' => 'A reply a moderator hid.']);

        $response = $this->getJson($this->repliesUrl($video, $thread))->assertOk();

        $this->assertNotContains($hidden->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(2, $response->json('total'));
        $response->assertJsonCount(2, 'data');
    }

    public function test_the_replies_of_a_hidden_comment_answer_404(): void
    {
        [, $video, $thread] = $this->thread(VideoStatus::PUBLIC, true);
        $thread->forceFill(['banned_at' => now()])->saveQuietly();

        $this->getJson($this->repliesUrl($video, $thread))->assertNotFound();
    }

    public function test_public_threads_are_unchanged(): void
    {
        [, $video, $thread] = $this->thread(VideoStatus::PUBLIC, true);

        $this->getJson($this->repliesUrl($video, $thread))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 2);
    }

    /** @return array{0: User, 1: Video, 2: Comment} a video of its owner with one comment that has two replies */
    private function thread(VideoStatus $status, bool $allowComments): array
    {
        $owner = User::factory()->create();
        $video = Video::factory()->for($owner, 'user')->withStatus($status)->create(['allow_comments' => $allowComments]);
        $thread = Comment::factory()->for(User::factory()->create(), 'user')->for($video)->createQuietly(['content' => 'The first comment.']);

        foreach (['One reply.', 'Another reply.'] as $text) {
            Comment::factory()->for(User::factory()->create(), 'user')->for($video)->createQuietly(['parent_id' => $thread->id, 'content' => $text]);
        }

        return [$owner, $video, $thread];
    }

    private function repliesUrl(Video $video, Comment $thread): string
    {
        return route('comments.replies', [$video->uuid, $thread->id]);
    }
}
