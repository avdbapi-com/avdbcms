<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use App\Services\ViewHistoryRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ViewStorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'site.view_cooldown_minutes' => 0,
            'site.view_daily_stats_enabled' => true,
            'site.view_history_retention_days' => 30,
        ]);
    }

    public function test_counter_only_mode_updates_totals_and_daily_stats_without_raw_view_rows(): void
    {
        config(['site.view_log_mode' => ViewHistoryRetentionService::LOG_MODE_COUNTER_ONLY]);

        $video = $this->publicVideo();

        $this->assertTrue(app(ViewHistoryRetentionService::class)->dailyStatsEnabled());

        $this->post(route('videos.view', $video->uuid))
            ->assertNoContent();

        $this->assertSame(1, $video->refresh()->views);
        $this->assertSame(0, View::query()->where('video_id', $video->id)->count());
        $this->assertSame(1, DB::table('video_view_daily_stats')->count());
        $this->assertSame(1, (int) DB::table('video_view_daily_stats')
            ->where('video_id', $video->id)
            ->whereDate('view_date', now()->toDateString())
            ->value('views_count'));
    }

    public function test_authenticated_mode_keeps_member_history_but_skips_guest_rows(): void
    {
        config(['site.view_log_mode' => ViewHistoryRetentionService::LOG_MODE_AUTHENTICATED]);

        $video = $this->publicVideo();
        $viewer = User::factory()->create();

        $this->post(route('videos.view', $video->uuid))
            ->assertNoContent();

        $this->actingAs($viewer)
            ->post(route('videos.view', $video->uuid))
            ->assertNoContent();

        $this->assertSame(2, $video->refresh()->views);
        $this->assertSame(1, View::query()->where('video_id', $video->id)->count());
        $this->assertDatabaseHas('views', [
            'video_id' => $video->id,
            'user_id' => $viewer->id,
        ]);
        $this->assertSame(2, (int) DB::table('video_view_daily_stats')
            ->where('video_id', $video->id)
            ->whereDate('view_date', now()->toDateString())
            ->value('views_count'));
    }

    public function test_detailed_mode_keeps_guest_raw_history_when_enabled(): void
    {
        config(['site.view_log_mode' => ViewHistoryRetentionService::LOG_MODE_DETAILED]);

        $video = $this->publicVideo();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post(route('videos.view', $video->uuid))
            ->assertNoContent();

        $this->assertSame(1, $video->refresh()->views);
        $this->assertDatabaseHas('views', [
            'video_id' => $video->id,
            'user_id' => null,
            'ip' => '198.51.100.10',
        ]);
    }

    public function test_retention_prunes_old_raw_rows_without_touching_video_total(): void
    {
        config(['site.view_history_retention_days' => 30]);

        $video = $this->publicVideo(['views' => 25]);
        $old = View::query()->create([
            'video_id' => $video->id,
            'user_id' => null,
            'ip' => '127.0.0.1',
            'view_at' => now()->subDays(45),
        ]);
        $fresh = View::query()->create([
            'video_id' => $video->id,
            'user_id' => null,
            'ip' => '127.0.0.2',
            'view_at' => now()->subDays(5),
        ]);

        $deleted = app(ViewHistoryRetentionService::class)->prune(now(), 10);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('views', ['id' => $old->id]);
        $this->assertDatabaseHas('views', ['id' => $fresh->id]);
        $this->assertSame(25, $video->refresh()->views);
    }

    private function publicVideo(array $attributes = []): Video
    {
        $category = Category::query()->create([
            'title' => 'Scale Views',
            'slug' => 'scale-views',
            'icon' => 'film',
            'in_menu' => true,
        ]);

        return Video::factory()
            ->for($category)
            ->for(User::factory(), 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create(array_merge([
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
                'views' => 0,
            ], $attributes));
    }
}
