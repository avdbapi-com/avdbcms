<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Creator Quality Pulse')
            ->assertSee('Quality Pulse Candidate')
            ->assertSee('Recent views')
            ->assertSee('Recent comments')
            ->assertSee('Ready');
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
            ->assertDontSee('Creator Quality Pulse');
    }

    public function test_creator_quality_pulse_shows_low_data_fallback_when_enabled_without_recent_uploads(): void
    {
        config(['site.creator_quality_pulse_enabled' => true]);

        $creator = User::factory()->create();

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Creator Quality Pulse')
            ->assertSee('Upload videos to build your quality pulse')
            ->assertSee('Import');
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

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Creator Quality Pulse')
            ->assertSee('Query Count Pulse');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(50, $queryCount, 'Creator dashboard populated query count exceeded bound.');
    }

    public function test_creator_dashboard_query_count_stays_bounded_with_pulse_fallback(): void
    {
        $this->withoutVite();

        config(['site.creator_quality_pulse_enabled' => true]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs(User::factory()->create())
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Upload videos to build your quality pulse');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(32, $queryCount, 'Creator dashboard fallback query count exceeded bound.');
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

        $this->actingAs($creator)
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Studio Health Alerts')
            ->assertSee('Missing thumbnail')
            ->assertSee('Dashboard Missing Thumbnail Alert');
    }

    public function test_studio_health_alerts_show_no_alert_fallback(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => false,
            'site.studio_health_alerts_enabled' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Studio Health Alerts')
            ->assertSee('No urgent studio alerts');
    }

    public function test_creator_recovery_checklist_shows_no_item_fallback(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => false,
            'site.studio_health_alerts_enabled' => false,
            'site.creator_recovery_checklist_enabled' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('user.index'))
            ->assertOk()
            ->assertSee('Creator Recovery Checklist')
            ->assertSee('No stalled creator tasks');
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
        return Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
                'language' => null,
            ]);
    }
}
