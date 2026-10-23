<?php

namespace Tests\Feature\Api;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ViewerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_watch_history_returns_empty_state_payload(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->getJson(route('me.history'))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_watch_history_returns_populated_public_views_only(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create();
        $category = $this->createCategory('History');
        $publicVideo = $this->createVideo('Watched Public Video', $category, $creator, VideoStatus::PUBLIC);
        $privateVideo = $this->createVideo('Hidden Private Video', $category, $creator, VideoStatus::PRIVATE);

        View::query()->create([
            'video_id' => $publicVideo->id,
            'user_id' => $viewer->id,
            'ip' => '127.0.0.1',
            'view_at' => now()->subMinutes(10),
        ]);
        View::query()->create([
            'video_id' => $privateVideo->id,
            'user_id' => $viewer->id,
            'ip' => '127.0.0.1',
            'view_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($viewer)
            ->getJson(route('me.history'))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Watched Public Video'])
            ->assertJsonMissing(['title' => 'Hidden Private Video']);
    }

    public function test_playlist_save_and_delete_mutations_are_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $category = $this->createCategory('Playlist');
        $video = $this->createVideo('Saveable Video', $category, $owner, VideoStatus::PUBLIC);
        $playlist = Playlist::factory()
            ->for($owner)
            ->create([
                'status' => PlaylistStatus::PUBLIC,
                'sort' => PlaylistSort::MANUAL,
                'is_deletable' => true,
            ]);

        $this->actingAs($attacker)
            ->postJson(route('playlists.save'), [
                'video_id' => $video->id,
                'playlist_id' => $playlist->id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('playlist_has_videos', [
            'playlist_id' => $playlist->id,
            'video_id' => $video->id,
        ]);

        $this->actingAs($owner)
            ->postJson(route('playlists.save'), [
                'video_id' => $video->id,
                'playlist_id' => $playlist->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('playlist_has_videos', [
            'playlist_id' => $playlist->id,
            'video_id' => $video->id,
        ]);

        $this->actingAs($owner)
            ->postJson(route('playlists.save'), [
                'video_id' => $video->id,
                'playlist_id' => $playlist->id,
            ])
            ->assertCreated();

        $this->assertDatabaseMissing('playlist_has_videos', [
            'playlist_id' => $playlist->id,
            'video_id' => $video->id,
        ]);

        $this->actingAs($attacker)
            ->deleteJson(route('playlists.delete', $playlist->uuid))
            ->assertForbidden();

        $this->assertDatabaseHas('playlists', [
            'id' => $playlist->id,
            'deleted_at' => null,
        ]);
    }

    public function test_playlist_save_rejects_private_video_owned_by_another_user(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create();
        $category = $this->createCategory('Private Playlist Save');
        $privateVideo = $this->createVideo('Private Save Target', $category, $creator, VideoStatus::PRIVATE);
        $playlist = Playlist::factory()
            ->for($viewer)
            ->create([
                'status' => PlaylistStatus::PRIVATE,
                'sort' => PlaylistSort::MANUAL,
                'is_deletable' => true,
            ]);

        $this->actingAs($viewer)
            ->postJson(route('playlists.save'), [
                'video_id' => $privateVideo->id,
                'playlist_id' => $playlist->id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('playlist_has_videos', [
            'playlist_id' => $playlist->id,
            'video_id' => $privateVideo->id,
        ]);
    }

    public function test_subscription_feed_only_returns_active_videos_from_subscribed_channels(): void
    {
        $viewer = User::factory()->create();
        $subscribedCreator = User::factory()->create();
        $otherCreator = User::factory()->create();
        $category = $this->createCategory('Subscriptions');
        $subscribedPublic = $this->createVideo('Subscribed Public Upload', $category, $subscribedCreator, VideoStatus::PUBLIC);
        $subscribedPrivate = $this->createVideo('Subscribed Private Upload', $category, $subscribedCreator, VideoStatus::PRIVATE);
        $unsubscribedPublic = $this->createVideo('Unsubscribed Public Upload', $category, $otherCreator, VideoStatus::PUBLIC);

        $viewer->subscriptions()->attach($subscribedCreator->id, [
            'subscribe_at' => now(),
            'read_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->getJson(route('me.subscriptions-videos'))
            ->assertOk()
            ->assertJsonFragment(['title' => $subscribedPublic->title])
            ->assertJsonMissing(['title' => $subscribedPrivate->title])
            ->assertJsonMissing(['title' => $unsubscribedPublic->title]);
    }

    public function test_history_query_count_stays_bounded_for_populated_viewer(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create();
        $category = $this->createCategory('History Queries');

        foreach (range(1, 6) as $index) {
            $video = $this->createVideo("Watched Video {$index}", $category, $creator, VideoStatus::PUBLIC);

            View::query()->create([
                'video_id' => $video->id,
                'user_id' => $viewer->id,
                'ip' => '127.0.0.1',
                'view_at' => now()->subMinutes($index),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($viewer)
            ->getJson(route('me.history'))
            ->assertOk();

        $this->assertLessThanOrEqual(
            12,
            count(DB::getQueryLog()),
            'The watch-history endpoint should eager-load card relationships instead of issuing per-view queries.'
        );
    }

    public function test_history_clear_day_rejects_malformed_dates_without_deleting_rows(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create();
        $category = $this->createCategory('Clear Day');
        $video = $this->createVideo('Clear Day Video', $category, $creator, VideoStatus::PUBLIC);

        View::query()->create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'ip' => '127.0.0.1',
            'view_at' => now()->subHour(),
        ]);

        $this->actingAs($viewer)
            ->postJson(route('me.clear-day'), ['date' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->assertDatabaseHas('views', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);
    }

    private function createCategory(string $title): Category
    {
        return Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'play',
            'in_menu' => true,
            'position' => 1,
        ]);
    }

    private function createVideo(string $title, Category $category, User $creator, VideoStatus $status): Video
    {
        return Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus($status)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => now()->subHour(),
                'uploaded_at' => now()->subHour(),
                'language' => null,
            ]);
    }
}
