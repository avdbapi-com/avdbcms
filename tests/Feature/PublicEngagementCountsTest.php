<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicEngagementCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderated_comments_and_deleted_likes_do_not_inflate_public_counts(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)
            ->create(['show_likes' => true, 'allow_comments' => true]);
        Comment::factory()->for($user)->for($video)->createQuietly();
        Comment::factory()->for($user)->for($video)->banned()->createQuietly();
        $like = $video->interactions()->create(['user_id' => $user->id, 'status' => true, 'perform_at' => now()]);
        $video->updateQuietly(['likes_count' => 999, 'dislikes_count' => 999, 'comments_count' => 999]);

        $feedVideo = app(PublicVideoFeedService::class)->latest()->findOrFail($video->id);
        $this->assertSame(1, $feedVideo->likes_count);
        $this->assertSame(0, $feedVideo->dislikes_count);
        $this->assertSame(1, $feedVideo->comments_count);

        // Admin bulk deletion bypasses interaction events; the display must still be truthful.
        DB::table('interactions')->where('id', $like->id)->delete();
        $this->getJson(route('videos.show', $video->uuid))
            ->assertOk()
            ->assertJsonPath('likes', 0)
            ->assertJsonPath('dislikes', 0)
            ->assertJsonPath('comments', 1);
        $this->assertSame(0, app(PublicVideoFeedService::class)->latest()->findOrFail($video->id)->likes_count);
    }
}
