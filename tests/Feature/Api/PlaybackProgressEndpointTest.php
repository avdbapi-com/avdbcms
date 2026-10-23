<?php

namespace Tests\Feature\Api;

use App\Enums\VideoStatus;
use App\Models\PlaybackProgressSignal;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybackProgressEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.playback_progress_signal_enabled' => true,
            'site.playback_progress_signal_min_duration_seconds' => 5,
            'site.playback_progress_signal_retention_days' => 90,
            'site.upload_max_duration' => 7200,
        ]);
    }

    public function test_progress_update_requires_authentication(): void
    {
        $video = $this->video(VideoStatus::PUBLIC);

        $this->postJson(route('videos.progress', $video), [
            'position_seconds' => 10,
            'duration_seconds' => 120,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('playback_progress_signals', 0);
    }

    public function test_progress_update_rejects_private_video_owned_by_another_user(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create();
        $video = $this->video(VideoStatus::PRIVATE, $creator);

        $this->actingAs($viewer)
            ->postJson(route('videos.progress', $video), [
                'position_seconds' => 10,
                'duration_seconds' => 120,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('playback_progress_signals', 0);
    }

    public function test_progress_update_rejects_malformed_payloads(): void
    {
        $viewer = User::factory()->create();
        $video = $this->video(VideoStatus::PUBLIC);

        $this->actingAs($viewer)
            ->postJson(route('videos.progress', $video), [
                'position_seconds' => 121,
                'duration_seconds' => 120,
                'played_at' => now()->addMinute()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['position_seconds', 'played_at']);

        $this->assertDatabaseCount('playback_progress_signals', 0);
    }

    public function test_progress_update_uses_authenticated_user_and_upserts_one_row_per_video(): void
    {
        $viewer = User::factory()->create();
        $attackerTarget = User::factory()->create();
        $video = $this->video(VideoStatus::PUBLIC);

        $this->actingAs($viewer)
            ->postJson(route('videos.progress', $video), [
                'user_id' => $attackerTarget->id,
                'position_seconds' => 30,
                'duration_seconds' => 120,
                'played_at' => now()->subMinute()->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('stored', true)
            ->assertJsonPath('position_seconds', 30)
            ->assertJsonPath('duration_seconds', 120)
            ->assertJsonPath('progress_basis_points', 2500);

        $this->actingAs($viewer)
            ->postJson(route('videos.progress', $video), [
                'user_id' => $attackerTarget->id,
                'position_seconds' => 45,
                'duration_seconds' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('stored', true)
            ->assertJsonPath('position_seconds', 45)
            ->assertJsonPath('progress_basis_points', 3750);

        $this->assertDatabaseCount('playback_progress_signals', 1);
        $this->assertDatabaseHas('playback_progress_signals', [
            'user_id' => $viewer->id,
            'video_id' => $video->id,
            'position_seconds' => 45,
            'duration_seconds' => 120,
            'progress_basis_points' => 3750,
        ]);
        $this->assertDatabaseMissing('playback_progress_signals', [
            'user_id' => $attackerTarget->id,
            'video_id' => $video->id,
        ]);

        $this->assertSame(1, PlaybackProgressSignal::query()
            ->where('user_id', $viewer->id)
            ->where('video_id', $video->id)
            ->count());
    }

    private function video(VideoStatus $status, ?User $creator = null): Video
    {
        return Video::factory()
            ->for($creator ?? User::factory()->create(), 'user')
            ->withStatus($status)
            ->create([
                'duration' => 120,
                'published_at' => now()->subHour(),
                'uploaded_at' => now()->subHour(),
                'language' => null,
            ]);
    }
}
