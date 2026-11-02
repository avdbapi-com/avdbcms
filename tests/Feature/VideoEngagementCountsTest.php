<?php

namespace Tests\Feature;

use App\Console\Commands\RecountVideoEngagement;
use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Support\EngagementCounters;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The like, dislike and comment counts each video keeps (videos.likes_count, dislikes_count, comments_count: the studio,
 * the admin lists and search by likes read them). comments_count counts public comments only: not hidden, not deleted,
 * replies included. Up to 1.2.2 they drifted: Admin > Likes (delete one, clear all, the bulk bar), hiding comments (one,
 * in bulk, a report's "Block it", a member blocked with their comments) and a removed account changed the rows without
 * the counters. Each of those counts the videos it touched again now, and videos:recount-engagement (the update to 1.2.3
 * and once a day) puts every video right, in short slices (App\Support\EngagementCounters).
 */
class VideoEngagementCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->admin = User::factory()->admin()->create();
        $this->creator = User::factory()->create();
    }

    public function test_deleting_likes_in_the_admin_counts_their_videos_again(): void
    {
        [$first, $second] = [$this->video(), $this->video()];
        $member = User::factory()->create();
        $like = $this->vote($first, $member, true);
        $this->vote($first, User::factory()->create(), false);
        $this->vote($second, $member, true);
        $bulk = [$this->vote($second, User::factory()->create(), true), $this->vote($second, User::factory()->create(), false)];
        EngagementCounters::recount([$first->id, $second->id]);
        $this->assertSame([1, 1, 0], $this->counts($first));

        // one like (Admin > Likes > Delete)
        $this->actingAs($this->admin)->delete(route('admin.interactions.delete', $like))->assertRedirect();
        $this->assertSame([0, 1, 0], $this->counts($first));

        // the bulk bar
        $this->actingAs($this->admin)->post(route('admin.bulk.interactions'), ['action' => 'delete', 'ids' => $bulk])
            ->assertSessionHas('success', 'Deleted 2 likes and dislikes.');
        $this->assertSame([1, 0, 0], $this->counts($second));

        // Clear all
        $this->actingAs($this->admin)->delete(route('admin.interactions.clear'))->assertRedirect();
        $this->assertSame(0, DB::table('interactions')->count());
        $this->assertSame([[0, 0, 0], [0, 0, 0]], [$this->counts($first), $this->counts($second)]);
    }

    public function test_clear_all_removes_a_big_list_in_batches(): void
    {
        $video = $this->video();
        $members = User::factory()->count(3)->create()->pluck('id')->all();
        $rows = [];
        for ($i = 0; $i < EngagementCounters::BATCH * 5 + 20; $i++) {
            $rows[] = ['user_id' => $members[$i % 3], 'likeable_type' => $video->getMorphClass(), 'likeable_id' => $video->id, 'status' => $i % 4 === 0 ? 0 : 1, 'perform_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('interactions')->insert($chunk);
        }
        EngagementCounters::recount([$video->id]);
        $this->assertSame(EngagementCounters::BATCH * 5 + 20, array_sum(array_slice($this->counts($video), 0, 2)));

        DB::enableQueryLog();
        $this->actingAs($this->admin)->delete(route('admin.interactions.clear'))->assertRedirect();
        $deletes = array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/^delete from [`"]interactions[`"]/i', $query['query']) === 1);
        DB::disableQueryLog();

        $this->assertSame(0, DB::table('interactions')->count());
        $this->assertGreaterThanOrEqual(2, count($deletes), 'in batches, not one statement for every row');
        $this->assertSame([0, 0, 0], $this->counts($video));
    }

    public function test_hiding_and_showing_comments_moves_the_comment_count(): void
    {
        $video = $this->video();
        $member = User::factory()->create();
        [$one, $two, $three] = Comment::factory()->count(3)->for($member)->for($video)->create();
        $reply = Comment::factory()->for($member)->for($video)->create(['parent_id' => $one->id]);
        $this->assertSame(4, $this->counts($video)[2], 'each new comment counts, a reply too');

        // one comment (Admin > Comments > Hide / Show)
        $this->actingAs($this->admin)->post(route('admin.comments.ban', $one))->assertRedirect();
        $this->assertSame(3, $this->counts($video)[2]);
        $this->actingAs($this->admin)->post(route('admin.comments.ban', $one))->assertRedirect();
        $this->assertSame(3, $this->counts($video)[2], 'hiding a hidden comment changes nothing');
        $this->actingAs($this->admin)->post(route('admin.comments.unban', $one))->assertRedirect();
        $this->assertSame(4, $this->counts($video)[2]);

        // a report's "Block it"
        $this->actingAs($this->admin)->post(route('admin.reports.block', ['type' => 'comment', 'reportable' => $two->id]))->assertRedirect();
        $this->assertSame(3, $this->counts($video)[2]);

        // the bulk bar, with a hidden one in the selection
        $this->actingAs($this->admin)->post(route('admin.bulk.comments'), ['action' => 'ban', 'ids' => [$two->id, $three->id, $reply->id]])
            ->assertSessionHas('success', 'Hid 2 comments.');
        $this->assertSame(1, $this->counts($video)[2]);
        $this->actingAs($this->admin)->post(route('admin.bulk.comments'), ['action' => 'unban', 'ids' => [$one->id, $two->id, $three->id, $reply->id]])
            ->assertSessionHas('success', '3 comments show again.');
        $this->assertSame(4, $this->counts($video)[2]);
        $this->assertSame($this->truth($video), $this->counts($video));
    }

    public function test_deleting_comments_keeps_the_comment_count_right(): void
    {
        $video = $this->video();
        $author = User::factory()->create();
        $other = User::factory()->create();
        $hidden = Comment::factory()->for($author)->for($video)->banned()->create();
        $parent = Comment::factory()->for($author)->for($video)->create();
        Comment::factory()->count(2)->for($other)->for($video)->create(['parent_id' => $parent->id]);
        $lone = Comment::factory()->for($author)->for($video)->create();
        $this->assertSame(4, $this->counts($video)[2], 'a hidden comment does not count');

        // a hidden comment deleted: the count stays
        $this->actingAs($this->admin)->delete(route('admin.comments.delete', $hidden))->assertRedirect();
        $this->assertSame(4, $this->counts($video)[2]);

        // the author deletes a comment that has replies (the database removes the replies with it on MySQL / MariaDB)
        $this->actingAs($author, 'sanctum')->deleteJson(route('comments.delete', ['video' => $video->uuid, 'comment' => $parent->id]))->assertNoContent();
        $this->assertSame($this->truth($video), $this->counts($video));

        // a soft delete and its restore
        $lone->delete();
        $this->assertSame($this->truth($video), $this->counts($video));
        $lone->restore();
        $this->assertSame($this->truth($video), $this->counts($video));

        // the admin's delete of a comment with replies
        $again = Comment::factory()->for($author)->for($video)->create();
        Comment::factory()->for($other)->for($video)->create(['parent_id' => $again->id]);
        $this->actingAs($this->admin)->delete(route('admin.comments.delete', $again))->assertRedirect();
        $this->assertSame($this->truth($video), $this->counts($video));
    }

    public function test_a_comment_and_its_reply_deleted_in_one_request_leave_the_count_right(): void
    {
        // Admin > Comments (the bulk bar) and the studio's mass delete load the chosen comments first. The parent's delete
        // takes its replies (each counted down once); the reply loaded before that was counted down again when its own
        // delete found no row.
        $video = $this->video();
        $member = User::factory()->create();
        Comment::factory()->for($member)->for($video)->create();

        $parent = Comment::factory()->for($member)->for($video)->create();
        $reply = Comment::factory()->for($member)->for($video)->create(['parent_id' => $parent->id]);
        $this->assertSame(3, $this->counts($video)[2]);
        $this->actingAs($this->admin)->post(route('admin.bulk.comments'), ['action' => 'delete', 'ids' => [$parent->id, $reply->id]])
            ->assertSessionHas('success', 'Deleted 2 comments permanently.');
        $this->assertSame([0, 0, 1], $this->counts($video));

        $parent = Comment::factory()->for($member)->for($video)->create();
        $reply = Comment::factory()->for($member)->for($video)->create(['parent_id' => $parent->id]);
        $this->assertSame(3, $this->counts($video)[2]);
        $this->actingAs($this->creator)->delete(route('user.comments.mass-delete'), ['ids' => [$reply->id, $parent->id]])->assertRedirect();
        $this->assertSame([0, 0, 1], $this->counts($video));
        $this->assertSame($this->truth($video), $this->counts($video));
    }

    public function test_a_blocked_member_whose_comments_are_hidden_leaves_the_counts(): void
    {
        $video = $this->video();
        $member = User::factory()->create();
        Comment::factory()->count(2)->for($member)->for($video)->create();
        Comment::factory()->for(User::factory()->create())->for($video)->create();
        $this->assertSame(3, $this->counts($video)[2]);

        $this->actingAs($this->admin)->post(route('admin.users.ban', $member), ['ban_comments' => 1])->assertRedirect();

        $this->assertSame(2, Comment::query()->where('user_id', $member->id)->whereNotNull('banned_at')->count());
        $this->assertSame([0, 0, 1], $this->counts($video));
    }

    public function test_a_removed_account_takes_its_likes_and_comments_off_other_videos(): void
    {
        [$first, $second] = [$this->video(), $this->video()];
        $leaving = User::factory()->create();
        $staying = User::factory()->create();
        $this->vote($first, $leaving, true);
        $this->vote($second, $leaving, false);
        $this->vote($first, $staying, true);
        Comment::factory()->count(2)->for($leaving)->for($first)->create();
        Comment::factory()->for($leaving)->for($second)->create()->delete();
        Comment::factory()->for($staying)->for($second)->create();
        EngagementCounters::recount([$first->id, $second->id]);
        $this->assertSame([[2, 0, 2], [0, 1, 1]], [$this->counts($first), $this->counts($second)]);

        $leaving->forceDelete();

        $this->assertSame([[1, 0, 0], [0, 0, 1]], [$this->counts($first), $this->counts($second)]);
        $this->assertSame(0, DB::table('interactions')->where('user_id', $leaving->id)->count());
        $this->assertSame(0, DB::table('comments')->where('user_id', $leaving->id)->count());
    }

    public function test_the_recount_puts_every_video_right_in_slices_and_writes_only_wrong_counts(): void
    {
        // more videos than one step reads (CHUNK), so a slice can stop between two steps
        $ids = $this->bareVideos(EngagementCounters::CHUNK + 30);
        [$liked, $commented, $right] = [$ids[3], $ids[EngagementCounters::CHUNK + 5], $ids[10]];
        $member = User::factory()->create();
        DB::table('interactions')->insert([
            ['user_id' => $member->id, 'likeable_type' => (new Video())->getMorphClass(), 'likeable_id' => $liked, 'status' => 1, 'perform_at' => now()],
            ['user_id' => $this->creator->id, 'likeable_type' => (new Video())->getMorphClass(), 'likeable_id' => $liked, 'status' => 0, 'perform_at' => now()],
        ]);
        DB::table('comments')->insert([
            ['video_id' => $commented, 'user_id' => $member->id, 'content' => 'Public', 'ip' => '127.0.0.1', 'banned_at' => null, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $commented, 'user_id' => $member->id, 'content' => 'Hidden', 'ip' => '127.0.0.1', 'banned_at' => now(), 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['video_id' => $commented, 'user_id' => $member->id, 'content' => 'Deleted', 'ip' => '127.0.0.1', 'banned_at' => null, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        // what older versions left: counts that are too high, hidden and deleted comments counted
        DB::table('videos')->where('id', $liked)->update(['likes_count' => 7, 'dislikes_count' => 0, 'comments_count' => 2]);
        DB::table('videos')->where('id', $commented)->update(['comments_count' => 3]);
        $stamp = (string) DB::table('videos')->where('id', $liked)->value('updated_at');

        EngagementCounters::request();
        $this->assertTrue(RecountVideoEngagement::due());
        $first = EngagementCounters::run(0.0);
        $this->assertSame(['checked' => EngagementCounters::CHUNK, 'corrected' => 1, 'done' => false], $first, 'one step, then it stops');
        $this->assertSame([1, 1, 0], [(int) DB::table('videos')->where('id', $liked)->value('likes_count'), (int) DB::table('videos')->where('id', $liked)->value('dislikes_count'), (int) DB::table('videos')->where('id', $liked)->value('comments_count')]);
        $this->assertSame(3, (int) DB::table('videos')->where('id', $commented)->value('comments_count'), 'not reached yet');
        $this->assertTrue(RecountVideoEngagement::due(), 'the pass goes on');

        $this->artisan('videos:recount-engagement', ['--seconds' => 5])
            ->expectsOutputToContain('30 videos checked, 1 video put right, every video is counted')
            ->assertExitCode(0);
        $this->assertSame(1, (int) DB::table('videos')->where('id', $commented)->value('comments_count'), 'public comments only');
        $this->assertSame(0, (int) DB::table('videos')->where('id', $right)->value('likes_count'));
        $this->assertSame($stamp, (string) DB::table('videos')->where('id', $liked)->value('updated_at'), 'a recount does not move the video in "my videos"');
        $this->assertFalse(RecountVideoEngagement::due(), 'the next pass is due in a day');

        // safe to run again: nothing left to put right
        $this->artisan('videos:recount-engagement', ['--fresh' => true])->expectsOutputToContain('0 videos put right')->assertExitCode(0);
    }

    public function test_the_update_counts_every_video_and_the_scheduler_starts_only_while_a_pass_is_due(): void
    {
        $video = $this->video();
        $this->vote($video, User::factory()->create(), true);
        Comment::factory()->for($this->creator)->for($video)->banned()->createQuietly();
        DB::table('videos')->where('id', $video->id)->update(['likes_count' => 40, 'dislikes_count' => 3, 'comments_count' => 9]);

        // the update's migration (2026_10_26_100200), twice
        $migration = require database_path('migrations/2026_10_26_100200_recount_video_likes_and_comments.php');
        $migration->up();
        $this->assertSame([1, 0, 0], $this->counts($video));
        $migration->up();
        $this->assertSame([1, 0, 0], $this->counts($video));

        $events = array_values(array_filter($this->app->make(Kernel::class)->resolveConsoleSchedule()->events(),
            fn ($event): bool => str_contains((string) $event->command, 'videos:recount-engagement')));
        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        // schedule:run starts the tasks in the foreground one after the other: a run of up to 50 seconds there held back
        // the tasks after it, the queue worker of hosts without a daemon among them
        $this->assertTrue($events[0]->runInBackground, 'it runs in the background, like upload18:finish-pending --seconds=50');
        $this->assertFalse($events[0]->filtersPass($this->app), 'a pass just ended: not started');

        EngagementCounters::request();
        $this->assertTrue($events[0]->filtersPass($this->app));
    }

    private function video(): Video
    {
        return Video::factory()->for($this->creator)->create(['status' => VideoStatus::PUBLIC, 'likes_count' => 0, 'dislikes_count' => 0, 'comments_count' => 0]);
    }

    private function vote(Video $video, User $member, bool $like): int
    {
        return (int) DB::table('interactions')->insertGetId([
            'user_id' => $member->id, 'likeable_type' => $video->getMorphClass(), 'likeable_id' => $video->id, 'status' => $like ? 1 : 0, 'perform_at' => now(),
        ]);
    }

    /** @return list<int> ids of $count plain video rows (fast: no factory) */
    private function bareVideos(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['uuid' => (string) Str::uuid(), 'title' => 'Counted '.$i, 'slug' => 'counted-'.$i, 'original_file_name' => 'counted.mp4',
                'user_id' => $this->creator->id, 'status' => VideoStatus::PUBLIC->value, 'created_at' => now(), 'updated_at' => now()->subDay()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('videos')->insert($chunk);
        }

        return DB::table('videos')->where('title', 'like', 'Counted %')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array{0: int, 1: int, 2: int} the stored likes, dislikes and comments */
    private function counts(Video $video): array
    {
        $row = DB::table('videos')->where('id', $video->id)->first(['likes_count', 'dislikes_count', 'comments_count']);

        return [(int) $row->likes_count, (int) $row->dislikes_count, (int) $row->comments_count];
    }

    /** @return array{0: int, 1: int, 2: int} what the public pages count live */
    private function truth(Video $video): array
    {
        return [
            $video->likes()->count(),
            $video->dislikes()->count(),
            $video->comments()->public()->count(),
        ];
    }
}
