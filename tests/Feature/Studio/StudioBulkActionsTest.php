<?php

namespace Tests\Feature\Studio;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 1.2.3 (member design 6.3, 6.6): the bulk actions of My videos. "Visibility" changes the member's OWN videos only
 * (another member's id in the list is ignored), keeps drafts, failed uploads and banned videos as they are and says
 * how many kept their state; "Public" sets the publication date once and keeps an earlier one. The messages are
 * translated plural forms ("Deleted 2 videos.", not "Deleted 2 video(s)").
 */
class StudioBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // a deleted video takes its files with it: never the copy's real disks
        foreach ([null, 'public', 'thumbnails', 'videos'] as $disk) {
            Storage::fake($disk);
        }
    }

    public function test_visibility_changes_only_the_members_own_videos(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->video($member, VideoStatus::PUBLIC);
        $theirs = $this->video($other, VideoStatus::PUBLIC);

        $this->actingAs($member)
            ->post(route('user.videos.mass-visibility'), ['ids' => [$mine->id, $theirs->id], 'visibility' => 'private'])
            ->assertRedirect()
            ->assertSessionHas('success', '1 video is private now.');

        $this->assertSame(VideoStatus::PRIVATE, $mine->fresh()->status);
        $this->assertSame(VideoStatus::PUBLIC, $theirs->fresh()->status, "another member's video must never change");
    }

    public function test_drafts_failed_and_banned_videos_keep_their_state(): void
    {
        $member = User::factory()->create();
        $public = $this->video($member, VideoStatus::PUBLIC);
        $draft = $this->video($member, VideoStatus::DRAFT);
        $failed = $this->video($member, VideoStatus::FAILED);
        $banned = $this->video($member, VideoStatus::BANNED);

        $this->actingAs($member)
            ->post(route('user.videos.mass-visibility'), ['ids' => [$public->id, $draft->id, $failed->id, $banned->id], 'visibility' => 'unlisted'])
            ->assertSessionHas('success', '1 video is unlisted now. 3 drafts or failed uploads kept their state.');

        $this->assertSame(VideoStatus::UNLISTED, $public->fresh()->status);
        $this->assertSame(VideoStatus::DRAFT, $draft->fresh()->status);
        $this->assertSame(VideoStatus::FAILED, $failed->fresh()->status);
        $this->assertSame(VideoStatus::BANNED, $banned->fresh()->status);
    }

    public function test_nothing_changeable_is_an_information_not_a_success(): void
    {
        $member = User::factory()->create();
        $draft = $this->video($member, VideoStatus::DRAFT);

        $this->actingAs($member)
            ->post(route('user.videos.mass-visibility'), ['ids' => [$draft->id], 'visibility' => 'public'])
            ->assertSessionHas('info', '0 videos are public now. 1 draft or failed upload kept its state.');
    }

    public function test_public_sets_the_publication_date_once(): void
    {
        $member = User::factory()->create();
        $neverPublic = $this->video($member, VideoStatus::PRIVATE, ['published_at' => null]);
        $earlier = now()->subMonth()->startOfMinute();
        $wasPublic = $this->video($member, VideoStatus::UNLISTED, ['published_at' => $earlier]);
        $scheduled = $this->video($member, VideoStatus::PLANNED, ['scheduled_at' => now()->addWeek(), 'published_at' => now()->addWeek()]);

        $this->actingAs($member)
            ->post(route('user.videos.mass-visibility'), ['ids' => [$neverPublic->id, $wasPublic->id, $scheduled->id], 'visibility' => 'public'])
            ->assertSessionHas('success', '3 videos are public now.');

        $this->assertNotNull($neverPublic->fresh()->published_at);
        $this->assertTrue($neverPublic->fresh()->published_at->lessThanOrEqualTo(now()));
        $this->assertTrue($wasPublic->fresh()->published_at->equalTo($earlier), 'an earlier publication date is kept');
        $this->assertNull($scheduled->fresh()->scheduled_at, 'a scheduled video made public now has no schedule any more');
        $this->assertTrue($scheduled->fresh()->published_at->lessThanOrEqualTo(now()));
    }

    public function test_an_unknown_visibility_is_refused(): void
    {
        $member = User::factory()->create();
        $video = $this->video($member, VideoStatus::PUBLIC);

        $this->actingAs($member)
            ->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'banned'])
            ->assertSessionHasErrors('visibility');

        $this->assertSame(VideoStatus::PUBLIC, $video->fresh()->status);
    }

    public function test_bulk_delete_says_how_many_in_a_plural_form(): void
    {
        $member = User::factory()->create();
        $videos = [$this->video($member, VideoStatus::PUBLIC), $this->video($member, VideoStatus::PRIVATE)];

        $this->actingAs($member)
            ->delete(route('user.videos.mass-delete'), ['ids' => array_map(fn (Video $video) => $video->id, $videos)])
            ->assertSessionHas('success', 'Deleted 2 videos.');
    }

    private function video(User $owner, VideoStatus $status, array $attributes = []): Video
    {
        return Video::factory()->for($owner, 'user')->create(array_merge([
            'status' => $status->value,
            'category_id' => null,
            'language' => null,
            'published_at' => $status === VideoStatus::PUBLIC ? now()->subDays(3) : null,
            'uploaded_at' => $status === VideoStatus::FAILED ? null : now()->subDays(3),
        ], $attributes));
    }
}
