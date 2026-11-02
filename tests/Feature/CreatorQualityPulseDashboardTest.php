<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use App\Notifications\Support\NotificationTasks;
use App\Support\SpotlightChips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreatorQualityPulseDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_quality_pulse_renders_dashboard_metrics_for_uploaders(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => true,
            'site.creator_quality_pulse_limit' => 2,
            'site.creator_quality_pulse_lookback_days' => 30,
        ]);

        $creator = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory('Pulse');
        $video = $this->createPublicVideo('Quality Pulse Candidate', $category, $creator);

        Thumbnail::factory()->active()->for($video)->create();
        View::query()->create([
            'video_id' => $video->id,
            'user_id' => $commenter->id,
            'ip' => '127.0.5.1',
            'view_at' => now()->subHour(),
        ]);
        Comment::factory()->for($video)->for($commenter, 'user')->create([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        // 1.2.3 (member design 6.2): the pulse is the dashboard's "Tips for your videos", one sentence per video
        // (the next useful step for it: a picture, a description, a playlist, else its recent views and comments).
        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Tips for your videos')
            ->assertSee('Quality Pulse Candidate');
    }

    public function test_creator_quality_pulse_is_hidden_when_disabled(): void
    {
        config(['site.creator_quality_pulse_enabled' => false]);

        $creator = User::factory()->create();
        $category = $this->createCategory('Pulse');
        $this->createPublicVideo('Hidden Pulse Candidate', $category, $creator);

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertDontSee('Tips for your videos');
    }

    public function test_creator_quality_pulse_shows_low_data_fallback_when_enabled_without_recent_uploads(): void
    {
        config(['site.creator_quality_pulse_enabled' => true]);

        $creator = User::factory()->create();

        // studio-36: a member without videos gets ONE empty state ("Upload your first video") instead of
        // five zero cards, seven empty panels and three import buttons; the tips come with the first video.
        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Upload your first video')
            ->assertDontSee('Tips for your videos');
    }

    public function test_creator_dashboard_query_count_stays_bounded_with_pulse_data(): void
    {
        $this->withoutVite();

        config([
            'site.creator_quality_pulse_enabled' => true,
            'site.creator_quality_pulse_limit' => 3,
            'site.creator_quality_pulse_lookback_days' => 30,
        ]);

        $creator = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory('Pulse');

        foreach (range(1, 3) as $index) {
            $video = $this->createPublicVideo('Query Count Pulse ' . $index, $category, $creator);
            Thumbnail::factory()->active()->for($video)->create();
            View::query()->create([
                'video_id' => $video->id,
                'user_id' => $viewer->id,
                'ip' => '127.0.8.' . $index,
                'view_at' => now()->subMinutes($index),
            ]);
            Comment::factory()->for($video)->for($viewer, 'user')->create([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $queryCount = $this->dashboardQueryCount($creator, ['Tips for your videos', 'Query Count Pulse']);

        // 41 = the studio dashboard's own queries + the account panel's two counts (My videos, Watch later; cached per
        // member for a minute in layouts/menus/account.blade.php, so a cold cache pays them here)
        $this->assertLessThanOrEqual(41, $queryCount, 'Creator dashboard populated query count exceeded bound.');
    }

    public function test_creator_dashboard_query_count_stays_bounded_with_pulse_fallback(): void
    {
        $this->withoutVite();

        config(['site.creator_quality_pulse_enabled' => true]);

        // made before counting: the factory insert and UserObserver's avatar queries are fixture setup, not the dashboard
        $creator = User::factory()->create();

        $queryCount = $this->dashboardQueryCount($creator, ['Upload your first video']);

        // The earlier bound, 32, counted the member's creation too (3 queries: the insert, the avatar candidate read and
        // the avatar write), so the request itself had 29. Measured: 26 on SQLite and MariaDB.
        $this->assertLessThanOrEqual(29, $queryCount, 'Creator dashboard fallback query count exceeded bound.');
    }

    /**
     * The dashboard lists the newest videos, comments, subscribers and likes (dashboard_widget_limit rows each): five
     * rows must cost what one row costs, for public videos, drafts, private videos (the thumbnail asks every video that
     * is not public for its owner) and a link format that prints the creator and the category.
     */
    public function test_creator_dashboard_query_count_does_not_grow_with_listed_rows(): void
    {
        $this->withoutVite();

        config([
            'site.creator_quality_pulse_enabled' => true,
            'site.creator_quality_pulse_limit' => 5,
            'site.creator_quality_pulse_lookback_days' => 30,
            'site.dashboard_widget_limit' => 5,
        ]);

        $passes = [
            'public videos' => [VideoStatus::PUBLIC, true, null],
            'drafts' => [VideoStatus::DRAFT, false, null],
            'private videos' => [VideoStatus::PRIVATE, true, null],
            'link format with creator and category' => [VideoStatus::PUBLIC, true, '{category}/{user}/{slug}-{uuid}'],
        ];

        foreach ($passes as $label => [$status, $engaged, $linkFormat]) {
            if ($linkFormat !== null) {
                config(['site.video_url_pattern' => $linkFormat]);
            }

            $creator = User::factory()->create();
            $category = $this->createCategory('Rows '.$label);

            $this->addListedRows($creator, $category, 1, 1, $status, $engaged);
            $oneRow = $this->dashboardQueryCount($creator, ['Query Count Pulse 1']);

            $this->addListedRows($creator, $category, 2, 5, $status, $engaged);
            $fiveRows = $this->dashboardQueryCount($creator, ['Query Count Pulse 5']);

            $this->assertSame($oneRow, $fiveRows, "Creator dashboard runs queries per listed video, comment, subscriber or like ({$label}).");
        }
    }

    public function test_creator_dashboard_comments_use_qualified_created_at_ordering(): void
    {
        config(['site.creator_quality_pulse_enabled' => false]);

        $creator = User::factory()->create();
        $commenter = User::factory()->create();
        $category = $this->createCategory('Pulse');
        $video = $this->createPublicVideo('Commented Creator Video', $category, $creator);

        Comment::factory()->for($video)->for($commenter, 'user')->create([
            'content' => 'Older dashboard comment',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        Comment::factory()->for($video)->for($commenter, 'user')->create([
            'content' => 'Newest dashboard comment',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Newest dashboard comment',
                'Older dashboard comment',
            ]);
    }

    public function test_studio_health_alerts_render_dashboard_alerts(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => false,
            'site.studio_health_alerts_enabled' => true,
            'site.studio_health_alerts_limit' => 3,
            'site.studio_health_alerts_lookback_days' => 30,
        ]);

        $creator = User::factory()->create();
        $category = $this->createCategory('Studio');
        $this->createPublicVideo('Dashboard Missing Thumbnail Alert', $category, $creator);

        // 1.2.3 (member design 6.2): the alerts and the recovery checklist are one "Needs your attention" list,
        // each item a sentence naming the video and one action.
        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Needs your attention')
            ->assertSee('No picture chosen: Dashboard Missing Thumbnail Alert')
            ->assertSee('Pick a picture');
    }

    public function test_studio_health_alerts_show_no_alert_fallback(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => false,
            'site.studio_health_alerts_enabled' => true,
        ]);

        $creator = User::factory()->create();
        $video = $this->createPublicVideo('Healthy Video', $this->createCategory('Studio'), $creator);
        Thumbnail::factory()->active()->for($video)->create();
        View::query()->create(['video_id' => $video->id, 'user_id' => null, 'ip' => '127.0.7.1', 'view_at' => now()->subHour()]);
        // a counted view (ViewService::incrementView adds it to the video's views before it writes the row above): since
        // 1.2.3 round 1 (R1-019) "No recent views" reads that count, as the Views tile does
        $video->increment('views');

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Needs your attention')
            ->assertSee('All good: nothing needs your attention.');
    }

    public function test_creator_recovery_checklist_shows_no_item_fallback(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => false,
            'site.studio_health_alerts_enabled' => false,
            'site.creator_recovery_checklist_enabled' => true,
        ]);

        $creator = User::factory()->create();
        $video = $this->createPublicVideo('Healthy Video', $this->createCategory('Studio'), $creator);
        Thumbnail::factory()->active()->for($video)->create();
        View::query()->create(['video_id' => $video->id, 'user_id' => null, 'ip' => '127.0.7.1', 'view_at' => now()->subHour()]);

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Needs your attention')
            ->assertSee('All good: nothing needs your attention.');
    }

    public function test_the_attention_list_is_gone_when_both_its_sources_are_off(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => false,
            'site.studio_health_alerts_enabled' => false,
            'site.creator_recovery_checklist_enabled' => false,
        ]);

        $creator = User::factory()->create();
        $this->createPublicVideo('Quiet Video', $this->createCategory('Studio'), $creator);

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Quiet Video')
            ->assertDontSee('Needs your attention')
            ->assertDontSee('Tips for your videos');
    }

    /**
     * The queries of one dashboard view, always from the same state: an empty cache except the site-wide Ctrl+K chip
     * check (a live site refreshes it once an hour per locale; its own cost is bounded in SpotlightChipsTest), the
     * notification task table known to exist (as on a site after "Finish update", whatever ran earlier in this
     * process), and a fresh member instance (nothing loaded by an earlier request of this test).
     *
     * @param  list<string>  $see
     */
    private function dashboardQueryCount(User $creator, array $see): int
    {
        Cache::flush();
        SpotlightChips::all();
        NotificationTasks::flushState();
        NotificationTasks::available();
        $member = $creator->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($member)->get(route('user.index'));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        foreach ($see as $text) {
            $response->assertSee($text);
        }

        return $queryCount;
    }

    /**
     * For each index: a video with an active thumbnail; when engaged also a view and a comment by a new member, and a
     * new subscriber who likes the video.
     */
    private function addListedRows(User $creator, Category $category, int $from, int $to, VideoStatus $status, bool $engaged): void
    {
        foreach (range($from, $to) as $index) {
            $video = $this->createVideo('Query Count Pulse ' . $index, $category, $creator, $status);
            Thumbnail::factory()->active()->for($video)->create();
            // The factory dates a video up to a year back; the recovery checklist lists drafts and private videos not
            // touched for 21 days. A fixed age keeps every row on that list, so one row and five rows show the same parts.
            Video::query()->whereKey($video->id)->update(['created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40)]);

            if (! $engaged) {
                continue;
            }

            $commenter = User::factory()->create();
            View::query()->create([
                'video_id' => $video->id,
                'user_id' => $commenter->id,
                'ip' => '127.0.9.' . $index,
                'view_at' => now()->subMinutes($index),
            ]);
            Comment::factory()->for($video)->for($commenter, 'user')->create([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
            $fan = User::factory()->create();
            DB::table('subscriptions')->insert([
                'user_id' => $creator->id,
                'subscriber_id' => $fan->id,
                'subscribe_at' => now()->subMinutes($index),
                'read_at' => now(),
            ]);
            DB::table('interactions')->insert([
                'user_id' => $fan->id,
                'likeable_type' => $video->getMorphClass(),
                'likeable_id' => $video->id,
                'status' => true,
                'perform_at' => now()->subMinutes($index),
            ]);
        }
    }

    private function createCategory(string $title): Category
    {
        return Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'gauge',
            'in_menu' => true,
            'position' => 1,
        ]);
    }

    private function createPublicVideo(string $title, Category $category, User $creator): Video
    {
        return $this->createVideo($title, $category, $creator, VideoStatus::PUBLIC);
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
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
                'language' => null,
            ]);
    }
}
