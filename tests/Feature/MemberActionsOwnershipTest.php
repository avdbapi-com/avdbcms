<?php

namespace Tests\Feature;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security plan S02 and S06: a member's own pages act only on what is the member's. Deleting a comment from "Comments
 * on my videos" works only for comments on the member's own videos, and cancelling a report only for the member's own
 * pending report; anything else answers 404 (as if it did not exist) and is left as it was.
 */
class MemberActionsOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_cannot_delete_a_comment_on_another_members_video(): void
    {
        [$owner, $attacker, $video] = $this->ownerAttackerAndVideo();
        $comment = Comment::factory()->for($owner, 'user')->for($video)->createQuietly();
        $comment->interactions()->create(['user_id' => $owner->id, 'status' => true, 'perform_at' => now()]);

        $this->actingAs($attacker)->delete('/profile/comments/'.$comment->id)->assertNotFound();

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
        $this->assertSame(1, $comment->interactions()->count(), 'its likes stay');
    }

    public function test_the_video_owner_still_deletes_a_comment_on_their_video(): void
    {
        [$owner, $attacker, $video] = $this->ownerAttackerAndVideo();
        $comment = Comment::factory()->for($attacker, 'user')->for($video)->createQuietly();
        $comment->interactions()->create(['user_id' => $owner->id, 'status' => true, 'perform_at' => now()]);

        $this->actingAs($owner)->from('/profile/comments')->delete('/profile/comments/'.$comment->id)
            ->assertRedirect('/profile/comments');

        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertSame(0, $comment->interactions()->count());
    }

    public function test_the_api_delete_keeps_its_own_rule(): void
    {
        [$owner, $attacker, $video] = $this->ownerAttackerAndVideo();
        $comment = Comment::factory()->for($owner, 'user')->for($video)->createQuietly();

        $this->actingAs($attacker)->deleteJson(route('comments.delete', [$video->uuid, $comment->id]))->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
    }

    public function test_a_member_cannot_cancel_another_members_report(): void
    {
        [$owner, $attacker, $video] = $this->ownerAttackerAndVideo();
        $report = $this->report($owner, $video, ReportStatus::PENDING);

        $this->actingAs($attacker)->post('/profile/reports/'.$report->id.'/cancel')->assertNotFound();

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
    }

    public function test_a_member_cancels_only_their_own_pending_report(): void
    {
        [$owner, , $video] = $this->ownerAttackerAndVideo();
        $pending = $this->report($owner, $video, ReportStatus::PENDING);

        $this->actingAs($owner)->post('/profile/reports/'.$pending->id.'/cancel')->assertRedirect('/profile/reports');
        $this->assertDatabaseMissing('reports', ['id' => $pending->id]);

        $done = $this->report($owner, $video, $this->processedStatus());
        $this->actingAs($owner)->post('/profile/reports/'.$done->id.'/cancel')->assertNotFound();
        $this->assertDatabaseHas('reports', ['id' => $done->id]);
    }

    /** @return array{0: User, 1: User, 2: Video} */
    private function ownerAttackerAndVideo(): array
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $video = Video::factory()->for($owner, 'user')->withStatus(VideoStatus::PUBLIC)->create(['allow_comments' => true]);

        return [$owner, $attacker, $video];
    }

    private function report(User $reporter, Video $video, ReportStatus $status): Report
    {
        return Report::query()->forceCreate([
            'user_id' => $reporter->id,
            'reportable_type' => Video::class,
            'reportable_id' => $video->id,
            'reason' => ReportReason::CAPTIONS_ISSUE->value,
            'comment' => 'A plain reason.',
            'status' => $status->value,
        ]);
    }

    private function processedStatus(): ReportStatus
    {
        foreach (ReportStatus::cases() as $case) {
            if ($case !== ReportStatus::PENDING) {
                return $case;
            }
        }

        $this->fail('ReportStatus has no processed state');
    }
}
