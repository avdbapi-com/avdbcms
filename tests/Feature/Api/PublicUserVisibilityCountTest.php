<?php

namespace Tests\Feature\Api;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicUserVisibilityCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_counts_and_playlists_only_include_locale_visible_active_videos(): void
    {
        $user = User::factory()->create();
        $viewer = User::factory()->create();

        $activeVideo = Video::factory()
            ->for($user)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'language' => 'en',
                'published_at' => now()->subMinute(),
                'uploaded_at' => now()->subMinute(),
                'views' => 7,
            ]);
        $privateVideo = Video::factory()
            ->for($user)
            ->withStatus(VideoStatus::PRIVATE)
            ->create([
                'language' => 'en',
                'views' => 100,
            ]);

        View::factory()
            ->for($activeVideo, 'video')
            ->create(['user_id' => $viewer->id]);
        View::factory(3)
            ->for($privateVideo, 'video')
            ->create(['user_id' => $viewer->id]);

        $playlist = Playlist::factory()
            ->for($user)
            ->create(['status' => PlaylistStatus::PUBLIC]);
        $playlist->videos()->attach([$activeVideo->id, $privateVideo->id]);

        $this->getJson(route('users.show', $user->id))
            ->assertOk()
            ->assertJsonPath('videos_count', 1)
            ->assertJsonPath('views', 7)
            ->assertJsonCount(1, 'videos')
            ->assertJsonCount(1, 'playlists')
            ->assertJsonPath('playlists.0.videos_count', 1)
            ->assertJsonCount(1, 'playlists.0.videos');

        $this->getJson(route('users.playlists', $user->id))
            ->assertOk()
            ->assertJsonPath('data.0.videos_count', 1);

        $this->getJson(route('playlists.show', $playlist->uuid))
            ->assertOk()
            ->assertJsonPath('videos_count', 1)
            ->assertJsonCount(1, 'videos');
        $this->getJson(route('playlists.videos', $playlist->uuid))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)->getJson(route('playlists.show', $playlist->uuid))
            ->assertOk()
            ->assertJsonPath('videos_count', 2)
            ->assertJsonCount(2, 'videos');
    }

    public function test_profile_counts_use_the_current_locale_for_native_videos(): void
    {
        app()->setLocale('vi');

        $user = User::factory()->create();
        Video::factory()
            ->for($user)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'language' => 'vi',
                'published_at' => now()->subMinute(),
                'uploaded_at' => now()->subMinute(),
            ]);
        Video::factory()
            ->for($user)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'language' => 'en',
                'published_at' => now()->subMinute(),
                'uploaded_at' => now()->subMinute(),
            ]);

        $this->getJson(route('users.show', $user->id))
            ->assertOk()
            ->assertJsonPath('videos_count', 1)
            ->assertJsonCount(1, 'videos');
    }
}
