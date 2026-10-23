<?php

namespace Tests\Unit;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use App\Services\CreatorQualityPulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorQualityPulseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_videos_are_empty_when_creator_quality_pulse_is_disabled(): void
    {
        config(['site.creator_quality_pulse_enabled' => false]);

        $creator = User::factory()->create();
        $category = $this->createCategory('Pulse');
        $this->createPublicVideo('Disabled Pulse Candidate', $category, $creator);

        $this->assertTrue(app(CreatorQualityPulseService::class)->videosFor($creator)->isEmpty());
    }

    public function test_invalid_limit_and_lookback_settings_are_clamped_before_querying(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => true,
            'site.creator_quality_pulse_limit' => 99,
            'site.creator_quality_pulse_lookback_days' => 0,
        ]);

        $creator = User::factory()->create();
        $category = $this->createCategory('Pulse');
        $this->createPublicVideo('Outside One Day Window', $category, $creator, now()->subDays(2));

        foreach (range(1, 13) as $index) {
            $this->createPublicVideo(
                'Recent Pulse Candidate ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $category,
                $creator,
                now()->subMinutes($index)
            );
        }

        $videos = app(CreatorQualityPulseService::class)->videosFor($creator);

        $this->assertCount(CreatorQualityPulseService::MAX_LIMIT, $videos);
        $this->assertFalse($videos->contains('title', 'Outside One Day Window'));
        $this->assertSame('Recent Pulse Candidate 01', $videos->first()->title);
    }

    public function test_creator_quality_pulse_ignores_another_creators_videos_views_and_comments(): void
    {
        config([
            'site.creator_quality_pulse_enabled' => true,
            'site.creator_quality_pulse_limit' => 5,
            'site.creator_quality_pulse_lookback_days' => 30,
        ]);

        $creator = User::factory()->create();
        $otherCreator = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory('Pulse');
        $ownVideo = $this->createPublicVideo('Own Pulse Video', $category, $creator);
        $otherVideo = $this->createPublicVideo('Other Creator Pulse Video', $category, $otherCreator);

        Thumbnail::factory()->active()->for($ownVideo)->create();
        View::query()->create([
            'video_id' => $ownVideo->id,
            'user_id' => $viewer->id,
            'ip' => '127.0.6.1',
            'view_at' => now()->subHour(),
        ]);
        Comment::factory()->for($ownVideo)->for($viewer, 'user')->create([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        foreach (range(1, 3) as $index) {
            View::query()->create([
                'video_id' => $otherVideo->id,
                'user_id' => $viewer->id,
                'ip' => '127.0.7.' . $index,
                'view_at' => now()->subMinutes($index),
            ]);
            Comment::factory()->for($otherVideo)->for($viewer, 'user')->create([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $videos = app(CreatorQualityPulseService::class)->videosFor($creator);

        $this->assertCount(1, $videos);
        $this->assertSame('Own Pulse Video', $videos->first()->title);
        $this->assertSame(1, (int) $videos->first()->creator_quality_views_count);
        $this->assertSame(1, (int) $videos->first()->creator_quality_comments_count);
        $this->assertFalse($videos->contains('title', 'Other Creator Pulse Video'));
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

    private function createPublicVideo(string $title, Category $category, User $creator, mixed $publishedAt = null): Video
    {
        $publishedAt ??= now()->subDay();

        return Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => $publishedAt,
                'uploaded_at' => $publishedAt,
                'language' => null,
            ]);
    }
}
