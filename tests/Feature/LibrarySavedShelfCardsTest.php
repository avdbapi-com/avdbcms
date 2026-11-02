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
 * Round 2a (R2A-031): the Library's Saved playlists shelf (R1-034) showed the playlists of other channels like the
 * member's own ("Public · Updated Oct 25", no channel), right under the member's Playlists shelf, while the Saved
 * playlists page shows the same cards as "qa3ch_curator · Saved 3 days ago". The shelf's cards name the channel and say
 * when the playlist was saved, as that page does, and the channels come in one query for the whole shelf.
 */
class LibrarySavedShelfCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_saved_shelf_names_the_channel_and_when_it_was_saved(): void
    {
        $member = User::factory()->create();
        $curator = User::factory()->create(['username' => 'qa3ch_curator']);
        $this->saved($member, $curator, 'Night drives', now()->subDays(3));
        // the member's own playlist keeps its own meta line on the Playlists shelf
        Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => 'My own list', 'user_id' => $member->id, 'status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);

        $html = $this->actingAs($member)->get(route('pages.library'))->assertOk()->getContent();
        $shelf = $this->between($html, 'aria-labelledby="mkShelfSaved"', '</section>');

        $this->assertMatchesRegularExpression('#<span class="mk-card__title">Night drives</span><span class="mk-card__channel">qa3ch_curator</span><span class="mk-card__meta">'.preg_quote(e(__('Saved :when', ['when' => now()->subDays(3)->diffForHumans()])), '#').'</span>#', $shelf);
        $this->assertStringNotContainsString(e(__('Public')), $shelf, 'not the owner\'s status line');
        $this->assertStringNotContainsString('Updated', $shelf);

        // the same card as on the Saved playlists page
        $page = $this->actingAs($member)->get(route('playlist.manage'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-card__channel">qa3ch_curator</span><span class="mk-card__meta">'.e(__('Saved :when', ['when' => now()->subDays(3)->diffForHumans()])).'</span>', $page);

        // the member's own playlist: no channel, its status and date as before
        $own = $this->between($html, 'aria-labelledby="mkShelfPlaylists"', '</section>');
        $this->assertStringNotContainsString('mk-card__channel', $own);
        $this->assertStringContainsString('<span class="mk-card__meta">'.e(__('Public'))."\u{00A0}· ", $own);
    }

    public function test_a_save_dated_ahead_of_the_clock_reads_now_at_the_latest(): void
    {
        // before 1.2.3 the Save button left added_at to the database's clock, which can run ahead of the site's
        $member = User::factory()->create();
        $this->saved($member, User::factory()->create(['username' => 'ahead_channel']), 'Saved ahead', now()->addHours(3));

        $shelf = $this->between($this->actingAs($member)->get(route('pages.library'))->assertOk()->getContent(), 'aria-labelledby="mkShelfSaved"', '</section>');

        $this->assertStringContainsString('<span class="mk-card__channel">ahead_channel</span>', $shelf);
        $this->assertStringNotContainsString('from now', $shelf);
        $this->assertStringNotContainsString('hours', $shelf);
    }

    public function test_the_shelf_loads_the_channels_in_one_query(): void
    {
        // everything made first (new accounts empty the site's caches), each page seen once to warm its caches
        $one = User::factory()->create();
        $four = User::factory()->create();
        $channels = User::factory()->count(4)->create();
        $this->saved($one, $channels[0], 'First', now()->subHour());
        foreach ($channels as $i => $channel) {
            $this->saved($four, $channel, 'Saved '.$i, now()->subMinutes(10 + $i));
        }
        foreach ([$one, $four] as $member) {
            $this->actingAs($member)->get(route('pages.library'))->assertOk();
        }

        [$countOne] = $this->queries(fn () => $this->actingAs($one)->get(route('pages.library'))->assertOk());
        [$countFour, $log] = $this->queries(fn () => $this->actingAs($four)->get(route('pages.library'))->assertOk());

        $this->assertSame($countOne, $countFour, 'no query per saved card');
        // the channels' names come from one query for the shelf, never one per card
        $ids = $channels->pluck('id')->map(fn ($id) => (int) $id)->all();
        $single = array_filter($log, fn (array $q): bool => (bool) preg_match('/from "users" where "users"\."id" = \?/', $q['query'])
            && array_intersect(array_map('intval', $q['bindings']), $ids) !== []);
        $this->assertSame([], array_values($single));
    }

    /** @return array{0: int, 1: array<int, array{query: string, bindings: array}>} */
    private function queries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [count($log), $log];
    }

    private function saved(User $member, User $channel, string $title, \DateTimeInterface $at): Playlist
    {
        $playlist = Playlist::query()->create(['uuid' => (string) Str::uuid(), 'title' => $title, 'user_id' => $channel->id,
            'status' => PlaylistStatus::PUBLIC, 'is_deletable' => true]);
        DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => $at]);

        return $playlist;
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'missing: '.$from);
        $end = strpos($html, $to, (int) $start);

        return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
    }
}
