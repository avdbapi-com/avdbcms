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

    // Security plan S07: another member's private video never gets into a member's playlists.

    public function test_another_members_private_video_cannot_be_saved_to_watch_later(): void
    {
        [$member, , $private] = $this->memberAndOthersVideos();

        $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $private->id))->assertNotFound();

        $ids = $this->actingAs($member)->getJson(route('custom-playlist.watch-later.ids'))->assertOk()->json();
        $this->assertNotContains($private->id, array_map('intval', (array) ($ids['ids'] ?? $ids['data'] ?? $ids)));
        $this->assertDatabaseMissing('playlist_has_videos', ['video_id' => $private->id]);
    }

    public function test_another_members_private_video_cannot_be_added_to_a_playlist(): void
    {
        [$member, , $private] = $this->memberAndOthersVideos();
        $playlist = Playlist::factory()->for($member)->create();

        $this->actingAs($member)->from(route('user.playlists.edit', $playlist))
            ->put(route('user.playlists.update', $playlist), $this->playlistForm('Mine', [$private->id]))
            ->assertSessionHasErrors('videos.0');
        $this->assertFalse($playlist->videos()->whereKey($private->id)->exists());

        $this->actingAs($member)->from(route('user.playlists.create'))
            ->post(route('user.playlists.store'), $this->playlistForm('New one', [$private->id]))
            ->assertSessionHasErrors('videos.0');
        $this->assertDatabaseMissing('playlists', ['title' => 'New one']);
    }

    public function test_public_and_own_private_videos_are_still_added(): void
    {
        [$member, $public] = $this->memberAndOthersVideos();
        $own = Video::factory()->for($member)->withStatus(VideoStatus::PRIVATE)->create(['uploaded_at' => now()->subHour()]);

        $this->actingAs($member)->post(route('user.playlists.store'), $this->playlistForm('Store mixed', [$public->id, $own->id]))
            ->assertRedirect(route('user.playlists.index'));
        $stored = Playlist::query()->where('title', 'Store mixed')->firstOrFail();
        $this->assertEqualsCanonicalizing([$public->id, $own->id], $stored->videos()->pluck('videos.id')->all());

        $playlist = Playlist::factory()->for($member)->create();
        $this->actingAs($member)->put(route('user.playlists.update', $playlist), $this->playlistForm('Update mixed', [$public->id, $own->id]))
            ->assertRedirect(route('user.playlists.index'));
        $this->assertEqualsCanonicalizing([$public->id, $own->id], $playlist->videos()->pluck('videos.id')->all());

        $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $public->id))->assertOk()->assertJson(['status' => 'added']);
    }

    public function test_a_video_that_went_private_after_it_was_saved_keeps_the_playlist_working(): void
    {
        [$member, $public] = $this->memberAndOthersVideos();
        $playlist = Playlist::factory()->for($member)->create();
        $playlist->videos()->attach($public->id, ['position' => 1, 'added_at' => now()]);
        $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $public->id))->assertOk()->assertJson(['status' => 'added']);

        $public->forceFill(['status' => VideoStatus::PRIVATE->value])->saveQuietly();

        // The playlist that already holds it still saves, unchanged.
        $this->actingAs($member)->put(route('user.playlists.update', $playlist), $this->playlistForm('Still saves', [$public->id]))
            ->assertRedirect(route('user.playlists.index'));
        $this->assertTrue($playlist->videos()->whereKey($public->id)->exists());

        // It can still be taken out of Watch Later.
        $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $public->id))->assertOk()->assertJson(['status' => 'removed']);
    }

    public function test_the_members_playlists_never_show_another_members_private_video(): void
    {
        [$member, , $private] = $this->memberAndOthersVideos();
        $playlist = Playlist::factory()->for($member)->create();
        $this->actingAs($member)->put(route('user.playlists.update', $playlist), $this->playlistForm('Probe', [$private->id]));
        $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $private->id));

        $this->actingAs($member)->getJson(route('me.playlists'))->assertOk()->assertDontSee((string) $private->uuid);
        $this->actingAs($member)->get(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))->assertOk()->assertDontSee((string) $private->uuid);
    }

    /** @return array{0: User, 1: Video, 2: Video} a member, another member's public video and private video */
    private function memberAndOthersVideos(): array
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $public = Video::factory()->for($other)->withStatus(VideoStatus::PUBLIC)->create(['uploaded_at' => now()->subHour(), 'published_at' => now()->subHour()]);
        $private = Video::factory()->for($other)->withStatus(VideoStatus::PRIVATE)->create(['uploaded_at' => now()->subHour()]);

        return [$member, $public, $private];
    }

    /** @param  list<int>  $videos */
    private function playlistForm(string $title, array $videos): array
    {
        return [
            'title' => $title,
            'status' => PlaylistStatus::PUBLIC->value,
            'sort' => PlaylistSort::MANUAL->value,
            'videos' => $videos,
        ];
    }
}
