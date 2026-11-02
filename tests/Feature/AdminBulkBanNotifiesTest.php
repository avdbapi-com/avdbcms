<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Jobs\NotifyBannedVideos;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Video\VideoBanned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * R1-160: "Ban video" on one row tells the creator (VideoBanned: the bell and an e-mail); the bulk bar's Ban did not.
 * Now each creator hears of each video the bulk action banned (not of one that was banned already), from the queue.
 */
class AdminBulkBanNotifiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_ban_tells_each_creator_like_a_single_ban(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $first = Video::factory()->for($alice)->create(['status' => VideoStatus::PUBLIC->value]);
        $second = Video::factory()->for($bob)->create(['status' => VideoStatus::PRIVATE->value]);
        $already = Video::factory()->for($bob)->create(['status' => VideoStatus::BANNED->value, 'banned_at' => now()->subDay()]);

        $this->actingAs($admin)
            ->post(route('admin.bulk.videos'), ['action' => 'ban', 'ids' => [$first->id, $second->id, $already->id]])
            ->assertRedirect(route('admin.videos.index'))
            ->assertSessionHas('success', 'Banned 2 videos.');

        Notification::assertSentTo($alice, VideoBanned::class, 1);
        Notification::assertSentTo($bob, VideoBanned::class, 1);
        Notification::assertCount(2);

        // The same as one row's Ban: the single ban notifies its creator too.
        $third = Video::factory()->for($alice)->create(['status' => VideoStatus::PUBLIC->value]);
        $this->actingAs($admin)->post(route('admin.videos.ban', $third))->assertRedirect();
        Notification::assertCount(3);
    }

    public function test_the_notifications_go_through_the_queue(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $video = Video::factory()->for(User::factory())->create(['status' => VideoStatus::PUBLIC->value]);

        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['action' => 'ban', 'ids' => [$video->id]])->assertRedirect();

        Queue::assertPushed(NotifyBannedVideos::class, fn (NotifyBannedVideos $job): bool => $job->videoIds === [$video->id]);
    }

    public function test_a_video_unbanned_before_the_job_runs_is_left_out(): void
    {
        Notification::fake();

        $video = Video::factory()->for(User::factory())->create(['status' => VideoStatus::PUBLIC->value]);

        (new NotifyBannedVideos([$video->id]))->handle();

        Notification::assertNothingSent();
    }
}
