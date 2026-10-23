<?php

namespace Tests\Unit;

use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Report;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use App\Services\StudioHealthAlertsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioHealthAlertsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_are_empty_when_studio_health_alerts_are_disabled(): void
    {
        config(['site.studio_health_alerts_enabled' => false]);

        $creator = User::factory()->create();
        $category = $this->createCategory('Studio Health');
        $this->createFailedVideo('Disabled Failed Upload', $category, $creator, now());

        $this->assertTrue(app(StudioHealthAlertsService::class)->alertsFor($creator)->isEmpty());
    }

    public function test_invalid_limit_and_lookback_settings_are_clamped_before_querying(): void
    {
        config([
            'site.studio_health_alerts_enabled' => true,
            'site.studio_health_alerts_limit' => 99,
            'site.studio_health_alerts_lookback_days' => 0,
        ]);

        $creator = User::factory()->create();
        $category = $this->createCategory('Studio Health');
        $this->createPublicVideo('Outside One Day Window', $category, $creator, now()->subDays(2));

        foreach (range(1, 13) as $index) {
            $this->createPublicVideo(
                'Recent Health Candidate ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                $category,
                $creator,
                now()->subMinutes($index)
            );
        }

        $alerts = app(StudioHealthAlertsService::class)->alertsFor($creator);

        $this->assertCount(StudioHealthAlertsService::MAX_LIMIT, $alerts);
        $this->assertFalse($alerts->pluck('video.title')->contains('Outside One Day Window'));
        $this->assertSame('missing_thumbnail', $alerts->first()['type']);
    }

    public function test_failed_upload_alerts_are_scoped_to_the_creator(): void
    {
        $this->enableStudioHealth();

        $creator = User::factory()->create();
        $otherCreator = User::factory()->create();
        $category = $this->createCategory('Studio Health');
        $ownVideo = $this->createFailedVideo('Own Failed Upload', $category, $creator, now()->subHour());
        $this->createFailedVideo('Other Failed Upload', $category, $otherCreator, now()->subHour());
        $this->createFailedVideo('Old Failed Upload', $category, $creator, now()->subDays(30));

        $alerts = app(StudioHealthAlertsService::class)->alertsFor($creator);

        $this->assertSame(['upload_failed'], $alerts->pluck('type')->all());
        $this->assertSame($ownVideo->id, $alerts->first()['video']->id);
        $this->assertFalse($alerts->pluck('video.title')->contains('Other Failed Upload'));
        $this->assertFalse($alerts->pluck('video.title')->contains('Old Failed Upload'));
    }

    public function test_recent_report_alerts_are_scoped_to_creator_owned_videos(): void
    {
        $this->enableStudioHealth();

        $creator = User::factory()->create();
        $otherCreator = User::factory()->create();
        $reporter = User::factory()->create();
        $category = $this->createCategory('Studio Health');
        $ownVideo = $this->createPublicVideo('Own Reported Video', $category, $creator, now()->subDay());
        $otherVideo = $this->createPublicVideo('Other Reported Video', $category, $otherCreator, now()->subDay());
        $oldVideo = $this->createPublicVideo('Old Reported Video', $category, $creator, now()->subDays(20));

        Thumbnail::factory()->active()->for($ownVideo)->create();
        Thumbnail::factory()->active()->for($otherVideo)->create();
        Thumbnail::factory()->active()->for($oldVideo)->create();
        $this->createPendingReport($ownVideo, $reporter, now()->subHour());
        $this->createPendingReport($otherVideo, $reporter, now()->subHour());
        $this->createPendingReport($oldVideo, $reporter, now()->subDays(30));
        $this->createRecentView($ownVideo, $reporter);

        $alerts = app(StudioHealthAlertsService::class)->alertsFor($creator);

        $this->assertSame(['recent_reports'], $alerts->pluck('type')->all());
        $this->assertSame($ownVideo->id, $alerts->first()['video']->id);
        $this->assertSame(1, $alerts->first()['count']);
        $this->assertFalse($alerts->pluck('video.title')->contains('Other Reported Video'));
        $this->assertFalse($alerts->pluck('video.title')->contains('Old Reported Video'));
    }

    public function test_missing_thumbnail_and_low_engagement_signals_are_scoped_to_creator_videos(): void
    {
        $this->enableStudioHealth();

        $creator = User::factory()->create();
        $otherCreator = User::factory()->create();
        $viewer = User::factory()->create();
        $category = $this->createCategory('Studio Health');
        $missingThumbnail = $this->createPublicVideo('Own Missing Thumbnail', $category, $creator, now()->subHour());
        $lowEngagement = $this->createPublicVideo('Own Low Engagement', $category, $creator, now()->subHours(2));
        $otherMissing = $this->createPublicVideo('Other Missing Thumbnail', $category, $otherCreator, now()->subHour());

        $this->createRecentView($missingThumbnail, $viewer);
        Thumbnail::factory()->active()->for($lowEngagement)->create();

        $alerts = app(StudioHealthAlertsService::class)->alertsFor($creator);

        $this->assertTrue($alerts->contains(fn (array $alert): bool => $alert['type'] === 'missing_thumbnail' && $alert['video']->id === $missingThumbnail->id));
        $this->assertTrue($alerts->contains(fn (array $alert): bool => $alert['type'] === 'low_engagement' && $alert['video']->id === $lowEngagement->id));
        $this->assertFalse($alerts->pluck('video.title')->contains('Other Missing Thumbnail'));
    }

    public function test_alert_videos_are_limited_to_creator_and_load_route_relations_once(): void
    {
        config([
            'site.studio_health_alerts_enabled' => true,
            'site.studio_health_alerts_limit' => 2,
            'site.studio_health_alerts_lookback_days' => 14,
        ]);

        $creator = User::factory()->create();
        $otherCreator = User::factory()->create();
        $category = $this->createCategory('Studio Health');

        foreach (range(1, 3) as $index) {
            $this->createPublicVideo('Creator Missing Thumbnail ' . $index, $category, $creator, now()->subHours($index));
        }

        $this->createPublicVideo('Other Creator Missing Thumbnail', $category, $otherCreator, now()->subHour());

        $alerts = app(StudioHealthAlertsService::class)->alertsFor($creator);

        $this->assertCount(2, $alerts);
        $this->assertSame(['missing_thumbnail', 'missing_thumbnail'], $alerts->pluck('type')->all());
        $this->assertFalse($alerts->pluck('video.title')->contains('Other Creator Missing Thumbnail'));

        foreach ($alerts as $alert) {
            /** @var Video $video */
            $video = $alert['video'];

            $this->assertSame($creator->id, $video->user_id);
            $this->assertTrue($video->relationLoaded('user'));
            $this->assertTrue($video->relationLoaded('category'));
        }
    }

    private function enableStudioHealth(): void
    {
        config([
            'site.studio_health_alerts_enabled' => true,
            'site.studio_health_alerts_limit' => 5,
            'site.studio_health_alerts_lookback_days' => 14,
        ]);
    }

    private function createCategory(string $title): Category
    {
        return Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'shield',
            'in_menu' => true,
            'position' => 1,
        ]);
    }

    private function createPublicVideo(string $title, Category $category, User $creator, mixed $publishedAt): Video
    {
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

    private function createFailedVideo(string $title, Category $category, User $creator, mixed $updatedAt): Video
    {
        return Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::FAILED)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => null,
                'uploaded_at' => null,
                'language' => null,
                'updated_at' => $updatedAt,
                'created_at' => $updatedAt,
            ]);
    }

    private function createPendingReport(Video $video, User $reporter, mixed $createdAt): Report
    {
        return Report::factory()
            ->for($reporter, 'user')
            ->create([
                'reportable_type' => $video->getMorphClass(),
                'reportable_id' => $video->id,
                'status' => ReportStatus::PENDING,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
    }

    private function createRecentView(Video $video, User $viewer): void
    {
        View::query()->create([
            'video_id' => $video->id,
            'user_id' => $viewer->id,
            'ip' => '127.0.7.1',
            'view_at' => now()->subMinutes(10),
        ]);
    }
}
