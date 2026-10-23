<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\PlaybackProgressSignal;
use App\Models\User;
use App\Models\Video;
use App\Services\PlaybackProgressSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybackProgressSignalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.playback_progress_signal_enabled' => true,
            'site.playback_progress_signal_min_duration_seconds' => 5,
            'site.playback_progress_signal_retention_days' => 30,
        ]);
    }

    public function test_ingest_upserts_one_row_per_viewer_video(): void
    {
        $viewer = User::factory()->create();
        $video = $this->video();
        $service = app(PlaybackProgressSignalService::class);

        $first = $service->ingest($viewer, $video, 25, 100, now()->subMinute());
        $second = $service->ingest($viewer, $video, 60, 100, now());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('playback_progress_signals', 1);
        $this->assertDatabaseHas('playback_progress_signals', [
            'id' => $first->id,
            'user_id' => $viewer->id,
            'video_id' => $video->id,
            'position_seconds' => 60,
            'duration_seconds' => 100,
            'progress_basis_points' => 6000,
        ]);
    }

    public function test_prune_command_deletes_only_expired_progress_rows_in_chunks(): void
    {
        $viewer = User::factory()->create();
        $video = $this->video();
        $secondExpiredVideo = $this->video();
        $activeVideo = $this->video();
        $noExpiryVideo = $this->video();

        PlaybackProgressSignal::query()->create([
            'user_id' => $viewer->id,
            'video_id' => $video->id,
            'position_seconds' => 20,
            'duration_seconds' => 100,
            'progress_basis_points' => 2000,
            'last_played_at' => now()->subDays(40),
            'expires_at' => now()->subDay(),
        ]);
        PlaybackProgressSignal::query()->create([
            'user_id' => $viewer->id,
            'video_id' => $activeVideo->id,
            'position_seconds' => 30,
            'duration_seconds' => 100,
            'progress_basis_points' => 3000,
            'last_played_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        PlaybackProgressSignal::query()->create([
            'user_id' => $viewer->id,
            'video_id' => $secondExpiredVideo->id,
            'position_seconds' => 25,
            'duration_seconds' => 100,
            'progress_basis_points' => 2500,
            'last_played_at' => now()->subDays(35),
            'expires_at' => now()->subMinute(),
        ]);
        PlaybackProgressSignal::query()->create([
            'user_id' => $viewer->id,
            'video_id' => $noExpiryVideo->id,
            'position_seconds' => 40,
            'duration_seconds' => 100,
            'progress_basis_points' => 4000,
            'last_played_at' => now(),
            'expires_at' => null,
        ]);

        $this->artisan('playback-progress:prune', ['--chunk' => 1])
            ->expectsOutput('Deleted 2 expired playback progress signal rows.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('playback_progress_signals', 2);
        $this->assertDatabaseMissing('playback_progress_signals', [
            'video_id' => $video->id,
        ]);
        $this->assertDatabaseMissing('playback_progress_signals', [
            'video_id' => $secondExpiredVideo->id,
        ]);
        $this->assertDatabaseHas('playback_progress_signals', [
            'video_id' => $activeVideo->id,
        ]);
        $this->assertDatabaseHas('playback_progress_signals', [
            'video_id' => $noExpiryVideo->id,
        ]);
    }

    private function video(): Video
    {
        return Video::factory()
            ->for(User::factory(), 'user')
            ->for(Category::query()->create([
                'title' => 'Playback Progress Test',
                'slug' => 'playback-progress-test-' . str()->random(8),
                'icon' => 'play',
                'in_menu' => true,
                'position' => 1,
            ]))
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'duration' => 100,
                'published_at' => now()->subHour(),
                'uploaded_at' => now()->subHour(),
                'language' => null,
            ]);
    }
}
