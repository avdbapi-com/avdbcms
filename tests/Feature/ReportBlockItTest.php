<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Community > Reports > "Block it" acts on reported comments only (1.2.0). For a reported member it used to write
 * users.status, a column the users table does not have (the request failed with a database error), and for a reported
 * video only a block date that visitors' lists do not read (the video stayed visible). A member is blocked on their own
 * page (Block member, with its policy checks) and a video on its page (Ban video); the lists offer Block for comments
 * only, and the block address refuses the other types without changing anything.
 */
class ReportBlockItTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_it_hides_a_reported_comment_and_closes_every_report_about_it(): void
    {
        $site = $this->reportedSite();
        $second = $this->report($site['reporter2'], $site['comment']);

        $this->actingAs($site['admin'])
            ->post(route('admin.reports.block', ['type' => 'comment', 'reportable' => $site['comment']->id]))
            ->assertRedirect(route('admin.index'));

        $comment = $site['comment']->fresh();
        $this->assertNotNull($comment->banned_at, 'the comment has a block date');
        $this->assertFalse(Comment::query()->public()->whereKey($comment->id)->exists(), 'the comment is hidden from visitors');
        $this->assertSame(ReportStatus::BLOCKED, $site['reports']['comment']->fresh()->status);
        $this->assertSame(ReportStatus::BLOCKED, $second->fresh()->status, 'every report about the comment is closed');

        // The other reports wait as before.
        $this->assertSame(ReportStatus::PENDING, $site['reports']['user']->fresh()->status);
        $this->assertSame(ReportStatus::PENDING, $site['reports']['video']->fresh()->status);
    }

    public function test_block_it_is_refused_for_a_reported_member_and_changes_nothing(): void
    {
        $site = $this->reportedSite();

        $this->actingAs($site['admin'])
            ->post(route('admin.reports.block', ['type' => 'user', 'reportable' => $site['member']->id]))
            ->assertNotFound();

        $this->assertNull($site['member']->fresh()->banned_at, 'the member is not blocked from here (their page has Block member)');
        $this->assertSame(ReportStatus::PENDING, $site['reports']['user']->fresh()->status);
    }

    public function test_block_it_is_refused_for_a_reported_video_and_changes_nothing(): void
    {
        $site = $this->reportedSite();

        $this->actingAs($site['admin'])
            ->post(route('admin.reports.block', ['type' => 'video', 'reportable' => $site['video']->id]))
            ->assertNotFound();

        $video = $site['video']->fresh();
        $this->assertNull($video->banned_at, 'the video is not changed from here (its page has Ban video)');
        $this->assertSame(VideoStatus::PUBLIC, $video->status);
        $this->assertSame(ReportStatus::PENDING, $site['reports']['video']->fresh()->status);
    }

    public function test_the_reports_list_offers_block_it_for_comments_only(): void
    {
        config(['admin.ui' => 'v2']);
        $site = $this->reportedSite();

        $html = (string) $this->actingAs($site['admin'])->get(route('admin.reports.index'))->assertOk()->getContent();

        // The row menu template: "Block it" is an item kept only for rows that carry its data-ad-if value ...
        $this->assertSame(1, preg_match('/<button[^>]*formaction="[^"]*\/block"[^>]*>/', $html, $item), 'the row menu has a Block it item');
        $this->assertSame(1, preg_match('/\bdata-ad-if="([a-z0-9_-]+)"/', $item[0], $if), 'Block it is not offered on every row');
        $flag = 'data-'.$if[1].'=';

        // ... and only the row of a reported comment carries it; Dismiss stays on every row.
        foreach (['comment' => true, 'user' => false, 'video' => false] as $type => $offered) {
            $this->assertSame(1, preg_match('/<button[^>]*\bdata-type="'.$type.'"[^>]*>/', $html, $row), "the row of the reported {$type}");
            $this->assertSame($offered, str_contains($row[0], $flag), $offered ? "Block it is offered for a reported {$type}" : "Block it is not offered for a reported {$type}");
        }
        $this->assertMatchesRegularExpression('/<button[^>]*formaction="[^"]*\/cancel"/', $html);
    }

    public function test_the_old_reports_list_offers_block_for_comments_only(): void
    {
        config(['admin.ui' => 'v1']);
        $site = $this->reportedSite();

        $html = (string) $this->actingAs($site['admin'])->get(route('admin.reports.index'))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('admin.reports.block', ['Comment', $site['comment']->id]).'"', $html);
        $this->assertStringNotContainsString('action="'.route('admin.reports.block', ['User', $site['member']->id]).'"', $html);
        $this->assertStringNotContainsString('action="'.route('admin.reports.block', ['Video', $site['video']->id]).'"', $html);
        foreach ([['User', $site['member']->id], ['Video', $site['video']->id], ['Comment', $site['comment']->id]] as $target) {
            $this->assertStringContainsString('action="'.route('admin.reports.cancel', $target).'"', $html, 'Dismiss stays on every report');
        }

        // The addresses carry the id the reportable binding looks up, not the route key (a member's is the profile
        // slug, which used to make Resolve on a reported member a 404).
        $this->actingAs($site['admin'])
            ->post(route('admin.reports.cancel', ['User', $site['member']->id]))
            ->assertRedirect(route('admin.index'));
        $this->assertSame(ReportStatus::CANCELLED, $site['reports']['user']->fresh()->status);
    }

    public function test_the_old_dashboard_offers_block_for_comments_only(): void
    {
        config(['admin.ui' => 'v1']);
        $site = $this->reportedSite();

        $html = (string) $this->actingAs($site['admin'])->get(route('admin.index'))->assertOk()->getContent();

        $block = fn (string $type, int $id) => 'data-route="'.route('admin.reports.block', ['type' => $type, 'reportable' => $id]).'"';
        $cancel = fn (string $type, int $id) => 'data-route="'.route('admin.reports.cancel', ['type' => $type, 'reportable' => $id]).'"';

        $this->assertStringContainsString($block('comment', $site['comment']->id), $html);
        $this->assertStringNotContainsString($block('user', $site['member']->id), $html);
        $this->assertStringNotContainsString($block('video', $site['video']->id), $html);
        foreach (['user' => $site['member']->id, 'video' => $site['video']->id, 'comment' => $site['comment']->id] as $type => $id) {
            $this->assertStringContainsString($cancel($type, $id), $html, "Cancel stays on the reported {$type}");
        }
    }

    /**
     * An administrator, a member, a public video of that member with a comment, and one waiting report about each of
     * the three (by another member).
     *
     * @return array{admin: User, member: User, reporter2: User, video: Video, comment: Comment, reports: array{user: Report, video: Report, comment: Report}}
     */
    private function reportedSite(): array
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $reporter = User::factory()->create();
        $category = Category::query()->firstOrCreate(['slug' => 'report-block'], ['title' => 'Report block']);
        $video = Video::factory()->for($member)->create(['category_id' => $category->id, 'status' => VideoStatus::PUBLIC]);
        $comment = Comment::query()->create(['video_id' => $video->id, 'user_id' => $member->id, 'content' => 'A reported comment', 'ip' => '127.0.0.1']);

        return [
            'admin' => $admin,
            'member' => $member,
            'reporter2' => User::factory()->create(),
            'video' => $video,
            'comment' => $comment,
            'reports' => [
                'user' => $this->report($reporter, $member),
                'video' => $this->report($reporter, $video),
                'comment' => $this->report($reporter, $comment),
            ],
        ];
    }

    private function report(User $by, User|Video|Comment $about): Report
    {
        return Report::query()->create([
            'user_id' => $by->id,
            'reportable_type' => $about::class,
            'reportable_id' => $about->id,
            'reason' => 1,
            'status' => ReportStatus::PENDING,
        ]);
    }
}
