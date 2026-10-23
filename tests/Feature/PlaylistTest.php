<?php

namespace Tests\Feature;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Enums\CustomPlaylistType;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Playlists\Types\WatchLaterPlaylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_public_playlist_(): void
    {
        $user = User::factory()->create();

        $playlist = Playlist::factory()
            ->for($user)
            ->create([
                'status' => PlaylistStatus::PUBLIC
            ]);

        $this
            ->get(route('playlist.show', $playlist))
            ->assertOk();
    }

    public function test_show_private_playlist_(): void
    {
        $user = User::factory()->create();

        $playlist = Playlist::factory()
            ->for($user)
            ->create([
                'status' => PlaylistStatus::PRIVATE
            ]);

        $this->actingAs($user)
            ->get(route('playlist.show', $playlist))
            ->assertOk();
    }

    public function test_first_video_preview_matches_visible_playlist_videos(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'status' => PlaylistStatus::PUBLIC,
            'sort' => PlaylistSort::MANUAL,
        ]);

        $hidden = Video::factory()->for($user)->withStatus(VideoStatus::PRIVATE)->create([
            'uploaded_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);
        $visible = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)->create([
            'uploaded_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);

        $playlist->videos()->attach($hidden->id, ['position' => 1, 'added_at' => now()->subMinutes(2)]);
        $playlist->videos()->attach($visible->id, ['position' => 2, 'added_at' => now()->subMinute()]);

        $this->assertSame($visible->id, $playlist->fresh()->first_video?->id);

        $loaded = $playlist->fresh()->load([
            'videos' => fn ($query) => $query->active()->forLocale(),
        ]);
        $this->assertSame($visible->id, $loaded->first_video?->id);
    }

    public function test_show_watch_later_playlist(): void
    {
        $user = User::factory()->create();

        Playlist::factory()
            ->for($user)
            ->create([
                'status' => PlaylistStatus::PRIVATE,
                'title' => WatchLaterPlaylist::getName()
            ]);

        $this->actingAs($user)
            ->get(route('custom-playlist.show', ['type' => CustomPlaylistType::WATCH_LATER]))
            ->assertOk();
    }

    public function test_show_liked_videos_playlist(): void
    {
        $user = User::factory()->create();

        Playlist::factory()
            ->for($user)
            ->create([
                'status' => PlaylistStatus::PRIVATE,
                'title' => LikedVideosPlaylist::getName()
            ]);

        $this->actingAs($user)
            ->get(route('custom-playlist.show', ['type' => CustomPlaylistType::LIKED_VIDEOS]))
            ->assertOk();
    }

    public function test_create_playlist_view(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('user.playlists.create'))
            ->assertOk();
    }

    public function test_create_playlist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
             ->post(route('user.playlists.store'), [
                'title' => 'Test playlist',
                'status' => PlaylistStatus::PUBLIC->value,
                'sort' => PlaylistSort::MANUAL->value
            ])
            ->assertRedirect(route('user.playlists.index'));

        $this->assertDatabaseHas('playlists', [
            'title' => 'Test playlist',
            'status' => PlaylistStatus::PUBLIC
        ]);
    }

    public function test_update_playlist_view(): void
    {
        $user = User::factory()->create();

        $playlist = Playlist::factory()
            ->for($user)
            ->create();

        $this->actingAs($user)
            ->get(route('user.playlists.edit', $playlist))
            ->assertOk();
    }

    public function test_update_playlist(): void
    {
        $user = User::factory()->create();

        $playlist = Playlist::factory()
            ->for($user)
            ->create();

        $this->actingAs($user)
            ->put(route('user.playlists.update', $playlist), [
                'title' => 'Rename playlist',
                'status' => PlaylistStatus::PUBLIC->value,
                'sort' => PlaylistSort::MANUAL->value
            ])
            ->assertRedirect(route('user.playlists.index'));

        $this->assertDatabaseHas('playlists', [
            'title' => 'Rename playlist',
            'status' => PlaylistStatus::PUBLIC->value,
            'sort' => PlaylistSort::MANUAL->value
        ]);
    }
}
