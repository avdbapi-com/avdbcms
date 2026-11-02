<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 1.2.3 (member design 6.1-6.8): the rebuilt studio pages. Every page answers in standards mode (a doctype first:
 * the drafts page printed a <style> before it, studio-14), carries the kit root, and keeps the rules of each page:
 * Activity's Likes / Dislikes filters (studio-9: 500), the replies of a comment (studio-21; the security rule: own videos
 * only), the document scrolls instead of the studio's content box (perf-1), the tab bar and its marker follow the
 * admin's "Mobile Bottom Nav Bar", the toolbar selects are native (5.9), the preview mode and own-pictures setting,
 * the Analytics period, the "no daily figures" note, the dashboard's widget limit and the new member's one empty state
 * (studio-36), and the bulk removals use the kit dialog, never confirm() (studio-33).
 */
class StudioPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $viewer;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('thumbnails');
        $this->member = User::factory()->create(['username' => 'nightdrive_tv']);
        $this->viewer = User::factory()->create(['username' => 'qa_studio_viewer']);
        $this->video = $this->video($this->member, ['title' => 'Sunset drive along the coast road in 4K', 'views' => 140]);
    }

    public function test_every_studio_page_answers_in_standards_mode_with_the_kit_root(): void
    {
        $draft = $this->video($this->member, ['title' => 'Untitled upload 0425', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);
        $comment = $this->comment($this->video, $this->viewer, 'Wow, the light is unreal.');
        $this->comment($this->video, $this->member, 'Thank you!', $comment);
        $playlist = $this->playlist($this->member, [$this->video->id]);
        DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $this->viewer->id, 'subscribe_at' => now()->subHour(), 'read_at' => now()]);

        $pages = [
            route('user.index'),
            route('user.videos.index'),
            route('user.videos.edit', $this->video),
            route('user.videos.show', $this->video),
            route('user.videos.create', $draft),
            route('user.playlists.index'),
            route('user.playlists.create'),
            route('user.playlists.edit', $playlist),
            route('user.comments.index'),
            route('user.comments.replies', $comment),
            route('user.subscribers'),
            route('user.activity.index'),
            route('user.reports.index'),
            route('user.invoices.index'),
        ];

        foreach ($pages as $url) {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/^\s*<!DOCTYPE html>/i', $html, "{$url} must start with its doctype");
            $this->assertStringContainsString('class="avd-mk mk-page', $html, "{$url} must print the kit page root");
        }
    }

    public function test_activity_filters_likes_and_dislikes_answer_and_list_only_their_kind(): void
    {
        $this->actingAs($this->member);
        $other = $this->video($this->viewer, ['title' => 'Blue hour above the valley']);
        $disliked = $this->video($this->viewer, ['title' => 'Loud city traffic for 10 hours']);
        Interaction::query()->create(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $other->id, 'status' => true, 'perform_at' => now()->subHour()]);
        Interaction::query()->create(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $disliked->id, 'status' => false, 'perform_at' => now()->subHours(2)]);
        $this->comment($other, $this->member, 'Thanks for watching!');
        $this->assertSame(3, Activity::query()->where('user_id', $this->member->id)->count());

        $likes = $this->get(route('user.activity.index', ['type' => 'likes']))->assertOk();
        $likes->assertSee('Blue hour above the valley')->assertDontSee('Loud city traffic for 10 hours')->assertDontSee('Thanks for watching!');

        $dislikes = $this->get(route('user.activity.index', ['type' => 'dislikes']))->assertOk();
        $dislikes->assertSee('Loud city traffic for 10 hours')->assertDontSee('Blue hour above the valley');

        $comments = $this->get(route('user.activity.index', ['type' => 'comments']))->assertOk();
        $comments->assertSee('Thanks for watching!')->assertDontSee('Loud city traffic for 10 hours');

        $this->get(route('user.activity.index', ['type' => 'replies']))->assertOk()->assertSee('No activity matches these filters');
    }

    public function test_the_replies_of_a_comment_open_on_the_members_own_videos_only(): void
    {
        $comment = $this->comment($this->video, $this->viewer, 'Which camera did you use?');
        $this->comment($this->video, $this->member, 'A small action camera.', $comment);

        $part = $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $comment, 'part' => 1]))->assertOk();
        $part->assertSee('A small action camera.')->assertSee('Remove reply')->assertDontSee('<!DOCTYPE', false);
        $this->assertStringContainsString('data-bs-target="#mk-remove-reply"', $part->getContent());

        // the security rule (S02): another member's comment answers as if it did not exist
        $elsewhere = $this->comment($this->video($this->viewer), $this->viewer, 'Not on your video.');
        $this->actingAs($this->member)->get(route('user.comments.replies', $elsewhere))->assertNotFound();
    }

    public function test_the_studio_content_box_is_not_a_scroll_container(): void
    {
        $html = $this->actingAs($this->member)->get(route('user.videos.create', $this->video($this->member, ['status' => VideoStatus::DRAFT->value, 'published_at' => null])))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div id="main-container" class="([^"]*)"/', $html, $m));
        $this->assertStringNotContainsString('overflow-auto', $m[1], 'perf-1: #main-container scrolled on its own and its scrollbar blinked on hover');
    }

    public function test_the_tab_bar_follows_the_mobile_bottom_nav_setting(): void
    {
        config(['site.mobile_bottom_nav_enabled' => true, 'site.allow_uploads' => true]);
        $on = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<nav class="mk-tabbar avd-mk"', $on);
        $this->assertStringContainsString('mk-tabbar__upload', $on);

        config(['site.allow_uploads' => false]);
        $four = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('mk-tabbar avd-mk mk-tabbar--4', $four);
        $this->assertStringNotContainsString('mk-tabbar__upload', $four);

        config(['site.mobile_bottom_nav_enabled' => false, 'site.allow_uploads' => true]);
        $off = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="mk-tabbar', $off);
    }

    public function test_the_toolbar_and_form_selects_are_native_and_playlists_follow_the_enhanced_setting(): void
    {
        $this->playlist($this->member, []);
        $list = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        preg_match_all('/<select[^>]*class="form-select[^"]*"[^>]*>/', $list, $selects);
        $this->assertNotEmpty($selects[0]);
        foreach ($selects[0] as $select) {
            $this->assertStringContainsString('data-native-select', $select, 'toolbar selects stay native (5.9): '.$select);
        }

        config(['site.enhanced_selects_enabled' => true]);
        $edit = $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]*name="playlists\[\]"[^>]*class="[^"]*select-multiple/', $edit);
        foreach (['category_id', 'language', 'default_comments_sort'] as $name) {
            $this->assertMatchesRegularExpression('/<select[^>]*name="'.$name.'"[^>]*data-native-select|<select[^>]*data-native-select[^>]*name="'.$name.'"/', $edit, "{$name} stays native");
        }

        config(['site.enhanced_selects_enabled' => false]);
        $plain = $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->getContent();
        $this->assertStringNotContainsString('select-multiple', $plain);
        $this->assertStringContainsString('mk-checklist', $plain);
    }

    public function test_the_preview_card_follows_the_preview_mode(): void
    {
        config(['site.studio_video_preview_mode' => 'inline']);
        $inline = $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->getContent();
        $this->assertStringContainsString('mk-inline-player', $inline);
        $this->assertMatchesRegularExpression('/<video[^>]*controls/', $inline);

        config(['site.studio_video_preview_mode' => 'button']);
        $button = $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->getContent();
        $this->assertStringNotContainsString('mk-inline-player', $button);
        $this->assertStringContainsString('mk-play-badge', $button);
    }

    public function test_own_pictures_follow_the_admin_setting(): void
    {
        config(['site.allow_custom_thumbnails' => true]);
        $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->assertSee('name="thumbnail_file"', false);

        config(['site.allow_custom_thumbnails' => false]);
        $this->actingAs($this->member)->get(route('user.videos.edit', $this->video))->assertOk()->assertDontSee('name="thumbnail_file"', false);
    }

    public function test_an_imported_video_shows_its_poster_as_the_current_picture(): void
    {
        $imported = $this->video($this->member, ['poster_url' => 'https://cdn.example.test/poster.jpg']);
        $html = $this->actingAs($this->member)->get(route('user.videos.edit', $imported))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="radio" name="thumbnail" value="" checked><span class="mk-thumb"><img src="https:\/\/cdn\.example\.test\/poster\.jpg"/', $html);

        // a video with a chosen picture of its own does not offer the poster
        Thumbnail::factory()->generated()->active()->create(['video_id' => $imported->id]);
        $this->actingAs($this->member)->get(route('user.videos.edit', $imported))->assertOk()->assertDontSee('Current picture');
    }

    public function test_analytics_marks_the_chosen_period_and_draws_the_chart_server_side(): void
    {
        DB::table('video_view_daily_stats')->insert(['video_id' => $this->video->id, 'view_date' => now()->toDateString(), 'views_count' => 40, 'created_at' => now(), 'updated_at' => now()]);

        $default = $this->actingAs($this->member)->get(route('user.videos.show', $this->video))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-current="true"\s*>28 days</', $default);
        $this->assertStringContainsString('class="mk-chart"', $default);
        $this->assertStringNotContainsString('new Chart(', $default, 'no Chart.js on member pages (studio-1)');

        $week = $this->actingAs($this->member)->get(route('user.videos.show', ['video' => $this->video, 'period' => '7']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-current="true"\s*>7 days</', $week);
        $this->assertDoesNotMatchRegularExpression('/aria-current="true"\s*>28 days</', $week);
    }

    public function test_analytics_without_daily_figures_says_so_instead_of_an_empty_chart(): void
    {
        config(['site.view_daily_stats_enabled' => false]);

        $html = $this->actingAs($this->member)->get(route('user.videos.show', $this->video))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="mk-chart"', $html);
        $this->assertStringContainsString('Daily view totals are switched off', $html);
    }

    public function test_the_dashboard_lists_as_many_rows_as_the_widget_limit(): void
    {
        config(['site.dashboard_widget_limit' => 2]);
        foreach (['First of four', 'Second of four', 'Third of four'] as $i => $title) {
            $this->video($this->member, ['title' => $title, 'created_at' => now()->subMinutes(10 - $i)]);
        }

        $html = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();
        $latest = substr($html, (int) strpos($html, 'id="mk-latest-title"'));
        $latest = substr($latest, 0, (int) strpos($latest, '</section>'));

        $this->assertSame(2, substr_count($latest, '<li class="mk-item">'));
    }

    /**
     * studio-29: the dashboard's lists and boxes are cached 5 minutes per member, so a returning member's view needs at
     * most 20 queries (it ran every widget's queries on every view: 29 here, 67 in the QA run); the member's own changes
     * still show at once (a removed comment, a new upload).
     */
    public function test_a_returning_members_dashboard_needs_at_most_twenty_queries_and_shows_their_changes_at_once(): void
    {
        $comment = $this->comment($this->video, $this->viewer, 'Wow, the light at 04:10 is unreal.');
        $this->comment($this->video, $this->viewer, 'Which camera did you use?');
        foreach (['Harbour timelapse at dawn', 'Morning market walk'] as $title) {
            $this->video($this->member, ['title' => $title]);
        }
        DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $this->viewer->id, 'subscribe_at' => now()->subHour(), 'read_at' => now()]);

        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertSee('Wow, the light at 04:10 is unreal.');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertSee('Wow, the light at 04:10 is unreal.')->assertSee('Harbour timelapse at dawn');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(20, $queries, 'a returning member\'s dashboard runs '.$queries.' queries');

        // the member removes the comment: the next view no longer lists it
        $this->actingAs($this->member)->delete(route('user.comments.destroy', $comment))->assertRedirect();
        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertDontSee('Wow, the light at 04:10 is unreal.')->assertSee('Which camera did you use?');

        // a new upload shows at once (the member's videos stamp is part of the cache key)
        $this->video($this->member, ['title' => 'Fresh upload of today', 'created_at' => now(), 'uploaded_at' => now(), 'published_at' => now()]);
        $this->actingAs($this->member)->get(route('user.index'))->assertOk()->assertSee('Fresh upload of today');
    }

    public function test_a_new_member_gets_one_empty_state_on_the_dashboard(): void
    {
        $new = User::factory()->create();

        $this->actingAs($new)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Upload your first video')
            ->assertDontSee('Latest videos')
            ->assertDontSee('Needs your attention');
    }

    public function test_bulk_removals_use_the_kit_dialog_not_confirm(): void
    {
        $this->comment($this->video, $this->viewer, 'One');
        $this->playlist($this->member, [$this->video->id]);

        foreach ([route('user.comments.index') => '#mk-remove-comments', route('user.playlists.index') => '#mk-delete-playlists', route('user.videos.index') => '#mk-delete-videos'] as $url => $dialog) {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-bs-target="'.$dialog.'"', $html, $url);
            $this->assertStringNotContainsString('confirm(', $html, $url.' must not use the browser confirm()');
        }
    }

    public function test_subscribers_show_subscribe_back_and_hide_counts_a_member_hides(): void
    {
        $hidden = User::factory()->create(['username' => 'pixel_drift', 'show_subscribers' => false]);
        foreach ([$this->viewer, $hidden] as $fan) {
            DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $fan->id, 'subscribe_at' => now()->subHour(), 'read_at' => now()]);
        }
        DB::table('subscriptions')->insert(['user_id' => $this->viewer->id, 'subscriber_id' => $this->member->id, 'subscribe_at' => now()->subDay(), 'read_at' => now()]);

        $html = $this->actingAs($this->member)->get(route('user.subscribers'))->assertOk()->getContent();

        $this->assertStringContainsString('2 people follow your channel.', $html);
        $this->assertMatchesRegularExpression('/mk-btn--fixed[^"]*"[^>]*data-mk-subscribe="[^"]+"[^>]*aria-pressed="true"/', $html, 'the member follows qa_studio_viewer back');
        $this->assertMatchesRegularExpression('/mk-btn--fixed[^"]*"[^>]*data-mk-subscribe="[^"]+"[^>]*aria-pressed="false"/', $html);
        $pixel = substr($html, (int) strpos($html, '>pixel_drift</a>'), 600);
        $this->assertStringNotContainsString('subscriber', strtolower(strip_tags(substr($pixel, 0, (int) strpos($pixel, 'Subscribed ')))), 'a member who hides their count shows none');
    }

    public function test_reports_show_the_status_and_offer_cancel_for_pending_ones_only(): void
    {
        $reported = $this->video($this->viewer, ['title' => 'Loud city traffic for 10 hours']);
        $pending = Report::query()->create(['user_id' => $this->member->id, 'reportable_type' => Video::class, 'reportable_id' => $reported->id, 'reason' => ReportReason::MISINFORMATION, 'comment' => '', 'status' => ReportStatus::PENDING]);
        $done = Report::query()->create(['user_id' => $this->member->id, 'reportable_type' => User::class, 'reportable_id' => $this->viewer->id, 'reason' => ReportReason::INFRINGES_MY_RIGHTS, 'comment' => '', 'status' => ReportStatus::CANCELLED]);

        $html = $this->actingAs($this->member)->get(route('user.reports.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Loud city traffic for 10 hours', $html);
        $this->assertStringContainsString('Reviewed: no action', $html);
        $this->assertStringContainsString('data-action="'.route('user.reports.cancel', $pending).'"', $html);
        $this->assertStringNotContainsString(route('user.reports.cancel', $done), $html);
    }

    public function test_the_invoices_page_says_when_there_is_none_yet(): void
    {
        $this->actingAs($this->member)->get(route('user.invoices.index'))->assertOk()->assertSee('No invoices yet');
    }

    // ---------------------------------------------------------------------------------------------------- helpers

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

    private function comment(Video $video, User $author, string $text, ?Comment $parent = null): Comment
    {
        return Comment::query()->create([
            'video_id' => $video->id,
            'user_id' => $author->id,
            'parent_id' => $parent?->id,
            'content' => $text,
        ]);
    }

    /** @param list<int> $videoIds */
    private function playlist(User $owner, array $videoIds): Playlist
    {
        $playlist = Playlist::factory()->for($owner)->create(['status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);

        foreach (array_values($videoIds) as $i => $id) {
            DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $id, 'position' => $i, 'added_at' => now()]);
        }

        return $playlist;
    }
}
