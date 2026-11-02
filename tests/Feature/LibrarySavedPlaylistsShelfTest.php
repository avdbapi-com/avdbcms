<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 1 (R1-034, channel-29): the Library page has a Saved playlists shelf, with the same playlists as the Saved
 * playlists page (only those that open: public or unlisted) and "See all" to it.
 */
class LibrarySavedPlaylistsShelfTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_library_shows_the_saved_playlists_that_open(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create();
        $open = $this->saved($member, $channel, 'Open saved playlist', PlaylistStatus::PUBLIC);
        $unlisted = $this->saved($member, $channel, 'Unlisted saved playlist', PlaylistStatus::UNLISTED);
        $this->saved($member, $channel, 'Went private later', PlaylistStatus::PRIVATE);

        $html = $this->actingAs($member)->get(route('pages.library'))->assertOk()->getContent();
        $shelf = $this->between($html, 'aria-labelledby="mkShelfSaved"', '</section>');

        $this->assertMatchesRegularExpression('#<h2 class="mk-panel__title" id="mkShelfSaved">.*Saved playlists <span class="mk-count">2</span></h2>#s', $shelf);
        $this->assertStringContainsString('Open saved playlist', $shelf);
        $this->assertStringContainsString('Unlisted saved playlist', $shelf);
        $this->assertStringNotContainsString('Went private later', $shelf);
        $this->assertMatchesRegularExpression('#href="(?:https?://[^/"]+)?'.preg_quote((string) parse_url(route('playlist.manage'), PHP_URL_PATH), '#').'"#', $shelf, 'See all');
        $this->assertNotNull($open);
        $this->assertNotNull($unlisted);
    }

    public function test_a_member_with_only_saved_playlists_has_no_empty_library(): void
    {
        $member = User::factory()->create();
        $this->saved($member, User::factory()->create(), 'Only this one', PlaylistStatus::PUBLIC);

        $html = $this->actingAs($member)->get(route('pages.library'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Your library is empty', $html);
        $this->assertStringContainsString('Only this one', $html);
    }

    private function saved(User $member, User $channel, string $title, PlaylistStatus $status): Playlist
    {
        $playlist = Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => $title, 'user_id' => $channel->id,
            'status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);
        DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => now()->subMinute()]);
        $playlist->forceFill(['status' => $status])->save();

        return $playlist->fresh();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'missing: '.$from);
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
