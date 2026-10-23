<?php

namespace Tests\Feature;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedJourneyAbuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_clear_another_users_history_view_id(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create();
        $otherView = View::query()->create([
            'video_id' => $video->id,
            'user_id' => $otherUser->id,
            'ip' => '127.0.0.1',
            'view_at' => now()->subMinute(),
        ]);

        $this->actingAs($viewer)
            ->deleteJson(route('me.clear', $otherView))
            ->assertForbidden();

        $this->assertDatabaseHas('views', [
            'id' => $otherView->id,
            'deleted_at' => null,
        ]);
    }

    public function test_playlist_save_rejects_playlist_id_owned_by_another_user(): void
    {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create();
        $otherPlaylist = Playlist::factory()
            ->for($otherUser)
            ->create([
                'status' => PlaylistStatus::PUBLIC,
                'sort' => PlaylistSort::MANUAL,
                'is_deletable' => true,
            ]);

        $this->actingAs($viewer)
            ->postJson(route('playlists.save'), [
                'video_id' => $video->id,
                'playlist_id' => $otherPlaylist->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['playlist_id']);

        $this->assertDatabaseMissing('playlist_has_videos', [
            'playlist_id' => $otherPlaylist->id,
            'video_id' => $video->id,
        ]);
    }

    public function test_comment_routes_reject_comment_ids_from_a_different_video(): void
    {
        $commenter = User::factory()->create();
        $routeVideo = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);
        $actualVideo = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);
        $comment = Comment::factory()
            ->for($commenter, 'user')
            ->for($actualVideo)
            ->create(['content' => 'Original comment']);

        $this->actingAs($commenter)
            ->putJson(route('comments.update', [$routeVideo->uuid, $comment->id]), [
                'content' => 'Tampered through another video route',
            ])
            ->assertNotFound();

        $this->assertSame('Original comment', $comment->refresh()->content);
    }

    public function test_comment_reply_parent_must_belong_to_the_route_video(): void
    {
        $commenter = User::factory()->create();
        $routeVideo = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);
        $otherVideo = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['allow_comments' => true]);
        $foreignParent = Comment::factory()
            ->forUser()
            ->for($otherVideo)
            ->create();

        $this->actingAs($commenter)
            ->postJson(route('comments.store', $routeVideo->uuid), [
                'parent_id' => $foreignParent->id,
                'content' => 'Cross-video reply attempt',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertDatabaseMissing('comments', [
            'video_id' => $routeVideo->id,
            'parent_id' => $foreignParent->id,
        ]);
    }

    public function test_report_payload_user_id_injection_still_uses_authenticated_user(): void
    {
        $reporter = User::factory()->create();
        $injectedUser = User::factory()->create();
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create();

        $this->actingAs($reporter)
            ->postJson(route('report'), [
                'type' => Video::class,
                'id' => $video->id,
                'reason' => ReportReason::HARASSMENT_OR_BULLYING->value,
                'comment' => 'Malicious user_id injection attempt',
                'user_id' => $injectedUser->id,
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('reports', [
            'user_id' => $reporter->id,
            'reportable_type' => Video::class,
            'reportable_id' => $video->id,
        ]);
        $this->assertDatabaseMissing('reports', [
            'user_id' => $injectedUser->id,
            'reportable_type' => Video::class,
            'reportable_id' => $video->id,
        ]);
    }
}
