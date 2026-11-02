<?php

namespace Tests\Feature\Studio;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\Thumbnail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use App\Services\StudioHealthAlertsService;
use App\Support\Studio\Dashboard;
use App\Support\Studio\StudioFormat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 1.2.3 round 1 (the judge's studio findings, lane FS). Each test fails on RC1 and passes with the fix:
 *  - R1-093 a failed upload's row menu offers "Upload again" and never "Edit details" (a 403 page);
 *  - R1-108 Subscribers, Activity, Reports and Invoices past their last page go to it (no "Showing 0-0 of 4");
 *  - R1-113 a member without videos is welcomed, not welcomed back;
 *  - R1-019 "No recent views" only for a new video nobody has watched (guests count, as in the Views tile);
 *  - R1-165 replies and comments hidden by a moderator are not listed or counted in the studio (what viewers see);
 *  - R1-112 written-month dates follow the member's language ("29 oct. 2026"), numeric admin formats stay;
 *  - R1-074 the dashboard's own queries on a cold widget cache stay within the 4.3 budget;
 *  - R1-022 the playlist editor prints the first rows once and the whole list once, compact;
 *  - R1-024 "Finish later" with typed changes saves the draft and goes to My videos;
 *  - R1-110 Video details: Category has a row of its own (long category names are never cut);
 *  - R1-166 the draft page can show "Upload failed" in its head when the processing fails;
 *  - R1-025 comment texts take their own direction (dir="auto"); R1-092 the processing state takes the preview row;
 *  - R1-069 the studio's shared inline script stays small (round 1b, R1R-01: it is one cached file now).
 * Pass 2 (each fails on the first pass's files, or on RC1's for R1-006, and passes):
 *  - R1-006 the draft page prints the state it shows and reloads only for a new one (the owner-approved poll change);
 *  - R1-025 the dashboard greeting isolates the member's name; R1-165 the Comments video filter skips hidden-only videos;
 *  - R1-026 My videos' bulk dialogs are filled outside the click, and the toolbar and the bulk bar share one cell;
 *  - R1-069 the row-confirmation script only where rows have confirmations; the video pages' crop texts.
 */
class StudioRound1FixesTest extends TestCase
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

    // ------------------------------------------------------------------------------------------------- R1-093

    public function test_a_failed_upload_row_offers_upload_again_and_never_edit_details(): void
    {
        config(['site.allow_uploads' => true]);
        $failed = $this->video($this->member, ['title' => 'Rainy day vlog', 'status' => VideoStatus::FAILED->value, 'uploaded_at' => null, 'published_at' => null]);
        $public = $this->video($this->member, ['title' => 'Sunset drive']);

        $html = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $data = $this->rowsJson($html, 'mk-video-rows');

        $this->assertArrayNotHasKey('edit', $data['r'][$failed->id], 'a failed upload cannot be edited (VideoPolicy answers 403)');
        $this->assertSame(1, $data['r'][$failed->id]['upload'] ?? null, 'the row menu offers "Upload again"');
        $this->assertSame(1, $data['r'][$failed->id]['delete'] ?? null);
        $this->assertSame(1, $data['r'][$public->id]['edit'] ?? null);
        $this->assertArrayNotHasKey('upload', $data['r'][$public->id]);
        $this->assertMatchesRegularExpression('~<template id="mk-video-menu">.*data-k="upload"[^>]*data-bs-target="#video_create".*</template>~s', $html);
        $this->actingAs($this->member)->get(route('user.videos.edit', $failed))->assertForbidden();

        // uploads switched off: no "Upload again" anywhere
        config(['site.allow_uploads' => false]);
        $data = $this->rowsJson($this->actingAs($this->member)->get(route('user.videos.index'))->getContent(), 'mk-video-rows');
        $this->assertArrayNotHasKey('upload', $data['r'][$failed->id]);
        $this->assertArrayNotHasKey('edit', $data['r'][$failed->id]);
    }

    // ------------------------------------------------------------------------------------------------- R1-108

    public function test_studio_lists_past_their_last_page_go_to_it(): void
    {
        config(['site.per_page_users' => 2, 'site.per_page_activity' => 2]);
        $video = $this->video($this->viewer, ['title' => 'Loud city traffic']);
        foreach (range(1, 3) as $i) {
            $fan = User::factory()->create(['username' => 'fan_'.$i]);
            DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $fan->id, 'subscribe_at' => now()->subHours($i), 'read_at' => now()]);
            $like = Interaction::query()->create(['user_id' => $this->member->id, 'likeable_type' => Video::class, 'likeable_id' => $this->video($fan)->id, 'status' => 1, 'perform_at' => now()->subHours($i)]);
            Activity::query()->firstOrCreate(['user_id' => $this->member->id, 'subject_type' => Interaction::class, 'subject_id' => $like->id], ['perform_at' => now()->subHours($i)]);
            Report::query()->create(['user_id' => $this->member->id, 'reportable_type' => Video::class, 'reportable_id' => $this->video($fan)->id, 'reason' => ReportReason::MISINFORMATION, 'comment' => '', 'status' => ReportStatus::PENDING]);
            $this->transaction($this->member, now()->subMonths($i));
        }

        foreach (['user.subscribers', 'user.activity.index', 'user.reports.index', 'user.invoices.index'] as $name) {
            $response = $this->actingAs($this->member)->get(route($name, ['page' => 9]));
            $response->assertRedirect();
            $this->assertStringContainsString('page=2', (string) $response->headers->get('Location'), $name.' sends a page past the end to its last page');
            $this->actingAs($this->member)->get(route($name, ['page' => 2]))->assertOk();
        }

        // an empty list stays where it is (nothing to go back to)
        $empty = User::factory()->create();
        foreach (['user.subscribers', 'user.activity.index', 'user.reports.index', 'user.invoices.index'] as $name) {
            $this->actingAs($empty)->get(route($name, ['page' => 4]))->assertOk();
        }

        // the same pattern: the replies page of a comment (the sheet's page without JavaScript); the sheet's own part
        // (fetched with ?part=1) is left as it is, it only asks for the pages its "Show more" names
        config(['site.per_page_replies' => 2]);
        $own = $this->video($this->member, ['title' => 'Rain on the windshield']);
        $parent = $this->comment($own, $this->viewer, 'Which camera?');
        foreach (range(1, 3) as $i) {
            $this->comment($own, $this->viewer, 'Reply '.$i, $parent);
        }
        $replies = $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $parent, 'page' => 9]));
        $replies->assertRedirect();
        $this->assertStringContainsString('page=2', (string) $replies->headers->get('Location'), 'the replies page sends a page past the end to its last page');
        $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $parent, 'page' => 2]))->assertOk();
        $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $parent, 'page' => 9, 'part' => 1]))->assertOk();
        $lonely = $this->comment($own, $this->viewer, 'No replies yet');
        $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $lonely, 'page' => 3]))->assertOk();
    }

    // ------------------------------------------------------------------------------------------------- R1-113

    public function test_a_member_without_videos_is_welcomed_not_welcomed_back(): void
    {
        $html = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Welcome, <bdi>nightdrive_tv</bdi>', $html);
        $this->assertStringNotContainsString('Welcome back', $html);

        $this->video($this->member, ['title' => 'Sunset drive']);
        $html = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Welcome back, <bdi>nightdrive_tv</bdi>', $html);
    }

    // ------------------------------------------------------------------------------------- R1-025 (review FS-RV-03)

    public function test_the_dashboard_greeting_isolates_the_members_name(): void
    {
        // a Latin name ending in a neutral character: on an Arabic page the "_" must stay at the name's own end, so the
        // name is isolated in every language (the markup is the same; the browser checks ran the Arabic pages)
        $member = User::factory()->create(['username' => 'neon_<b>&"tv_']);
        $this->video($member, ['title' => 'Sunset drive']);

        $html = $this->actingAs($member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<h1 class="mk-head__title">Welcome back, <bdi>neon_&lt;b&gt;&amp;&quot;tv_</bdi></h1>~u', $html, 'the name is escaped and isolated inside the greeting');
        $this->assertStringNotContainsString('<b>&', $html);
    }

    // ------------------------------------------------------------------------------------- R1-165 (review FS-RV-04)

    public function test_the_comments_video_filter_offers_only_videos_with_a_listed_comment(): void
    {
        $shown = $this->video($this->member, ['title' => 'Harbour lights']);
        $this->comment($shown, $this->viewer, 'Lovely colours.');
        // its only comment hidden by a moderator: the list cannot show anything for it
        $hiddenOnly = $this->video($this->member, ['title' => 'Spam magnet']);
        $this->comment($hiddenOnly, $this->viewer, 'Hidden comment: spam', null, hidden: true);
        // a reply under a hidden comment is not listed either (the list shows comments, replies sit in their sheet)
        $hiddenParent = $this->video($this->member, ['title' => 'Quiet clip']);
        $parent = $this->comment($hiddenParent, $this->viewer, 'Hidden comment: ads', null, hidden: true);
        $this->comment($hiddenParent, $this->member, 'Reply under a hidden comment', $parent);

        $html = $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<option value="'.$shown->id.'"[^>]*>Harbour lights</option>~', $html);
        $this->assertDoesNotMatchRegularExpression('~<option value="\d+"[^>]*>Spam magnet</option>~', $html, 'a video whose only comment is hidden is not offered');
        $this->assertDoesNotMatchRegularExpression('~<option value="\d+"[^>]*>Quiet clip</option>~', $html, 'nor one whose only listed comment would be a hidden one');
    }

    // ------------------------------------------------------------------------------------------------- R1-019

    public function test_no_recent_views_only_for_a_new_video_nobody_watched(): void
    {
        config(['site.studio_health_alerts_enabled' => true, 'site.studio_health_alerts_limit' => 12, 'site.studio_health_alerts_lookback_days' => 14]);
        // watched today by guests: the Views tile counts it (daily figures and videos.views), the raw history has no row
        $watched = $this->video($this->member, ['title' => 'Uploaded today: harbour timelapse', 'views' => 42, 'published_at' => now()->subHours(3), 'uploaded_at' => now()->subHours(3)]);
        DB::table('video_view_daily_stats')->insert(['video_id' => $watched->id, 'view_date' => now()->toDateString(), 'views_count' => 42, 'created_at' => now(), 'updated_at' => now()]);
        $unwatched = $this->video($this->member, ['title' => 'Nobody saw this yet', 'views' => 0, 'published_at' => now()->subDays(2), 'uploaded_at' => now()->subDays(2)]);

        $alerts = app(StudioHealthAlertsService::class)->alertsFor($this->member)->where('type', 'low_engagement');

        $this->assertFalse($alerts->contains(fn (array $alert) => $alert['video']->id === $watched->id), 'a video with 42 views is not "No recent views"');
        $this->assertTrue($alerts->contains(fn (array $alert) => $alert['video']->id === $unwatched->id));
    }

    // ------------------------------------------------------------------------------------------------- R1-165

    public function test_replies_and_comments_hidden_by_a_moderator_are_not_listed_or_counted(): void
    {
        $video = $this->video($this->member, ['title' => 'Sunset drive']);
        $parent = $this->comment($video, $this->viewer, 'Wow, the light is unreal.');
        $this->comment($video, $this->viewer, 'Public reply: same here.', $parent);
        $this->comment($video, $this->viewer, 'Hidden reply: buy followers', $parent, hidden: true);
        $this->comment($video, $this->viewer, 'Hidden comment: spam spam', null, hidden: true);

        $sheet = $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $parent, 'part' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Public reply: same here.', $sheet);
        $this->assertStringNotContainsString('Hidden reply', $sheet);
        $this->assertStringContainsString('1 reply', $sheet);
        $this->assertStringNotContainsString('2 replies', $sheet);

        $list = $this->actingAs($this->member)->get(route('user.comments.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Wow, the light is unreal.', $list);
        $this->assertStringNotContainsString('Hidden comment', $list);
        $this->assertStringContainsString('1 reply', $list);

        $dashboard = $this->actingAs($this->member)->get(route('user.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Wow, the light is unreal.', $dashboard);
        $this->assertStringNotContainsString('Hidden comment', $dashboard);

        $analytics = $this->actingAs($this->member)->get(route('user.videos.show', $video))->assertOk()->getContent();
        $this->assertStringNotContainsString('Hidden comment', $analytics);

        // My videos: the row counts the comments viewers see (the comment and its public reply), while deleting the video
        // still names every comment it removes (the hidden ones too)
        $videos = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-meta-comments">2 comments</span>', $videos);
        $this->assertStringNotContainsString('<span class="mk-meta-comments">4 comments</span>', $videos);
        $this->assertMatchesRegularExpression('/"'.$video->id.'":\{[^}]*"c":4[,}]/', $videos, 'the delete dialog counts every comment the video takes with it');
    }

    // ------------------------------------------------------------------------------------------------- R1-112

    public function test_written_month_dates_follow_the_members_language(): void
    {
        $this->skipUnlessIntl();
        config(['site.date_format' => 'M d, Y', 'app.timezone' => 'Europe/Paris']);
        $date = CarbonImmutable::create(2026, 10, 29, 20, 0, 0, 'Europe/Paris');

        app()->setLocale('en');
        $this->assertSame('Oct 29, 2026', StudioFormat::date($date));
        $this->assertSame('Oct 29', StudioFormat::shortDate($date));

        app()->setLocale('fr');
        $this->assertSame('29 oct. 2026', StudioFormat::date($date));
        $this->assertSame('29 oct.', StudioFormat::shortDate($date));

        app()->setLocale('de');
        $this->assertSame('29. Okt. 2026', StudioFormat::date($date));

        app()->setLocale('ar');
        $this->assertSame('29', explode(' ', StudioFormat::date($date))[0], 'Arabic writes the day first, in Western digits');

        // the admin's numeric formats are an explicit choice: kept in every language
        config(['site.date_format' => 'd/m/Y']);
        app()->setLocale('fr');
        $this->assertSame('29/10/2026', StudioFormat::date($date));
        app()->setLocale('en');
    }

    // ------------------------------------------------------------------------------------------------- R1-074

    public function test_the_dashboard_stays_within_its_query_budget_on_a_cold_widget_cache(): void
    {
        config(['site.studio_health_alerts_enabled' => true, 'site.creator_recovery_checklist_enabled' => true, 'site.creator_quality_pulse_enabled' => true]);
        $fans = User::factory()->count(4)->create();
        foreach (range(1, 8) as $i) {
            $video = $this->video($this->member, ['title' => 'Night drive diary '.$i, 'views' => $i * 10, 'published_at' => now()->subDays($i), 'uploaded_at' => now()->subDays($i)]);
            Thumbnail::factory()->active()->for($video)->create();
            DB::table('video_view_daily_stats')->insert(['video_id' => $video->id, 'view_date' => now()->subDays($i)->toDateString(), 'views_count' => $i, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($fans as $fan) {
                $this->comment($video, $fan, 'Comment '.$i.' by '.$fan->username);
            }
        }
        $this->video($this->member, ['title' => 'Failed upload', 'status' => VideoStatus::FAILED->value, 'uploaded_at' => null, 'published_at' => null, 'updated_at' => now()->subHour()]);
        $this->video($this->member, ['title' => 'Old failed upload', 'status' => VideoStatus::FAILED->value, 'uploaded_at' => null, 'published_at' => null, 'updated_at' => now()->subDays(40)]);
        $this->video($this->member, ['title' => 'Old draft', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'updated_at' => now()->subDays(40)]);
        $this->video($this->member, ['title' => 'Private for a while', 'status' => VideoStatus::PRIVATE->value, 'updated_at' => now()->subDays(40)]);
        $reported = $this->video($this->member, ['title' => 'Reported video', 'published_at' => now()->subDays(2), 'uploaded_at' => now()->subDays(2)]);
        Report::query()->create(['user_id' => $fans[0]->id, 'reportable_type' => Video::class, 'reportable_id' => $reported->id, 'reason' => ReportReason::MISINFORMATION, 'comment' => '', 'status' => ReportStatus::PENDING]);
        foreach ($fans as $i => $fan) {
            DB::table('subscriptions')->insert(['user_id' => $this->member->id, 'subscriber_id' => $fan->id, 'subscribe_at' => now()->subHours($i + 1), 'read_at' => now()]);
        }

        // the layout's own caches warm (a first visit), then the dashboard's entries go stale (the member's change)
        $this->actingAs($this->member)->get(route('user.index'))->assertOk();
        $warm = $this->countQueries(fn () => $this->actingAs($this->member)->get(route('user.index'))->assertOk());
        Dashboard::forget($this->member);
        $cold = $this->countQueries(fn () => $this->actingAs($this->member)->get(route('user.index'))->assertOk());

        $this->assertLessThanOrEqual(20, $cold, "the dashboard with a cold widget cache ran {$cold} queries (warm: {$warm})");
    }

    // ------------------------------------------------------------------------------------------------- R1-022

    public function test_the_playlist_editor_prints_each_video_once(): void
    {
        $ids = [];
        foreach (range(1, 30) as $i) {
            $ids[] = $this->video($this->viewer, ['title' => 'Bulk clip '.$i, 'views' => $i])->id;
        }
        $playlist = $this->playlist($this->member, $ids);

        $html = $this->actingAs($this->member)->get(route('user.playlists.edit', $playlist))->assertOk()->getContent();
        $first = \App\Http\Controllers\User\PlaylistController::EDITOR_FIRST;

        $this->assertSame($first, substr_count($html, '<li class="mk-item mk-item--drag"'), 'only the rows the page shows first are printed');
        $this->assertStringNotContainsString('class="mk-item mk-item--drag" hidden', $html);
        $this->assertSame(1, substr_count($html, 'Bulk clip 30'), 'a video further down is in the page once (its JSON entry)');
        $this->assertStringNotContainsString("initial='", $html, 'the list is not an HTML-escaped attribute any more');

        preg_match('~<script type="application/json" id="mk-pl-items">(.*?)</script>~s', $html, $m);
        $items = json_decode($m[1] ?? 'null', true);
        $this->assertIsArray($items);
        $this->assertSame($ids, array_map(fn (array $item) => $item[0], $items), 'every id of the playlist, in its order (studio-4: saving never drops a video)');
        $this->assertMatchesRegularExpression('~<playlist-videos [^>]*source="mk-pl-items"~', $html);
        $this->assertStringContainsString('name="videos_order" value="'.implode(',', $ids).'"', $html);
    }

    // ------------------------------------------------------------------------------------------------- R1-024

    public function test_finish_later_saves_the_typed_changes_and_goes_to_my_videos(): void
    {
        $draft = $this->video($this->member, ['title' => 'Untitled upload 0425', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);

        $html = $this->actingAs($this->member)->get(route('user.videos.create', $draft))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<button type="submit" class="mk-btn mk-btn--quiet"[^>]*name="action" value="later"~', $html);

        $response = $this->actingAs($this->member)->post(route('user.videos.store', $draft), [
            'action' => 'later', 'title' => 'Rain on the windshield', 'description' => 'Typed before leaving.', 'status' => VideoStatus::PUBLIC->value,
            'allow_comments' => 1, 'default_comments_sort' => 'top', 'show_likes' => 1, 'show_ad' => 1,
        ]);

        $response->assertRedirect(route('user.videos.index'));
        $response->assertSessionHas('toast', __('Draft saved.'));
        $draft->refresh();
        $this->assertSame('Rain on the windshield', $draft->getRawOriginal('title'));
        $this->assertSame(VideoStatus::DRAFT, $draft->status, 'the video stays a draft');
    }

    // ------------------------------------------------------------------------------------------------- R1-110

    public function test_video_details_give_the_category_a_row_of_its_own(): void
    {
        $video = $this->video($this->member, ['title' => 'Sunset drive']);
        $html = $this->actingAs($this->member)->get(route('user.videos.edit', $video))->assertOk()->getContent();

        $this->assertStringNotContainsString('mk-form__row--3', $html);
        $this->assertMatchesRegularExpression('~<div class="mk-field">\s*<label class="mk-label" for="category">~', $html);
        $this->assertMatchesRegularExpression('~<div class="mk-form__row mk-form__row--2">\s*<div class="mk-field">\s*<label class="mk-label" for="language">~', $html);
    }

    // ------------------------------------------------------------------------------------------------- R1-166, R1-092

    public function test_the_processing_draft_page_can_say_upload_failed_in_its_head(): void
    {
        $draft = $this->video($this->member, ['title' => 'QA draft on Upload18', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null, 'upload18_vid' => 'qa-did-1', 'embed_code' => null]);

        $html = $this->actingAs($this->member)->get(route('user.videos.create', $draft))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~data-mk-head-status>\s*<span class="mk-status mk-status--processing">Processing on Upload18</span>~', $html);
        $this->assertMatchesRegularExpression('~<template data-mk-failed-status>\s*<span class="mk-status mk-status--failed">Upload failed</span>\s*</template>~', $html);
        $this->assertMatchesRegularExpression('~<div class="mk-preview mk-preview--busy"~', $html, 'the processing state takes the whole preview row on phones');

        // a finished draft has neither
        $done = $this->video($this->member, ['title' => 'Ready draft', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);
        $html = $this->actingAs($this->member)->get(route('user.videos.create', $done))->assertOk()->getContent();
        $this->assertStringNotContainsString('<template data-mk-failed-status>', $html);
        $this->assertDoesNotMatchRegularExpression('~<div class="mk-preview mk-preview--busy"~', $html);
    }

    // ------------------------------------------------------------------------------------------------- R1-006

    public function test_the_draft_page_prints_the_state_it_shows_and_reloads_only_for_a_new_one(): void
    {
        // the owner-approved change of the guarded completion poll (2026-10-27): the page prints whether it already shows
        // a ready picture; the check reloads only for a state the page does not show yet (the embed arrived, or the first
        // ready picture appeared), and never twice for the same state (a per-draft guard keeps the state of the last reload)
        $draft = $this->video($this->member, ['title' => 'QA draft on Upload18', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null, 'upload18_vid' => 'qa-did-6', 'embed_code' => null]);
        $page = fn (Video $video) => $this->actingAs($this->member)->get(route('user.videos.create', $video))->assertOk()->getContent();
        $readyAnswer = fn (Video $video) => $this->actingAs($this->member)->getJson(route('videos.create-status', ['video' => $video->id]))->assertOk()->json('ready_thumbnail_count') > 0;

        $html = $page($draft);
        $this->assertStringContainsString('const SHOULD_POLL_UPLOAD_COMPLETION = true;', $html);
        $this->assertStringContainsString('const READY_AT_RENDER = false;', $html, 'printed without a ready picture');
        $this->assertFalse($readyAnswer($draft));
        $this->assertStringContainsString('if (data.has_embed_code || (ready && !READY_AT_RENDER)) {', $html);
        $this->assertStringContainsString("const RELOAD_KEY = 'tp_reload_' + VIDEO_ID;", $html);
        $this->assertStringContainsString("const reached = (data.has_embed_code ? 'embed' : 'no-embed') + '|' + (ready ? 'ready' : 'no-picture');", $html);
        $this->assertStringContainsString('if (reloadedFor === reached) return;', $html);
        $this->assertStringContainsString('try { sessionStorage.setItem(RELOAD_KEY, reached); } catch (e) {}', $html);
        $this->assertStringNotContainsString('if (data.has_embed_code || data.ready_thumbnail_count > 0) {', $html, 'RC1 reloaded on every check that saw a picture');
        // unchanged: the timing (first check after 3 s, then every 10 s) and the dirty-form notice
        $this->assertStringContainsString('const timer = setInterval(poll, 10000);', $html);
        $this->assertStringContainsString('setTimeout(poll, 3000);', $html);
        $this->assertStringContainsString('showReadyRefreshNotice();', $html);

        // a picture that is not ready yet (pending, failed) is not shown as ready, as the endpoint counts it
        Thumbnail::factory()->for($draft)->create(['status' => ThumbnailStatus::PENDING]);
        Thumbnail::factory()->for($draft)->create(['status' => ThumbnailStatus::ERROR]);
        $this->assertStringContainsString('const READY_AT_RENDER = false;', $page($draft->fresh()));
        $this->assertFalse($readyAnswer($draft));

        // a generated picture: the page now shows it, so the same answer ("ready", no embed) is no change any more
        Thumbnail::factory()->for($draft)->generated()->create();
        $this->assertStringContainsString('const READY_AT_RENDER = true;', $page($draft->fresh()));
        $this->assertTrue($readyAnswer($draft), 'the page and the endpoint count the same pictures');

        // an uploaded picture counts too (GENERATED or UPLOADED, as VideoController::createStatus)
        $uploaded = $this->video($this->member, ['title' => 'Draft with my own picture', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null, 'upload18_vid' => 'qa-did-7', 'embed_code' => null]);
        Thumbnail::factory()->for($uploaded)->uploaded()->create();
        $this->assertStringContainsString('const READY_AT_RENDER = true;', $page($uploaded));
        $this->assertTrue($readyAnswer($uploaded));

        // a draft printed with its embed does not poll at all (nothing left to wait for)
        $embedded = $this->video($this->member, ['title' => 'Draft with its embed', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'embed_code' => 'qa-vid-8']);
        $this->assertStringContainsString('const SHOULD_POLL_UPLOAD_COMPLETION = false;', $page($embedded));
    }

    // ------------------------------------------------------------------------------------------------- R1-025

    public function test_comment_texts_take_their_own_direction(): void
    {
        $video = $this->video($this->member, ['title' => 'Sunset drive']);
        $parent = $this->comment($video, $this->viewer, 'First! Loved the colours.');
        $this->comment($video, $this->viewer, 'Can you make a part two?', $parent);

        foreach ([route('user.index'), route('user.comments.index'), route('user.videos.show', $video)] as $url) {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~<p [^>]*dir="auto"[^>]*>First! Loved the colours\.</p>~', $html, $url);
        }
        $sheet = $this->actingAs($this->member)->get(route('user.comments.replies', ['comment' => $parent, 'part' => 1]))->getContent();
        $this->assertMatchesRegularExpression('~<p class="mk-replies__text" dir="auto">Can you make a part two\?</p>~', $sheet);
        $this->assertMatchesRegularExpression('~<p class="mk-replies__text" dir="auto">First! Loved the colours\.</p>~', $sheet);
    }

    // ------------------------------------------------------------------------------------------------- R1-069

    public function test_the_studio_shared_inline_script_stays_small(): void
    {
        // 1.2.3 round 1b (R1R-01): the studio's script is one cached file (resources/js/member/studio-pages.js, a Vite
        // input) printed once by the layout with the parts the page uses; the page carries no inline copy of it
        $this->video($this->member, ['title' => 'Sunset drive']);
        $this->fakeStudioScript();
        $html = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('window.__mkStudioScripts', $html, 'no inline studio script');
        $this->assertStringNotContainsString('var LAYERS', $html, 'no inline bars script');
        $this->assertSame(1, substr_count($html, '<script src="/build/assets/studio-pages-test.js" data-parts="core,lists" data-copied="Link copied"></script>'));
        $this->assertSame(1, substr_count($html, '<link rel="preload" as="script" href="/build/assets/studio-pages-test.js">'));

        $script = (string) file_get_contents(resource_path('js/member/studio-pages.js'));
        $this->assertStringContainsString('if (parts.length && !window.__mkStudioScripts) {', $script);
        $this->assertStringContainsString("window.Alpine.data('mkVideos'", $script);
        $this->assertStringContainsString("'resources/js/member/studio-pages.js'", (string) file_get_contents(base_path('vite.config.ts')));
    }

    // ------------------------------------------------------------------------------------- R1-026 (review FS-RV-01)

    public function test_my_videos_bulk_dialogs_are_filled_when_they_open_and_the_bars_share_one_cell(): void
    {
        foreach (range(1, 3) as $i) {
            $this->video($this->member, ['title' => 'Night drive diary '.$i]);
        }
        $html = $this->actingAs($this->member)->get(route('user.videos.index'))->assertOk()->getContent();
        $dialog = $this->between($html, '<div class="modal fade" id="mk-delete-videos"', '<div class="offcanvas offcanvas-end mk-drawer avd-mk" tabindex="-1" id="mk-visibility-sheet"');
        $sheet = $this->between($html, 'id="mk-visibility-sheet"', '</form>');

        // the bulk dialog and the sheet: written into their slots in an idle moment after a tick (or when one opens first),
        // no Alpine list or bindings, and no Alpine expression on their show events. The component (mkVideos) is in the
        // studio's cached script since round 1b (R1R-01); the page root names it.
        $this->assertMatchesRegularExpression('~<div class="avd-mk mk-page"\s+x-data="mkVideos"~', $html);
        $script = (string) file_get_contents(resource_path('js/member/studio-pages.js'));
        $component = substr($script, (int) strpos($script, "window.Alpine.data('mkVideos'"));
        $this->assertSame(2, substr_count($component, 'this.later();'), 'a tick and Select all / Clear ask for the idle write');
        $this->assertStringContainsString('window.requestIdleCallback(run, {timeout: 600})', $component);
        $this->assertStringContainsString("if (event.target.id === 'mk-delete-videos' && this.\$root._mkStale) this.fillAll();", $component);
        $this->assertStringNotContainsString('x-on:show-bs', $html);
        $this->assertStringNotContainsString('x-for', $dialog, 'no Alpine x-for: it cost more than the rest of opening the dialog at CPU x4');
        $this->assertStringNotContainsString('x-text', $dialog);
        foreach (['title', 'items', 'more', 'c', 'l', 'd', 'p', 'remove', 'private-btn', 'private'] as $slot) {
            $this->assertStringContainsString('data-mk-bulk="'.$slot.'"', $dialog, "slot {$slot}");
        }
        $this->assertMatchesRegularExpression('~<template data-mk-bulk="item"><li class="mk-item">~', $dialog);
        $this->assertStringContainsString('<h2 class="mk-sheet__title" id="mk-visibility-sheet-title" data-mk-bulk="title">', $sheet);
        $this->assertStringContainsString(':disabled="bulk.changeable === 0"', $html, 'the sheet\'s button still follows the chosen visibility');

        // no Alpine handler on every click of the page: the single Delete dialog reads its row when it opens
        $this->assertDoesNotMatchRegularExpression('~<div class="avd-mk mk-page"[^>]*@click=~s', substr($html, (int) strpos($html, '<div class="avd-mk mk-page"'), 12000));
        $this->assertStringContainsString("if (event.target.id === 'mk-delete-video') this.pick(Number((event.relatedTarget && event.relatedTarget.getAttribute('data-mk-delete')) || 0));", $component);

        // the toolbar and the bulk bar share one grid cell on the three pages that have both
        $this->playlist($this->member, []);
        $this->comment($this->video($this->member, ['title' => 'Harbour lights']), $this->viewer, 'Lovely colours.');
        foreach ([route('user.videos.index'), route('user.comments.index'), route('user.playlists.index')] as $url) {
            $page = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~<div class="mk-bars">\s*<form class="mk-toolbar"[^>]*>.*?</form>\s*<div class="mk-bulkbar" role="region"~s', $page, $url);
        }
    }

    // --------------------------------------------------------------------------------------------- R1-069 (pass 2)

    public function test_the_row_confirmation_script_is_only_on_pages_with_row_confirmations(): void
    {
        // 1.2.3 round 1b (R1R-01): the parts are named on the cached script's tag (data-parts); the row confirmation runs
        // only with "confirm" or "lists" (studio-pages.js), so a page without row confirmations still does not get it
        $video = $this->video($this->member, ['title' => 'Sunset drive']);
        $parent = $this->comment($video, $this->viewer, 'Which camera?');
        $this->comment($video, $this->viewer, 'A reply', $parent);
        $this->fakeStudioScript();
        $parts = function (string $url): array {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match('~<script src="/build/assets/studio-pages-test\.js"(?: data-parts="([a-z,]+)")?~', $html, $m), $url);

            return explode(',', $m[1] ?? '');
        };

        $with = [route('user.index'), route('user.comments.index'), route('user.comments.replies', $parent), route('user.reports.index'), route('user.videos.index'), route('user.playlists.index')];
        foreach ($with as $url) {
            $list = $parts($url);
            $this->assertContains('core', $list, $url);
            $this->assertNotSame([], array_intersect(['confirm', 'lists'], $list), $url.' has row confirmations');
        }
        $without = [route('user.videos.edit', $video), route('user.videos.show', $video), route('user.subscribers'), route('user.playlists.create')];
        foreach ($without as $url) {
            $list = $parts($url);
            $this->assertContains('core', $list, $url.' keeps the core');
            $this->assertSame([], array_values(array_intersect(['confirm', 'lists'], $list)), $url.' has no row confirmation: the part does not run');
        }
        $script = (string) file_get_contents(resource_path('js/member/studio-pages.js'));
        $this->assertStringContainsString("if (has('confirm') || has('lists')) {", $script);
        $this->assertStringContainsString("const form = event.target.querySelector('form[data-mk-action-from]');", $script);

        // the head's "Upload failed" watcher only where its template is (a draft still being processed)
        $this->assertStringNotContainsString('<template data-mk-failed-status>', $this->actingAs($this->member)->get(route('user.videos.edit', $video))->getContent());
        $draft = $this->video($this->member, ['title' => 'QA draft on Upload18', 'status' => VideoStatus::DRAFT->value, 'published_at' => null, 'uploaded_at' => null, 'upload18_vid' => 'qa-did-9', 'embed_code' => null]);
        $this->assertStringContainsString('<template data-mk-failed-status>', $this->actingAs($this->member)->get(route('user.videos.create', $draft))->getContent());
        $this->assertStringContainsString("const failed = document.querySelector('template[data-mk-failed-status]');", $script);
    }

    public function test_video_pages_carry_only_the_thumbnail_crop_texts(): void
    {
        $video = $this->video($this->member, ['title' => 'Sunset drive']);
        $draft = $this->video($this->member, ['title' => 'Untitled upload 0425', 'status' => VideoStatus::DRAFT->value, 'published_at' => null]);

        foreach ([route('user.videos.edit', $video), route('user.videos.create', $draft)] as $url) {
            $html = $this->actingAs($this->member)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~<h2 class="mk-sheet__title" id="cropTitle">Change thumbnail</h2>~', $html, $url);
            $this->assertStringNotContainsString('data-help=', $html, $url.': no texts of the other pictures (avatar, banner)');
            $this->assertStringNotContainsString('Change banner', $html, $url);
        }
    }

    // ---------------------------------------------------------------------------------------------------- helpers

    /** The build's studio script at a fixed address (the tests run without Vite: R1R-01's tag prints only with a build). */
    private function fakeStudioScript(): void
    {
        $this->swap(\Illuminate\Foundation\Vite::class, new class extends \Illuminate\Foundation\Vite
        {
            public function asset($asset, $buildDirectory = null)
            {
                return $asset === 'resources/js/member/studio-pages.js' ? '/build/assets/studio-pages-test.js' : '';
            }

            public function __invoke($entrypoints, $buildDirectory = null)
            {
                return new \Illuminate\Support\HtmlString('');
            }
        });
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'not found: '.$from);
        $end = strpos($html, $to, (int) $start + strlen($from));

        return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
    }

    /** @return array<string, mixed> */
    private function rowsJson(string $html, string $id): array
    {
        preg_match('~<script type="application/json" id="'.preg_quote($id, '~').'">(.*?)</script>~s', $html, $m);

        return json_decode($m[1] ?? 'null', true) ?? [];
    }

    private function countQueries(\Closure $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $run();

        // a listener cannot be removed: the count is read (copied) right after the run
        return $count;
    }

    private function skipUnlessIntl(): void
    {
        if (! class_exists(\IntlDatePatternGenerator::class)) {
            $this->markTestSkipped('intl is not installed');
        }
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

    private function comment(Video $video, User $author, string $text, ?Comment $parent = null, bool $hidden = false): Comment
    {
        return Comment::query()->create([
            'video_id' => $video->id,
            'user_id' => $author->id,
            'parent_id' => $parent?->id,
            'content' => $text,
            'banned_at' => $hidden ? now()->subMinute() : null,
        ]);
    }

    /** @param list<int> $videoIds */
    private function playlist(User $owner, array $videoIds): Playlist
    {
        $playlist = Playlist::factory()->for($owner)->create(['status' => PlaylistStatus::PUBLIC, 'sort' => PlaylistSort::MANUAL, 'is_deletable' => true]);

        foreach (array_values($videoIds) as $i => $id) {
            DB::table('playlist_has_videos')->insert(['playlist_id' => $playlist->id, 'video_id' => $id, 'position' => $i, 'added_at' => now()->subMinutes(100 - $i)]);
        }

        return $playlist;
    }

    private function transaction(User $member, \DateTimeInterface $date): Transaction
    {
        return Transaction::query()->create([
            'payment_provider' => 'stripe',
            'stripe_id' => 'in_fs_'.uniqid(),
            'amount' => 500,
            'tax' => 0,
            'fee' => 45,
            'date' => $date,
            'user_id' => $member->id,
            'subscription_id' => null,
            'name' => 'Nightdrive TV',
            'address' => '1 Harbour road',
            'city' => 'Lyon',
            'postal_code' => '69001',
            'country' => 'FR',
            'vat_id' => null,
        ]);
    }
}
