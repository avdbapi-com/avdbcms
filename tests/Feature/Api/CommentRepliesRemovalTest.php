<?php

namespace Tests\Feature\Api;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1.2.3: removing a comment from the watch page (the comments API, its author or the video's
 * owner) removes its replies too, like the studio and the admin do; the video's stored comment count counts each row
 * off once. Before, the replies stayed in the database under a parent that no longer existed and were still counted.
 */
class CommentRepliesRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_video_owner_removing_a_comment_removes_its_replies(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $video = Video::factory()->for($owner, 'user')->withStatus(VideoStatus::PUBLIC)->create(['allow_comments' => true]);
        $comment = Comment::query()->create(['video_id' => $video->id, 'user_id' => $viewer->id, 'content' => 'Top comment']);
        $replyOne = Comment::query()->create(['video_id' => $video->id, 'user_id' => $owner->id, 'parent_id' => $comment->id, 'content' => 'Reply one']);
        $replyTwo = Comment::query()->create(['video_id' => $video->id, 'user_id' => $viewer->id, 'parent_id' => $comment->id, 'content' => 'Reply two']);
        $other = Comment::query()->create(['video_id' => $video->id, 'user_id' => $viewer->id, 'content' => 'Another comment']);
        Interaction::query()->create(['user_id' => $owner->id, 'likeable_type' => Comment::class, 'likeable_id' => $replyTwo->id, 'status' => true, 'perform_at' => now()]);
        $this->assertSame(4, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));

        $this->actingAs($owner)->deleteJson(route('comments.delete', [$video->uuid, $comment->id]))->assertNoContent();

        foreach ([$comment, $replyOne, $replyTwo] as $gone) {
            $this->assertNull(Comment::withTrashed()->find($gone->id));
        }
        $this->assertNotNull(Comment::query()->find($other->id));
        $this->assertSame(0, Interaction::query()->where('likeable_type', Comment::class)->where('likeable_id', $replyTwo->id)->count());
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));
    }

    public function test_removing_a_reply_keeps_its_comment(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $video = Video::factory()->for($owner, 'user')->withStatus(VideoStatus::PUBLIC)->create(['allow_comments' => true]);
        $comment = Comment::query()->create(['video_id' => $video->id, 'user_id' => $viewer->id, 'content' => 'Top comment']);
        $reply = Comment::query()->create(['video_id' => $video->id, 'user_id' => $viewer->id, 'parent_id' => $comment->id, 'content' => 'My reply']);

        $this->actingAs($viewer)->deleteJson(route('comments.delete', [$video->uuid, $reply->id]))->assertNoContent();

        $this->assertNull(Comment::withTrashed()->find($reply->id));
        $this->assertNotNull(Comment::query()->find($comment->id));
        $this->assertSame(1, (int) DB::table('videos')->where('id', $video->id)->value('comments_count'));
    }
}
