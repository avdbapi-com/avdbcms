<?php

namespace Tests\Feature;

use App\Enums\CustomPlaylistType;
use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Http\Controllers\User\CustomPlaylistController;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Playlists\Types\LikedVideosPlaylist;
use App\Playlists\Types\WatchLaterPlaylist;
use App\Support\WatchHistory;
use App\Support\WatchLaterState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The member's library pages of the redesign (member design 6.11): History, Library, Watch later, Liked
 * videos, playlist pages, Saved playlists.
 */
class MemberLibraryPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['site.ads_enabled' => false, 'site.show_history' => true]);
        $this->member = User::factory()->create();
        $this->channel = User::factory()->create(['username' => 'library_channel']);
    }

    // ---------------------------------------------------------------------------------------------------------------- History

    public function test_the_history_api_pages_with_a_cursor_and_labels_the_days_on_the_server(): void
    {
        config(['site.per_page_videos' => 2]);
        $videos = $this->videos(5);
        foreach ($videos as $n => $video) {
            $this->watched($video, now()->subDays($n === 0 ? 0 : ($n < 3 ? 1 : 9))->setTime(10, $n));
        }

        $rows = [];
        $labels = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->actingAs($this->member, 'sanctum')
                ->getJson(route('me.history', array_filter(['cursor' => $cursor])))
                ->assertOk();
            foreach ($page->json('data') as $day) {
                $labels[$day['date']] = $day['label'];
                foreach ($day['views'] as $view) {
                    $rows[] = $view['video']['id'];
                }
            }
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null && ++$pages < 10);

        // QA channel-30: one answer held the whole history; now 2 rows a page, every row once, newest first
        $this->assertSame(3, $pages + 1);
        $this->assertSame([$videos[0]->id, $videos[2]->id, $videos[1]->id, $videos[4]->id, $videos[3]->id], $rows);
        $this->assertSame('Today', $labels[now()->format('Y-m-d')]);
        $this->assertSame('Yesterday', $labels[now()->subDay()->format('Y-m-d')]);
    }

    public function test_day_labels_follow_the_sites_time_zone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        $now = Carbon::create(2026, 10, 25, 1, 30, 0, 'America/New_York');
        Carbon::setTestNow($now);

        try {
            $this->assertSame('Today', WatchHistory::dayLabel('2026-10-25', $now));
            $this->assertSame('Yesterday', WatchHistory::dayLabel('2026-10-24', $now));
            // the weekday and the date, also within the last week (the mockup's "Thursday, 22 Oct"), in the admin's date
            // format (default M d, Y: "Oct 22"; the year only when it is not this year)
            $this->assertSame('Thursday, Oct 22', WatchHistory::dayLabel('2026-10-22', $now));
            $this->assertSame('Monday, Oct 5', WatchHistory::dayLabel('2026-10-05', $now));
            $this->assertSame('Sunday, Oct 5, 2025', WatchHistory::dayLabel('2025-10-05', $now));
            config(['site.date_format' => 'd/m/Y']);
            $this->assertSame('Thursday, 22 Oct', WatchHistory::dayLabel('2026-10-22', $now));
            $this->assertSame('Sunday, 05/10/2025', WatchHistory::dayLabel('2025-10-05', $now));
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set('UTC');
        }
    }

    public function test_the_history_page_prints_its_first_rows_and_shows_older_ones_on_request(): void
    {
        // the views below are 10-20 minutes old and must be today's: a clock at noon (in the first 20 minutes after
        // midnight in the site's time zone they were yesterday's and the test failed)
        $this->travelTo(now()->setTime(12, 0));
        $videos = $this->videos(WatchHistory::FIRST_PAGE + 3);
        foreach ($videos as $n => $video) {
            $this->watched($video, now()->subMinutes(10 + $n));
        }

        $html = $this->actingAs($this->member)->get(route('pages.history'))->assertOk()->getContent();

        // QA channel-3: the rows are in the page (no empty element that grows into skeletons and moves the page)
        $this->assertSame(WatchHistory::FIRST_PAGE, substr_count($html, '<li class="mk-lrow" data-history-row="'));
        $this->assertStringContainsString('data-history-more', $html);
        $this->assertStringNotContainsString('<user-history', $html);
        $this->assertStringNotContainsString('class="container-fluid my-3 px-0', $html);
        // QA channel-12 / 34 / 35: a page head, a quiet "Remove" that names its day, an explained dialog
        $this->assertStringContainsString('Clear all history', $html);
        $this->assertStringContainsString('aria-label="Remove Today from history"', $html);
        $this->assertStringContainsString('Your likes and playlists stay.', $html);

        $next = $this->actingAs($this->member)->getJson(route('me.history', ['format' => 'html', 'cursor' => $this->firstCursor()]))->assertOk();
        $this->assertSame(3, substr_count((string) $next->json('html'), 'data-history-row="'));
        $this->assertNull($next->json('next_cursor'));
    }

    public function test_an_empty_history_shows_the_empty_state_and_no_clear_button(): void
    {
        $this->actingAs($this->member)->get(route('pages.history'))
            ->assertOk()
            ->assertSee('Your history is empty')
            ->assertDontSee('mkHistoryClearAll"><', false)
            ->assertDontSee('<li class="mk-lrow" data-history-row', false);
    }

    public function test_history_search_keeps_only_matching_titles(): void
    {
        [$cabin, $road] = $this->videos(2, ['Tiny cabin build', 'Coast road at night']);
        $this->watched($cabin, now()->subHour());
        $this->watched($road, now()->subMinutes(30));

        $this->actingAs($this->member)->get(route('pages.history', ['q' => 'cabin']))
            ->assertOk()
            ->assertSee('Tiny cabin build')
            ->assertDontSee('Coast road at night');

        $this->actingAs($this->member)->get(route('pages.history', ['q' => 'nothing like this']))
            ->assertOk()
            ->assertSee('No videos match your search');
    }

    public function test_clear_day_takes_a_plain_date_only(): void
    {
        $this->actingAs($this->member, 'sanctum')->postJson(route('me.clear-day'), ['date' => '2026-10-25T00:00:00Z'])->assertStatus(422);
        $this->actingAs($this->member, 'sanctum')->postJson(route('me.clear-day'), ['date' => now()->format('Y-m-d')])->assertNoContent();
    }

    // ---------------------------------------------------------------------------------------------------------------- Library

    public function test_the_library_shows_six_cards_a_shelf_with_the_counts(): void
    {
        $videos = $this->videos(8);
        foreach ($videos as $n => $video) {
            $this->watched($video, now()->subMinutes(5 + $n));
        }
        $later = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        foreach ($videos as $n => $video) {
            $later->videos()->attach($video->id, ['position' => $n + 1, 'added_at' => now()]);
        }
        DB::table('subscriptions')->insert(['user_id' => $this->channel->id, 'subscriber_id' => $this->member->id, 'subscribe_at' => now(), 'read_at' => now()]);

        $html = $this->actingAs($this->member)->get(route('pages.library'))->assertOk()->getContent();

        $history = $this->between($html, 'mkShelfHistory', 'mkShelfLater');
        $this->assertSame(6, substr_count($history, '<a class="mk-card"'));   // 6.11: six items a shelf
        $this->assertStringContainsString('<span class="mk-count">8</span>', $history);
        $this->assertSame(6, substr_count($this->between($html, 'mkShelfLater', 'mkShelfLiked'), '<a class="mk-card"'));
        $this->assertStringContainsString('library_channel', $this->between($html, 'mkShelfSubs', '</section>'));
    }

    public function test_a_new_member_gets_one_empty_state_and_history_off_hides_its_shelf(): void
    {
        $this->actingAs($this->member)->get(route('pages.library'))->assertOk()->assertSee('Your library is empty');

        $video = $this->videos(1)[0];
        $this->watched($video, now()->subMinute());
        DB::table('subscriptions')->insert(['user_id' => $this->channel->id, 'subscriber_id' => $this->member->id, 'subscribe_at' => now(), 'read_at' => now()]);

        config(['site.show_history' => false]);
        $this->actingAs($this->member)->get(route('pages.library'))->assertOk()->assertDontSee('mkShelfHistory', false)->assertSee('mkShelfSubs', false);
    }

    public function test_history_off_closes_the_page_and_its_api_but_a_member_can_still_remove_views(): void
    {
        // member design 6.0 (T): the admin's "Watch history" (show_history) off. The page sends the member
        // home with the site's sentence (PageController, as before); the API no longer hands the history out (it did:
        // 200 with every row); removing rows still works, so nothing a member watched is kept against their will.
        $video = $this->videos(1)[0];
        $this->watched($video, now()->subHour());
        $view = \App\Models\View::query()->where('video_id', $video->id)->firstOrFail();
        config(['site.show_history' => false]);

        $this->actingAs($this->member)->get(route('pages.history'))
            ->assertRedirect(route('pages.home'))
            ->assertSessionHas('info', 'Watch history is currently unavailable.');
        $this->actingAs($this->member, 'sanctum')->getJson(route('me.history'))
            ->assertForbidden()
            ->assertJson(['message' => 'Watch history is currently unavailable.'])
            ->assertJsonMissingPath('data');
        $this->actingAs($this->member, 'sanctum')->getJson(route('me.history', ['format' => 'html']))->assertForbidden();
        $this->actingAs($this->member)->get(route('pages.library'))->assertOk()->assertDontSee('mkShelfHistory', false);

        $this->actingAs($this->member, 'sanctum')->deleteJson(route('me.clear', $view))->assertNoContent();
        $this->assertSoftDeleted('views', ['id' => $view->id]);

        // on again: the page and its API answer as before
        config(['site.show_history' => true]);
        $this->actingAs($this->member)->get(route('pages.history'))->assertOk();
        $this->actingAs($this->member, 'sanctum')->getJson(route('me.history'))->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_library_is_for_signed_in_members_only(): void
    {
        $this->get(route('pages.library'))->assertRedirect();
    }

    // ---------------------------------------------------------------------------------------------- Watch later, Liked videos

    public function test_liked_videos_has_its_translated_name_and_rows_without_an_inner_scroll_box(): void
    {
        Playlist::factory()->for($this->member)->create(['title' => LikedVideosPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        foreach ($this->videos(2) as $n => $video) {
            DB::table('interactions')->insert(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()->subMinutes($n)]);
        }

        // QA channel-27: the stored "Liked Videos" was printed as it is (no language file has it)
        $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::LIKED_VIDEOS))
            ->assertOk()
            ->assertSee('<h1 class="mk-head__title">Liked videos</h1>', false)
            ->assertDontSee('Liked Videos')
            ->assertDontSee('avd-playlist-video-list', false)   // QA channel-6: the scroll box
            ->assertSee('mk-lrows mk-lrows--numbered', false);
    }

    public function test_remove_watched_asks_first_and_can_be_undone_once_in_its_old_places(): void
    {
        $videos = $this->videos(4);
        $later = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false, 'sort' => PlaylistSort::MANUAL]);
        foreach ($videos as $n => $video) {
            $later->videos()->attach($video->id, ['position' => $n + 1, 'added_at' => now()->subDays(4 - $n)]);
        }
        $this->watched($videos[1], now()->subHour());
        $this->watched($videos[3], now()->subHour());

        $page = $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))->assertOk();
        // QA channel-27: one click removed 4 of 5 videos with no question; now a dialog that says how many go
        $page->assertSee('Remove watched videos?')->assertSee('Remove 2 videos')->assertSee('2 of the 4 videos in Watch later are in your history.');

        $this->actingAs($this->member)->from(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))
            ->post(route('custom-playlist.clear-watched-videos'))
            ->assertRedirect();
        $this->assertSame([$videos[0]->id, $videos[2]->id], $later->videos()->orderBy('playlist_has_videos.position')->pluck('videos.id')->all());

        $saved = session(CustomPlaylistController::UNDO_KEY);
        $this->assertIsArray($saved);
        $undoPage = $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))->assertOk();
        $undoPage->assertSee('name="undo" value="' . $saved['token'] . '"', false);

        // a wrong token does nothing
        $this->actingAs($this->member)->post(route('custom-playlist.clear-watched-videos'), ['undo' => Str::random(24)])->assertSessionHas('warning');
        $this->assertSame(2, $later->videos()->count());

        // the right one brings both back to their old places, once
        $this->actingAs($this->member)->post(route('custom-playlist.clear-watched-videos'), ['undo' => $saved['token']])->assertSessionHas('success');
        $this->assertSame(array_map(fn (Video $video) => $video->id, $videos), $later->videos()->orderBy('playlist_has_videos.position')->pluck('videos.id')->all());
        $this->actingAs($this->member)->post(route('custom-playlist.clear-watched-videos'), ['undo' => $saved['token']])->assertSessionHas('warning');
        $this->assertSame(4, $later->videos()->count());
    }

    public function test_an_undo_is_kept_for_ten_minutes_only(): void
    {
        $videos = $this->videos(2);
        $later = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        $later->videos()->attach($videos[0]->id, ['position' => 1, 'added_at' => now()]);
        $this->watched($videos[0], now()->subHour());

        $this->actingAs($this->member)->post(route('custom-playlist.clear-watched-videos'))->assertRedirect();
        $token = session(CustomPlaylistController::UNDO_KEY)['token'];

        Carbon::setTestNow(now()->addMinutes(CustomPlaylistController::UNDO_MINUTES + 1));
        try {
            $this->actingAs($this->member)->post(route('custom-playlist.clear-watched-videos'), ['undo' => $token])->assertSessionHas('warning');
            $this->assertSame(0, $later->videos()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_watch_later_pages_its_rows(): void
    {
        $videos = $this->videos(CustomPlaylistController::ROWS_PER_PAGE + 2);
        $later = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false, 'sort' => PlaylistSort::MANUAL]);
        foreach ($videos as $n => $video) {
            $later->videos()->attach($video->id, ['position' => $n + 1, 'added_at' => now()]);
        }

        // the page prints its first rows (R1-069: within the page-content budget, as History); "Show more" adds the rest
        $html = $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))->assertOk()->getContent();
        $this->assertSame(CustomPlaylistController::FIRST_PAGE, substr_count($html, 'data-playlist-row="'));
        $this->assertStringContainsString('data-next-page="2"', $html);

        // "Show more": the rows after the ones on screen, ROWS_PER_PAGE at a time
        $url = route('custom-playlist.show', CustomPlaylistType::WATCH_LATER);
        $next = $this->actingAs($this->member)->getJson($url . '?part=rows&page=2&shown=' . CustomPlaylistController::FIRST_PAGE)->assertOk();
        $this->assertSame(count($videos) - CustomPlaylistController::FIRST_PAGE, substr_count((string) $next->json('html'), 'data-playlist-row="'));
        $this->assertStringContainsString('data-playlist-row="' . $videos[CustomPlaylistController::FIRST_PAGE]->id . '"', (string) $next->json('html'));
        $this->assertNull($next->json('next_page'));
        // a page number alone counts the rows of the pages before it as on screen
        $byNumber = $this->actingAs($this->member)->getJson($url . '?part=rows&page=2')->assertOk();
        $this->assertSame($next->json('html'), $byNumber->json('html'));
    }

    // ------------------------------------------------------------------------------------------------- Playlist pages, saved

    public function test_a_public_playlist_page_has_an_h1_kit_actions_and_paged_rows_without_hidden_videos(): void
    {
        $owner = $this->channel;
        $playlist = Playlist::factory()->for($owner)->create(['title' => 'Coast drives', 'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL]);
        // the first page, one full "Show more" and one row after it
        $videos = $this->videos(\App\Http\Controllers\PlaylistController::FIRST_PAGE + \App\Http\Controllers\PlaylistController::ROWS_PER_PAGE + 1);
        foreach ($videos as $n => $video) {
            $playlist->videos()->attach($video->id, ['position' => $n + 1]);
        }
        $private = Video::factory()->for($owner)->withStatus(VideoStatus::PRIVATE)->create(['title' => 'Owner only clip', 'uploaded_at' => now()]);
        $playlist->videos()->attach($private->id, ['position' => 999]);

        $html = $this->actingAs($this->member)->get($playlist->route)->assertOk()->getContent();

        // QA channel-26: an h1, one primary (Play all), Save, Share; no btn-danger / btn-info / bg-success
        $this->assertStringContainsString('<h1 class="mk-head__title">Coast drives</h1>', $html);
        $this->assertStringContainsString('Play all', $html);
        $this->assertStringContainsString('data-favorite-url', $html);
        $this->assertStringContainsString('data-share-dialog="#mkSharePlaylist"', $html);
        foreach (['btn-danger', 'btn-info', 'badge bg-success'] as $old) {
            $this->assertStringNotContainsString($old, $this->between($html, 'mk-playlist', 'mkSharePlaylistTitle'));
        }
        // the page prints its first rows (R1-069, as History and Watch later); "Show more" adds the rest after them
        $this->assertSame(\App\Http\Controllers\PlaylistController::FIRST_PAGE, substr_count($html, 'data-playlist-row="'));
        $this->assertStringContainsString('data-next-page="2"', $html);
        $this->assertStringNotContainsString('Owner only clip', $html);

        $next = $this->actingAs($this->member)->getJson($playlist->route . '?part=rows&page=2&shown=' . \App\Http\Controllers\PlaylistController::FIRST_PAGE)->assertOk();
        $this->assertSame(\App\Http\Controllers\PlaylistController::ROWS_PER_PAGE, substr_count((string) $next->json('html'), 'data-playlist-row="'));
        $this->assertSame(2, $next->json('next_page'));
        $last = $this->actingAs($this->member)->getJson($playlist->route . '?part=rows&page=3&shown=' . (\App\Http\Controllers\PlaylistController::FIRST_PAGE + \App\Http\Controllers\PlaylistController::ROWS_PER_PAGE))->assertOk();
        $this->assertSame(count($videos) - \App\Http\Controllers\PlaylistController::FIRST_PAGE - \App\Http\Controllers\PlaylistController::ROWS_PER_PAGE, substr_count((string) $last->json('html'), 'data-playlist-row="'));
        $this->assertStringNotContainsString('Owner only clip', (string) $last->json('html'));
        $this->assertNull($last->json('next_page'));
        // a page number alone counts the rows of the pages before it as on screen
        $this->assertSame($next->json('html'), $this->actingAs($this->member)->getJson($playlist->route . '?part=rows&page=2')->json('html'));

        // the owner sees Edit as a quiet button and keeps their private video
        $this->actingAs($owner)->get($playlist->route)->assertOk()->assertSee('Edit playlist');
    }

    public function test_saved_playlists_has_a_head_when_empty_and_hides_playlists_made_private_since(): void
    {
        $this->actingAs($this->member)->get(route('playlist.manage'))
            ->assertOk()
            ->assertSee('<h1 class="mk-head__title">Saved playlists</h1>', false)   // QA channel-28
            ->assertSee('No saved playlists yet');

        $video = $this->videos(1)[0];
        $kept = Playlist::factory()->for($this->channel)->create(['title' => 'Still public list', 'status' => PlaylistStatus::PUBLIC]);
        $gone = Playlist::factory()->for($this->channel)->create(['title' => 'Now private list', 'status' => PlaylistStatus::PRIVATE]);
        foreach ([$kept, $gone] as $playlist) {
            $playlist->videos()->attach($video->id, ['position' => 1]);
            DB::table('favorites_playlist')->insert(['user_id' => $this->member->id, 'playlist_id' => $playlist->id, 'added_at' => now()]);
        }

        $this->actingAs($this->member)->get(route('playlist.manage'))
            ->assertOk()
            ->assertSee('Still public list')
            ->assertDontSee('Now private list');
    }

    // ------------------------------------------------------------------------------------------- review fixes

    public function test_removing_history_rows_answers_what_is_left_for_the_clear_all_question(): void
    {
        $videos = $this->videos(3);
        foreach ($videos as $n => $video) {
            $this->watched($video, now()->subDays($n)->setTime(10, 0));
        }
        $view = \App\Models\View::query()->where('video_id', $videos[0]->id)->firstOrFail();

        // the mobile app and 1.2.2 pages: 204 as before
        $this->actingAs($this->member, 'sanctum')->deleteJson(route('me.clear', $view))->assertNoContent();

        // the History page asks what is left: the "Clear all history?" sentence counts it (QA: "All 12 videos" after 8 went)
        $this->actingAs($this->member, 'sanctum')
            ->postJson(route('me.clear-day') . '?count=1', ['date' => now()->subDay()->format('Y-m-d')])
            ->assertOk()
            ->assertJson(['remaining' => 1, 'clear_all_text' => '1 video leaves your history on every device. Your likes and playlists stay.']);
    }

    public function test_the_day_question_names_the_day_and_no_day_remove_is_offered_while_searching(): void
    {
        // the views below are 20-30 minutes old and must be today's: a clock at noon (in the first 30 minutes after
        // midnight in the site's time zone they were yesterday's and the test failed)
        $this->travelTo(now()->setTime(12, 0));
        $this->assertSame('Remove today\'s history?', WatchHistory::removeDayQuestion(now()->format('Y-m-d')));
        $this->assertSame('Remove yesterday\'s history?', WatchHistory::removeDayQuestion(now()->subDay()->format('Y-m-d')));
        $old = now()->subDays(9);
        $this->assertSame('Remove the history of ' . WatchHistory::dayLabel($old->format('Y-m-d')) . '?', WatchHistory::removeDayQuestion($old->format('Y-m-d')));

        [$cabin, $road] = $this->videos(2, ['Tiny cabin build', 'Coast road at night']);
        $this->watched($cabin, now()->subMinutes(30));
        $this->watched($road, now()->subMinutes(20));

        $this->actingAs($this->member)->get(route('pages.history'))
            ->assertOk()
            ->assertSee('data-history-remove-day=', false)
            ->assertSee('data-day-question="Remove today&#039;s history?"', false);

        // with a search a day lists only its matching rows: its Remove (which takes the whole day) is not offered (QA)
        $this->actingAs($this->member)->get(route('pages.history', ['q' => 'cabin']))
            ->assertOk()
            ->assertSee('Tiny cabin build')
            ->assertDontSee('data-history-remove-day=', false);
        $more = $this->actingAs($this->member)->getJson(route('me.history', ['format' => 'html', 'q' => 'cabin']))->assertOk();
        $this->assertStringNotContainsString('data-history-remove-day=', (string) $more->json('html'));
    }

    public function test_pause_history_is_offered_once_the_site_has_its_switch_and_route(): void
    {
        $member = $this->member->fresh();
        $this->assertTrue(WatchHistory::pauseReady($member));
        $video = $this->videos(1)[0];
        $this->watched($video, now()->subMinute());

        // without the route the page offers no pause
        if (! \Illuminate\Support\Facades\Route::has(WatchHistory::PAUSE_ROUTE)) {
            $this->actingAs($member)->get(route('pages.history'))->assertOk()->assertDontSee('data-pause-url=', false)->assertDontSee('Pause history instead');
            \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum'])->prefix('api')
                ->post('/me/history/pause', [\App\Http\Controllers\Api\Private\HistoryController::class, 'pause'])
                ->name(WatchHistory::PAUSE_ROUTE);
            \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        }

        $on = $this->actingAs($member)->get(route('pages.history'))
            ->assertOk()
            ->assertSee('data-history-pause', false)
            ->assertSee('Pause history')
            ->assertSee('Pause history instead')
            ->getContent();
        $this->assertStringContainsString('hidden', $this->between($on, 'data-history-paused-note', '>'));

        $this->actingAs($member, 'sanctum')->postJson(route(WatchHistory::PAUSE_ROUTE), ['paused' => true])
            ->assertOk()
            ->assertJson(['paused' => true, 'message' => 'History paused']);
        $this->assertNotNull(DB::table('users')->where('id', $member->id)->value('history_paused_at'));
        $this->assertTrue(WatchHistory::isPaused($member->fresh()));

        $paused = $this->actingAs($member->fresh())->get(route('pages.history'))
            ->assertOk()
            ->assertSee('Turn history back on')
            ->assertSee('Your history is paused. Videos you watch now are not added to it.')
            ->getContent();
        $this->assertStringNotContainsString('hidden', $this->between($paused, 'data-history-paused-note', '>'));

        $this->actingAs($member->fresh(), 'sanctum')->postJson(route(WatchHistory::PAUSE_ROUTE), ['paused' => false])
            ->assertOk()
            ->assertJson(['paused' => false]);
        $this->assertNull(DB::table('users')->where('id', $member->id)->value('history_paused_at'));
        $this->actingAs($member->fresh(), 'sanctum')->postJson(route(WatchHistory::PAUSE_ROUTE), ['paused' => 'maybe'])->assertStatus(422);
    }

    public function test_a_watch_later_state_is_kept_whatever_the_page_showed(): void
    {
        $video = $this->videos(1)[0];
        $toggle = route('custom-playlist.watch-later.toggle', $video->id);
        $later = fn () => DB::table('playlist_has_videos')
            ->join('playlists', 'playlists.id', '=', 'playlist_has_videos.playlist_id')
            ->where('playlists.user_id', $this->member->id)->where('playlists.title', WatchLaterPlaylist::getName())
            ->where('playlist_has_videos.video_id', $video->id)->count();

        $this->actingAs($this->member)->postJson($toggle, ['state' => 'on'])->assertOk()->assertJson(['status' => 'added', 'message' => 'Saved to Watch later']);
        $this->actingAs($this->member)->postJson($toggle, ['state' => 'on'])->assertOk()->assertJson(['status' => 'added']);
        $this->assertSame(1, $later());

        // a second "Remove" (the row still on screen in another tab) keeps it out (QA: it put the video back)
        $this->actingAs($this->member)->postJson($toggle, ['state' => 'off'])->assertOk()->assertJson(['status' => 'removed', 'message' => 'Removed from Watch later']);
        $this->actingAs($this->member)->postJson($toggle, ['state' => 'off'])->assertOk()->assertJson(['status' => 'removed']);
        $this->assertSame(0, $later());

        // the watch page and the site's cards keep the toggle
        $this->actingAs($this->member)->postJson($toggle)->assertOk()->assertJson(['status' => 'added']);
        $this->actingAs($this->member)->postJson($toggle)->assertOk()->assertJson(['status' => 'removed']);
    }

    public function test_watch_later_answers_its_new_head_after_a_removal_and_reads_whole_hours(): void
    {
        $videos = $this->videos(3);
        $later = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false, 'sort' => PlaylistSort::MANUAL]);
        foreach ($videos as $n => $video) {
            DB::table('videos')->where('id', $video->id)->update(['duration' => 1800]);
            $later->videos()->attach($video->id, ['position' => $n + 1, 'added_at' => now()]);
        }
        $this->watched($videos[2], now()->subHour());

        // 3 x 30 min = 1 h 30 min; 2 x 30 min = "1 h" (QA: "2 h 0 min"); the middle dot has a no-break space before it
        // (member design 7: a wrapped line never starts with the dot)
        $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::WATCH_LATER))
            ->assertOk()
            ->assertSee("3 videos\u{00A0}· 1 h 30 min. Private: only you see this list.")
            ->assertDontSee('3 videos · 1 h 30 min')
            ->assertSee('data-rows-empty', false)
            ->assertSee('data-watch-later-row', false);

        $answer = $this->actingAs($this->member)->postJson(route('custom-playlist.watch-later.toggle', $videos[0]->id) . '?summary=1', ['state' => 'off'])->assertOk();
        $answer->assertJson([
            'status' => 'removed',
            'count' => 2,
            'summary' => "2 videos\u{00A0}· 1 h. Private: only you see this list.",
            'watched' => 1,
            'watched_texts' => ['confirm' => 'Remove 1 video'],
        ]);
        $this->assertStringContainsString('1 of the 2 videos in Watch later is in your history.', (string) $answer->json('watched_texts.text'));
    }

    public function test_liked_rows_offer_what_the_watch_later_state_allows_and_show_more_follows_the_rows_on_screen(): void
    {
        Playlist::factory()->for($this->member)->create(['title' => LikedVideosPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        $videos = $this->videos(CustomPlaylistController::ROWS_PER_PAGE + 3);
        foreach ($videos as $n => $video) {
            DB::table('interactions')->insert(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()->subMinutes($n)]);
        }
        $later = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        $later->videos()->attach($videos[0]->id, ['position' => 1, 'added_at' => now()]);

        $html = $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::LIKED_VIDEOS))->assertOk()->getContent();
        $first = $this->between($html, 'data-playlist-row="' . $videos[0]->id . '"', '</li>');
        $second = $this->between($html, 'data-playlist-row="' . $videos[1]->id . '"', '</li>');
        // QA: the item always said "Save to Watch later" and took a saved video out
        $this->assertStringContainsString('data-watch-later-state="on"', $first);
        $this->assertStringContainsString('Remove from Watch later', $first);
        $this->assertStringContainsString('data-watch-later-state="off"', $second);
        $this->assertStringContainsString('Save to Watch later', $second);

        // "Show more" after 2 rows of the first page left the screen: the rows after the ones on screen
        $next = $this->actingAs($this->member)->getJson(route('custom-playlist.show', CustomPlaylistType::LIKED_VIDEOS) . '?part=rows&page=2&shown=' . (CustomPlaylistController::ROWS_PER_PAGE - 2))->assertOk();
        $this->assertSame(5, substr_count((string) $next->json('html'), 'data-playlist-row="'));
        $this->assertStringContainsString('data-playlist-row="' . $videos[CustomPlaylistController::ROWS_PER_PAGE - 2]->id . '"', (string) $next->json('html'));
        $this->assertNull($next->json('next_page'));
    }

    public function test_the_rows_read_the_watch_later_list_its_button_saves_to(): void
    {
        Playlist::factory()->for($this->member)->create(['title' => LikedVideosPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        $video = $this->videos(1)[0];
        DB::table('interactions')->insert(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $video->id, 'status' => 1, 'perform_at' => now()]);
        // a deleted Watch later list (soft-deleted, older id) still holds the video; the list the button saves to (the
        // live one, App\Playlists\CustomPlaylist::initPlaylist) does not
        $deleted = Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);
        DB::table('playlist_has_videos')->insert(['playlist_id' => $deleted->id, 'video_id' => $video->id, 'position' => 1, 'added_at' => now()]);
        DB::table('playlists')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        Playlist::factory()->for($this->member)->create(['title' => WatchLaterPlaylist::getName(), 'status' => PlaylistStatus::PRIVATE, 'is_deletable' => false]);

        $this->assertSame([], WatchLaterState::ids([$video], $this->member));
        $html = $this->actingAs($this->member)->get(route('custom-playlist.show', CustomPlaylistType::LIKED_VIDEOS))->assertOk()->getContent();
        $row = $this->between($html, 'data-playlist-row="' . $video->id . '"', '</li>');
        $this->assertStringContainsString('data-watch-later-state="off"', $row);
        $this->assertStringContainsString('Save to Watch later', $row);

        // and a save from that row lands in the live list, so the row then offers the removal
        $this->actingAs($this->member)->postJson(route('custom-playlist.watch-later.toggle', $video->id), ['state' => 'on'])->assertOk()->assertJson(['status' => 'added']);
        $this->assertSame([(int) $video->id], WatchLaterState::ids([$video], $this->member));
    }

    public function test_saved_playlists_never_read_saved_in_the_future_and_playlist_dates_follow_the_date_format(): void
    {
        $video = $this->videos(1)[0];
        $playlist = Playlist::factory()->for($this->channel)->create(['title' => 'Late list', 'status' => PlaylistStatus::PUBLIC, 'updated_at' => Carbon::create(2026, 10, 20, 12)]);
        $playlist->videos()->attach($video->id, ['position' => 1]);
        // a row dated by a database clock six hours ahead of the site (QA: "Saved 5 hours from now")
        DB::table('favorites_playlist')->insert(['user_id' => $this->member->id, 'playlist_id' => $playlist->id, 'added_at' => now()->addHours(6)]);

        $this->actingAs($this->member)->get(route('playlist.manage'))->assertOk()->assertSee('Late list')->assertDontSee('from now');

        // 6.0: dates in the admin's "Date format" (default M d, Y)
        $this->actingAs($this->member)->get($playlist->route)->assertOk()->assertSee('Updated Oct 20, 2026');
        config(['site.date_format' => 'd.m.Y']);
        $this->actingAs($this->member)->get($playlist->route)->assertOk()->assertSee('Updated 20.10.2026');
    }

    public function test_history_rows_load_what_the_video_link_format_prints_with_them(): void
    {
        config(['site.video_url_pattern' => '{category}/{slug}-{uuid}']);
        $category = \App\Models\Category::query()->create(['title' => 'Drives', 'slug' => 'drives']);
        foreach ($this->videos(6) as $n => $video) {
            DB::table('videos')->where('id', $video->id)->update(['category_id' => $category->id]);
            $this->watched($video, now()->subMinutes($n + 1));
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->actingAs($this->member, 'sanctum')->getJson(route('me.history'))->assertOk();
        $this->actingAs($this->member)->get(route('pages.history'))->assertOk();

        // the categories come with the rows (one query per list), never one per row (QA: 15 more queries on
        // /api/me/history with a {category} link)
        $perRow = array_filter($queries, fn (string $sql) => (bool) preg_match('/from [`"]categories[`"] where [`"]categories[`"]\.[`"]id[`"] = \?/', $sql));
        $this->assertSame([], array_values($perRow));
    }

    // ---------------------------------------------------------------------------------------------------------------- helpers

    /**
     * Public videos of the test channel, newest first in the returned order's index (0 = newest).
     *
     * @return list<Video>
     */
    private function videos(int $count, array $titles = []): array
    {
        $videos = [];
        foreach (range(0, $count - 1) as $n) {
            $videos[] = Video::factory()->for($this->channel)->withStatus(VideoStatus::PUBLIC)->create([
                'title' => $titles[$n] ?? ('Library clip ' . ($n + 1)),
                'published_at' => now()->subDays(20 + $n),
                'uploaded_at' => now()->subDays(20 + $n),
            ]);
        }

        return $videos;
    }

    private function watched(Video $video, Carbon $at): void
    {
        DB::table('views')->insert(['video_id' => $video->id, 'user_id' => $this->member->id, 'ip' => '127.0.0.1', 'view_at' => $at]);
    }

    private function firstCursor(): string
    {
        return (string) WatchHistory::page($this->member, null, WatchHistory::FIRST_PAGE)->nextCursor()?->encode();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = (int) strpos($html, $from);
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }
}
