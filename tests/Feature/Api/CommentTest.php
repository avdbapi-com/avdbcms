<?php

namespace Tests\Feature\Api;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_comments_list(): void
    {
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create([
            'allow_comments' => true
        ]);

        Comment::factory()->forUser()->for($video)->count(3)->createQuietly();

        $response = $this->get(route('comments.index', $video->uuid));

        $response
            ->assertStatus(200)
            ->assertJson(fn (AssertableJson $json) =>
                $json
                    ->has('data', 3, fn (AssertableJson $json) =>
                        $json
                            ->whereType('id', 'integer')
                            ->whereType('class', 'string')
                            ->whereType('content', 'string')
                            ->whereType('parsed_content', 'string')
                            ->whereType('short_content', 'string')
                            ->whereType('is_long', 'boolean')
                            ->has('user', fn (AssertableJson $json) =>
                                $json->whereType('id', 'integer')
                                    ->whereType('username', 'string')
                                    ->whereType('avatar', 'string')
                                    ->whereType('route', 'string')
                                    ->whereType('is_video_author', 'boolean')
                            )
                            ->whereType('video_uuid', 'string')
                            ->whereType('created_at', 'string')
                            ->whereType('is_updated', 'boolean')
                            ->whereType('likes_count', 'integer')
                            ->whereType('dislikes_count', 'integer')
                            ->whereType('is_reply', 'boolean')
                            ->whereType('is_pinned', 'boolean')
                            ->whereType('has_replies', 'boolean')
                            ->whereType('replies_count', 'integer')
                            ->whereType('is_video_author_reply', 'boolean')
                            ->whereType('is_video_author_like', 'boolean')
                            ->etc()
                    )
                    ->has('links')
                    ->has('meta')
                    ->has('count')
            );
    }

    public function test_comment_delete_requires_owner_or_video_owner(): void
    {
        $videoOwner = User::factory()->create();
        $commenter = User::factory()->create();
        $attacker = User::factory()->create();
        $video = Video::factory()
            ->for($videoOwner, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);
        $comment = Comment::factory()
            ->for($commenter, 'user')
            ->for($video)
            ->createQuietly();

        $this->actingAs($attacker)
            ->deleteJson(route('comments.delete', [$video->uuid, $comment->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_comment_pin_and_unpin_require_video_owner(): void
    {
        $videoOwner = User::factory()->create();
        $commenter = User::factory()->create();
        $attacker = User::factory()->create();
        $video = Video::factory()
            ->for($videoOwner, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);
        $comment = Comment::factory()
            ->for($commenter, 'user')
            ->for($video)
            ->createQuietly();

        $this->actingAs($attacker)
            ->postJson(route('comments.pin', [$video->uuid, $comment->id]))
            ->assertForbidden();
        $this->assertNull($video->refresh()->pinned_comment_id);

        $this->actingAs($videoOwner)
            ->postJson(route('comments.pin', [$video->uuid, $comment->id]))
            ->assertNoContent();
        $this->assertSame($comment->id, $video->refresh()->pinned_comment_id);

        $this->actingAs($attacker)
            ->postJson(route('comments.unpin', [$video->uuid, $comment->id]))
            ->assertForbidden();
        $this->assertSame($comment->id, $video->refresh()->pinned_comment_id);

        $this->actingAs($videoOwner)
            ->postJson(route('comments.unpin', [$video->uuid, $comment->id]))
            ->assertNoContent();
        $this->assertNull($video->refresh()->pinned_comment_id);
    }
}
