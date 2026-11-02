<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Services\ViewService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 1.2.3, the review of the studio: each test fails on the code before the fix and
 * passes after the fix.
 *  - a view-only demo member is told why nothing changed on every studio page (6.18, section 8);
 *  - the dashboard's cache: one entry per member that a counted view does not renew, plain values only (no member
 *    rows, no e-mail, no password hash), never a new file per view (studio-29);
 *  - the playlist editor saves what the member changed, never a snapshot: a video saved to the playlist elsewhere in
 *    the meantime stays, removals and the order apply, the list travels in one field (max_input_vars), the ids are
 *    checked in a few queries, and a refused save keeps the member's list (studio-4, principle 7);
 *  - Video details that come back with errors keep their "Unsaved changes"; a description keeps the browser's line
 *    breaks as one character (the counter, maxlength = max);
 *  - the row menu offers Pin / Unpin exactly as the policy allows (no 403 page);
 *  - Analytics: an array period, a video without any daily figure (note only), signed numbers isolated for Arabic;
 *  - the comments page loads the video link relations once; bulk "Make public" announces the videos in one IndexNow
 *    call; a page past the end of a list goes to its last page; tips list only what there is to do; Save and Pin
 *    answer with a toast (6.4).
 */
class StudioReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        $this->member = User::factory()->create(['username' => 'nightdrive_tv']);
        $this->viewer = User::factory()->create(['username' => 'qa_studio_viewer']);
    }

    // ------------------------------------------------------------------------------------------ view-only refusals

    public function test_a_view_only_member_is_told_why_nothing_changed_on_every_studio_page(): void
    {
        $demo = User::factory()->create(['username' => 'demo_viewer']);
        $demo->forceFill(['is_view_only' => 1])->save();
        $video = $this->video($demo, ['title' => 'Demo drive']);
        $other = $this->video($demo, ['title' => 'Demo walk']);
        $comment = $this->comment($video, $this->viewer, 'A comment on the demo video.');
        $playlist = $this->playlist($demo, [$video->id]);
        $reported = $this->video($this->viewer, ['title' => 'Loud city traffic']);
        $report = Report::query()->create(['user_id' => $demo->id, 'reportable_type' => Video::class, 'reportable_id' => $reported->id, 'reason' => ReportReason::MISINFORMATION, 'comment' => '', 'status' => ReportStatus::PENDING]);

        $cases = [
            'delete a video' => [route('user.videos.index'), 'delete', route('user.videos.destroy', $video), []],
            'bulk delete' => [route('user.videos.index'), 'delete', route('user.videos.mass-delete'), ['ids' => [$video->id, $other->id]]],
            'bulk visibility' => [route('user.videos.index'), 'post', route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'private']],
            'delete a playlist' => [route('user.playlists.index'), 'delete', route('user.playlists.destroy', $playlist), []],
            'remove a comment' => [route('user.comments.index'), 'delete', route('user.comments.destroy', $comment), []],
            'cancel a report' => [route('user.reports.index'), 'post', route('user.reports.cancel', $report), []],
            'remove from the dashboard' => [route('user.index'), 'delete', route('user.comments.destroy', $comment), []],
        ];

        foreach ($cases as $name => [$from, $method, $url, $data]) {
            // a person's click (the guard sends a script that repeats a refused address Home instead)
            $html = $this->actingAs($demo)->from($from)->withHeader('Sec-Fetch-User', '?1')->followingRedirects()->{$method}($url, $data)->assertOk()->getContent();

            $this->assertStringContainsString('Not saved', $html, $name.': the page must say the change was not saved');
            $this->assertMatchesRegularExpression('/<div class="mk-alert"[^>]*data-mk-focus/', $html, $name.': the refusal is a focused alert');
        }

        $this->assertDatabaseHas('videos', ['id' => $video->id]);
        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
        $this->assertSame(ReportStatus::PENDING, $report->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------ dashboard cache

    public function test_one_counted_view_between_two_dashboard_visits_keeps_the_cache(): void
    {
        $videos = $this->dashboardData();

        $this->actingAs($this->member)->get(route('user.index'))->assertOk();
        $this->travel(1)->seconds();
        $this->countView($videos[0]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertSee('Probe video 0');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $queries, 'after one counted view the returning member\'s dashboard ran '.$queries.' queries');
    }

    public function test_the_dashboard_cache_holds_plain_values_and_no_member_rows(): void
    {
        $this->dashboardData();

        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertSee('Nice one');

        $blob = '';
        foreach ((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue(Cache::store()->getStore()) as $key => $entry) {
            if (str_starts_with((string) $key, 'avd:studio:dash:')) {
                $blob .= serialize($entry['value']);
            }
        }

        $this->assertNotSame('', $blob, 'the dashboard is cached');
        foreach ([$this->member, $this->viewer] as $user) {
            $row = DB::table('users')->where('id', $user->id)->first();
            $this->assertStringNotContainsString((string) $row->email, $blob, 'no e-mail address in the dashboard cache');
            $this->assertStringNotContainsString((string) $row->password, $blob, 'no password hash in the dashboard cache');
        }
        $this->assertStringNotContainsString('O:', $blob, 'no serialized object (Eloquent models) in the dashboard cache');
    }

    public function test_the_file_cache_keeps_one_dashboard_entry_per_member(): void
    {
        $dir = storage_path('framework/testing/m1-dash-cache-'.uniqid());
        File::ensureDirectoryExists($dir);
        config(['cache.stores.m1file' => ['driver' => 'file', 'path' => $dir, 'lock_path' => $dir], 'cache.default' => 'm1file']);

        try {
            $videos = $this->dashboardData();
            $this->actingAs($this->member)->get(route('user.index'))->assertOk();
            $added = [];

            // a counted view (it writes its own entries: the view counter's), then the dashboard: the dashboard itself
            // must not write a new file on any of these visits (its entries are overwritten in place)
            for ($visit = 0; $visit < 4; $visit++) {
                $this->travel(1)->seconds();
                $this->countView($videos[$visit]);
                $before = count(File::allFiles($dir));
                $this->actingAs($this->member)->get(route('user.index'))->assertOk();
                $added[] = count(File::allFiles($dir)) - $before;
            }

            $this->assertSame([0, 0, 0, 0], $added, 'the dashboard wrote new cache files on visits after a view: '.implode(', ', $added));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    // ------------------------------------------------------------------------------------------ playlist editor

    public function test_saving_the_editor_keeps_a_video_added_elsewhere_meanwhile(): void
    {
        $playlist = $this->playlist($this->member, $ids = [$this->video($this->member)->id, $this->video($this->member)->id, $this->video($this->member)->id]);

        $form = $this->editorForm($playlist);

        // meanwhile, in another tab, the member saves another video to this playlist from its watch page
        $elsewhere = $this->video($this->viewer);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $elsewhere->id, 'playlist_id' => $playlist->id])->assertStatus(201);

        // the editor saves (only its title changed)
        $this->actingAs($this->member, 'web')->put(route('user.playlists.update', $playlist), array_merge($form, ['title' => 'Renamed']))->assertSessionHasNoErrors();

        $this->assertSame([...$ids, $elsewhere->id], $this->order($playlist), 'the video saved from another page stays, after the editor\'s list');
        $this->assertSame('Renamed', $playlist->fresh()->title);
    }

    public function test_saving_the_editor_applies_the_members_removals_and_order_and_merges_other_changes(): void
    {
        [$a, $b, $c, $d] = array_map(fn () => $this->video($this->member)->id, range(1, 4));
        $playlist = $this->playlist($this->member, [$a, $b, $c, $d]);
        $form = $this->editorForm($playlist);
        $this->assertSame(implode(',', [$a, $b, $c, $d]), $form['videos_loaded'] ?? null, 'the editor names the list it loaded');

        // elsewhere: $e is added, $b is taken out (the watch page's Save menu toggles)
        $e = $this->video($this->viewer)->id;
        $this->actingAs($this->member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $e, 'playlist_id' => $playlist->id])->assertStatus(201);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/playlists/save', ['video_id' => $b, 'playlist_id' => $playlist->id])->assertStatus(201);
        $this->assertFalse(DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->where('video_id', $b)->exists());

        // the editor: $c removed, $f added from the search, the rest reordered
        $f = $this->video($this->member)->id;
        $this->actingAs($this->member, 'web')
            ->put(route('user.playlists.update', $playlist), array_merge($form, ['videos_order' => implode(',', [$d, $b, $a, $f])]))
            ->assertSessionHasNoErrors();

        // $c removed here, $b stays removed (removed elsewhere, untouched here), $e kept at the end
        $this->assertSame([$d, $a, $f, $e], $this->order($playlist));
    }

    public function test_the_editor_posts_its_list_in_one_field_and_a_long_save_runs_few_queries(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = $this->video($this->member)->id;
        }
        $playlist = $this->playlist($this->member, $ids);

        $html = $this->actingAs($this->member)->get(route('user.playlists.edit', $playlist))->assertOk()->getContent();
        $this->assertSame(0, substr_count($html, 'name="videos[]"'), 'the editor must not post one field per video (PHP max_input_vars cuts long lists)');

        $form = $this->editorForm($playlist);
        $form['videos_order'] = implode(',', array_reverse($ids));

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->actingAs($this->member)->put(route('user.playlists.update', $playlist), $form)->assertSessionHasNoErrors();

        $this->assertLessThan(40, $count, "one save of a 200-video playlist ran {$count} queries");
        $this->assertSame(array_reverse($ids), $this->order($playlist));
    }

    public function test_a_refused_editor_save_keeps_the_members_list_and_unsaved_state(): void
    {
        [$a, $b, $c] = array_map(fn () => $this->video($this->member, ['title' => 'Kept video '.uniqid()])->id, range(1, 3));
        $playlist = $this->playlist($this->member, [$a, $b, $c]);
        $form = $this->editorForm($playlist);

        // a name every member's own list has: refused (title.not_in)
        $html = $this->actingAs($this->member)->from(route('user.playlists.edit', $playlist))->followingRedirects()
            ->put(route('user.playlists.update', $playlist), array_merge($form, ['title' => 'Watch later', 'videos_order' => implode(',', [$c, $a])]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('This name belongs to a list every member has', $html, 'the save was refused');

        $this->assertMatchesRegularExpression('/x-data="\{ dirty: true/', $html, 'the editor comes back with its unsaved changes');
        $back = $this->hiddenFields($html);
        $this->assertSame(implode(',', [$c, $a]), $back['videos_order'] ?? null, 'the member\'s order and removal are kept');
        $this->assertSame(implode(',', [$a, $b, $c]), $back['videos_loaded'] ?? null, 'the list the page first loaded is kept for the merge');
        $this->assertSame([$a, $b, $c], $this->order($playlist), 'nothing was saved');
    }

    // ------------------------------------------------------------------------------------------ Video details

    public function test_video_details_that_come_back_with_errors_start_with_unsaved_changes(): void
    {
        $video = $this->video($this->member, ['title' => 'Calm sea at noon']);

        $html = $this->actingAs($this->member)->from(route('user.videos.edit', $video))->followingRedirects()
            ->put(route('user.videos.update', $video), $this->videoPayload($video, ['title' => '', 'description' => 'Typed but refused.']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Typed but refused.', $html);
        // 1.2.3 round 3 (R3-086): the starting values are a plain object, "dirty" left out while false
        $this->assertStringContainsString('mkVideoForm({dirty:true,status:', $html, 'typed values that were not saved keep the save bar and the leave warning');

        $clean = $this->actingAs($this->member)->get(route('user.videos.edit', $video))->assertOk()->getContent();
        $this->assertStringContainsString('mkVideoForm({status:', $clean);
    }

    public function test_a_description_keeps_the_browsers_line_breaks_as_one_character(): void
    {
        $video = $this->video($this->member, ['title' => 'Calm sea at noon']);

        $this->actingAs($this->member)->put(route('user.videos.update', $video), $this->videoPayload($video, ['description' => "Line one\r\nLine two\rLine three"]))->assertSessionHasNoErrors();

        $this->assertSame("Line one\nLine two\nLine three", DB::table('videos')->where('id', $video->id)->value('description'));
        $html = $this->actingAs($this->member)->get(route('user.videos.edit', $video))->assertOk()->getContent();
        // (the counter is the field's next element since 1.2.3 round 3, R3-086: the component keeps it in step)
        $this->assertMatchesRegularExpression('~</textarea>\s*<div class="mk-counter"><span>28</span> / ~', $html, 'the counter starts with the length the browser counts');
    }

    // ------------------------------------------------------------------------------------------ My videos

    public function test_the_row_menu_offers_pin_or_unpin_as_the_policy_allows(): void
    {
        // an owner without a verified e-mail: the channel cannot show a pinned video, the policy never allows Unpin
        $unverified = User::factory()->unverified()->create();
        $video = $this->video($unverified, ['title' => 'Calm sea at noon']);
        $unverified->forceFill(['pinned_video_id' => $video->id])->save();

        $rows = $this->rowMenus($unverified);
        $this->assertArrayNotHasKey('unpin', $rows[$video->id], 'Unpin would answer 403 for this owner');
        $this->actingAs($unverified)->post(route('user.videos.unpin', $video))->assertForbidden();

        // a verified owner with a pinned video: Unpin, and the other rows Pin
        $pinned = $this->video($this->member, ['title' => 'Pinned one']);
        $plain = $this->video($this->member, ['title' => 'Plain one']);
        $this->member->forceFill(['pinned_video_id' => $pinned->id])->save();

        $rows = $this->rowMenus($this->member);
        $this->assertArrayHasKey('unpin', $rows[$pinned->id]);
        $this->assertArrayNotHasKey('pin', $rows[$pinned->id]);
        $this->assertArrayHasKey('pin', $rows[$plain->id]);
        $this->assertArrayNotHasKey('unpin', $rows[$plain->id]);
    }

    public function test_save_and_pin_answer_with_a_toast(): void
    {
        $video = $this->video($this->member, ['title' => 'Calm sea at noon']);

        $this->actingAs($this->member)->put(route('user.videos.update', $video), $this->videoPayload($video, ['action' => 'save']))
            ->assertSessionHas('toast', 'Video saved')
            ->assertSessionMissing('success');

        $this->actingAs($this->member)->from(route('user.videos.index'))->post(route('user.videos.pin', $video))
            ->assertSessionHas('toast', 'Pinned to your channel.');

        $html = $this->actingAs($this->member)->withSession(['toast' => 'Video saved'])->get(route('user.videos.edit', $video))->assertOk()->getContent();
        $this->assertStringContainsString('data-mk-flash-toast', $html, 'the page hands the toast to the toast system');
        $this->assertStringNotContainsString('mk-alert--success', $html, 'no inline alert for a save');
    }

    public function test_a_page_past_the_end_of_a_list_goes_to_its_last_page(): void
    {
        config(['site.per_page_videos' => 2, 'site.per_page_comments' => 2]);
        $videos = [];
        for ($i = 0; $i < 3; $i++) {
            $videos[] = $this->video($this->member, ['title' => 'Row '.$i, 'created_at' => now()->subMinutes(10 - $i)]);
        }
        $page2 = route('user.videos.index', ['page' => 2]);
        $this->actingAs($this->member)->get($page2)->assertOk()->assertSee('Row 0');

        $response = $this->actingAs($this->member)->from($page2)->delete(route('user.videos.destroy', $videos[0]));
        $this->actingAs($this->member)->followingRedirects()->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('No videos match these filters')
            ->assertSee('Row 1');

        $comments = [];
        for ($i = 0; $i < 3; $i++) {
            $comments[] = $this->comment($videos[1], $this->viewer, 'Comment number '.$i, null, now()->subMinutes(10 - $i));
        }
        $this->actingAs($this->member)->followingRedirects()->get(route('user.comments.index', ['page' => 5]))
            ->assertOk()
            ->assertSee('Comment number 0');
    }

    public function test_bulk_public_announces_the_videos_in_one_indexnow_call(): void
    {
        config(['site.indexnow_enabled' => true, 'site.indexnow_key' => 'm1probekey']);
        Http::fake(['*' => Http::response('', 200)]);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->video($this->member, ['status' => VideoStatus::PRIVATE->value, 'published_at' => null])->id;
        }
        $this->member->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->member)->post(route('user.videos.mass-visibility'), ['ids' => $ids, 'visibility' => 'public'])->assertRedirect();

        $calls = Http::recorded();
        $this->assertCount(1, $calls, count($calls).' IndexNow calls for one bulk change');
        $this->assertCount(12, $calls[0][0]->data()['urlList'] ?? []);
    }

    // ------------------------------------------------------------------------------------------ Comments

    public function test_the_comments_page_loads_the_video_link_relations_once(): void
    {
        config(['site.video_url_pattern' => '{category}/{slug}-{uuid}']);
        $category = Category::query()->create(['title' => 'Travel', 'slug' => 'travel']);
        for ($i = 0; $i < 15; $i++) {
            $video = $this->video($this->member, ['title' => 'Probe '.$i, 'category_id' => $category->id]);
            $this->comment($video, $this->viewer, 'Comment '.$i);
        }

        // the layout's own category menu runs its queries on the first page of a request cycle (cached after that)
        $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk();
        $categoryQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"categories"'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $categoryQueries, "{$categoryQueries} category queries for 15 rows (one per video before)");
    }

    // ------------------------------------------------------------------------------------------ Analytics

    public function test_analytics_answers_a_hand_made_array_period(): void
    {
        $video = $this->video($this->member);

        $this->actingAs($this->member)->get(route('user.videos.show', $video).'?period[]=7')->assertOk();
    }

    public function test_analytics_of_a_video_without_any_daily_figure_shows_only_the_note(): void
    {
        $video = $this->video($this->member, ['views' => 354]);

        foreach (['all', '28'] as $period) {
            $html = $this->actingAs($this->member)->get(route('user.videos.show', ['video' => $video, 'period' => $period]))->assertOk()->getContent();

            $this->assertStringNotContainsString('class="mk-chart"', $html, 'no empty chart with an invented scale');
            $this->assertStringNotContainsString('Show as a table', $html);
            $this->assertStringContainsString('There are no daily figures for this video yet.', $html);
        }
    }

    public function test_signed_numbers_are_isolated_so_arabic_keeps_their_sign_in_front(): void
    {
        $video = $this->video($this->member, ['title' => 'Calm sea at noon']);
        DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $this->viewer->id, 'subscribe_at' => now()->subHour(), 'read_at' => now()]);
        $today = now()->toDateString();
        DB::table('video_view_daily_stats')->insert([
            ['video_id' => $video->id, 'view_date' => now()->subDays(8)->toDateString(), 'views_count' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $video->id, 'view_date' => $today, 'views_count' => 7, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $dashboard = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<bdi dir="ltr">+1</bdi>', $dashboard, 'the new subscribers figure');

        $analytics = $this->actingAs($this->member)->get(route('user.videos.show', $video))->assertOk()->getContent();
        $this->assertStringContainsString('<bdi dir="ltr">-30%</bdi>', $analytics, 'the last 7 days change');
    }

    // ------------------------------------------------------------------------------------------ dashboard tips

    public function test_tips_list_only_what_there_is_to_do(): void
    {
        config(['site.creator_quality_pulse_enabled' => true, 'site.creator_quality_pulse_limit' => 1]);
        $shelf = $this->playlist($this->member, []);

        foreach (range(1, 4) as $i) {
            $ready = $this->video($this->member, ['title' => 'Ready video '.$i, 'description' => 'A calm drive.']);
            Thumbnail::factory()->active()->for($ready)->create();
            DB::table('playlist_has_videos')->insert(['playlist_id' => $shelf->id, 'video_id' => $ready->id, 'position' => $i, 'added_at' => now()]);
            DB::table('video_view_daily_stats')->insert(['video_id' => $ready->id, 'view_date' => now()->toDateString(), 'views_count' => 100 + $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $todo = $this->video($this->member, ['title' => 'Harbour timelapse at dawn', 'description' => 'Boats.']);
        Thumbnail::factory()->active()->for($todo)->create();
        DB::table('video_view_daily_stats')->insert(['video_id' => $todo->id, 'view_date' => now()->toDateString(), 'views_count' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Add Harbour timelapse at dawn to a playlist', $html);
        $this->assertStringNotContainsString(' is ready', $html, 'a video with nothing to fix is not a tip');

        // nothing to do: no tips panel
        DB::table('playlist_has_videos')->insert(['playlist_id' => $shelf->id, 'video_id' => $todo->id, 'position' => 9, 'added_at' => now()]);
        \App\Support\Studio\Dashboard::forget($this->member);
        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertDontSee('Tips for your videos');
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    /** @return list<Video> six videos of the member with a comment each, and a subscriber */
    private function dashboardData(): array
    {
        // one clock for the six videos (flaky full runs): made across a second boundary, the first one
        // was a second older than the other five and fell out of the five "No picture chosen" alerts (the latest
        // uploaded first), and test_one_counted_view_between_two_dashboard_visits_keeps_the_cache missed "Probe video 0"
        $this->freezeTime();
        $videos = [];
        for ($i = 0; $i < 6; $i++) {
            $videos[] = $video = $this->video($this->member, ['title' => 'Probe video '.$i]);
            $this->comment($video, $this->viewer, 'Nice one '.$video->id);
        }
        DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $this->viewer->id, 'subscribe_at' => now()->subHour(), 'read_at' => now()]);

        return $videos;
    }

    /** A view the site counts (ViewService: videos.views + the day's total), by another member. */
    private function countView(Video $video): void
    {
        $this->actingAs($this->viewer);
        app(ViewService::class)->incrementView($video->fresh());
    }

    /** @return array<int, array<string, mixed>> the row menus of My videos (the page's JSON), by video id */
    private function rowMenus(User $owner): array
    {
        $html = $this->actingAs($owner)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script type="application\/json" id="mk-video-rows">(.*?)<\/script>/s', $html, $m));

        return json_decode($m[1], true)['r'] ?? [];
    }

    /** @return array<string, string> the editor form's hidden fields as the page prints them (what a save posts) */
    private function editorForm(Playlist $playlist): array
    {
        $html = $this->actingAs($this->member, 'web')->get(route('user.playlists.edit', $playlist))->assertOk()->getContent();

        return $this->hiddenFields($html) + [
            'title' => (string) $playlist->title,
            'status' => (string) PlaylistStatus::PUBLIC->value,
            'sort' => (string) PlaylistSort::MANUAL->value,
            'action' => 'save',
        ];
    }

    /** @return array<string, mixed> the hidden fields of the playlist form (videos[] as a list) */
    private function hiddenFields(string $html): array
    {
        $start = strpos($html, 'id="mk-playlist-form"');
        $this->assertNotFalse($start, 'the editor form');
        $form = substr($html, $start, (int) strpos($html, '</form>', $start) - $start);
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form, $m, PREG_SET_ORDER);
        $fields = [];
        foreach ($m as [, $name, $value]) {
            if (in_array($name, ['_token', '_method'], true)) {
                continue;
            }
            if (str_ends_with($name, '[]')) {
                $fields[substr($name, 0, -2)][] = html_entity_decode($value);
            } else {
                $fields[$name] = html_entity_decode($value);
            }
        }

        return $fields;
    }

    /** @return list<int> */
    private function order(Playlist $playlist): array
    {
        return DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->orderBy('position')->orderBy('id')->pluck('video_id')->map(fn ($id) => (int) $id)->all();
    }

    private function video(User $owner, array $attributes = []): Video
    {
        return Video::factory()->for($owner, 'user')->create(array_merge([
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'published_at' => now()->subDays(3),
            'uploaded_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ], $attributes));
    }

    private function comment(Video $video, User $author, string $text, ?Comment $parent = null, $at = null): Comment
    {
        $comment = Comment::query()->create([
            'video_id' => $video->id,
            'user_id' => $author->id,
            'parent_id' => $parent?->id,
            'content' => $text,
        ]);

        if ($at !== null) {
            DB::table('comments')->where('id', $comment->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $comment;
    }

    /** @param list<int> $videoIds */
    private function playlist(User $owner, array $videoIds): Playlist
    {
        $playlist = Playlist::factory()->for($owner)->create(['title' => 'Night drives', 'status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);

        foreach (array_values($videoIds) as $i => $id) {
            DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $id, 'position' => $i, 'added_at' => now()->subMinutes(500 - $i)]);
        }

        return $playlist;
    }

    private function videoPayload(Video $video, array $override = []): array
    {
        return array_merge([
            'title' => (string) $video->getRawOriginal('title'),
            'description' => 'A calm drive.',
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'allow_comments' => 1,
            'default_comments_sort' => 'top',
            'show_likes' => 1,
            'show_ad' => 0,
        ], $override);
    }
}
