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
use Tests\TestCase;

class ApiVideoViewCooldownTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_guest_views_inside_cooldown_only_increment_once(): void
    {
        Cache::flush();
        config([
            'site.view_cooldown_minutes' => 5,
            'site.view_log_mode' => ViewHistoryRetentionService::LOG_MODE_DETAILED,
        ]);

        $video = $this->publicVideo();
        $headers = ['User-Agent' => 'CooldownFeatureTest/1.0'];
        $server = ['REMOTE_ADDR' => '203.0.113.25'];

        $this->withHeaders($headers)
            ->withServerVariables($server)
            ->post(route('videos.view', $video->uuid))
            ->assertNoContent();

        $this->withHeaders($headers)
            ->withServerVariables($server)
            ->post(route('videos.view', $video->uuid))
            ->assertNoContent();

        $this->assertSame(1, $video->refresh()->views);
        $this->assertSame(1, View::query()->where('video_id', $video->id)->count());
    }

    private function publicVideo(): Video
    {
        $category = Category::query()->create([
            'title' => 'Cooldown',
            'slug' => 'cooldown',
            'icon' => 'film',
            'in_menu' => true,
        ]);
        $creator = User::factory()->create();

        return Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
                'views' => 0,
            ]);
    }
}
