<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Support\EngagementCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Look-around DB-X1: Home's "Most commented" source counted the public comments of every active video for each page and
 * sorted them all (1.7-10 s at 300,000 videos on every engine). It orders by the count of public comments each video
 * keeps now (videos.comments_count: not hidden, not deleted, replies included), read in the order of its (comments_count,
 * published_at, id) index: for the same comments the same order as the live count, and the count follows every way the
 * site shows, hides or removes a comment.
 */
class MostCommentedStoredCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_the_same_comments_the_order_is_the_live_counts_order_page_by_page(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        // public comments, hidden ones, equal counts (newest first, then the newer row)
        foreach ([[3, 0, 5], [1, 4, 4], [3, 1, 3], [0, 2, 2], [2, 0, 2], [0, 0, 1]] as $i => [$public, $hidden, $daysAgo]) {
            $video = Video::factory()->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
                'published_at' => now()->subDays($daysAgo)->startOfMinute(),
                'uploaded_at' => now()->subDays($daysAgo),
            ]);
            Comment::factory()->count($public)->for($member)->for($video)->create();
            Comment::factory()->count($hidden)->for($member)->for($video)->create()->each(fn (Comment $c) => $c->update(['banned_at' => now()]));
        }

        $feeds = app(PublicVideoFeedService::class);
        $live = $feeds->baseQuery()
            ->withCount(['comments as live_count' => fn ($query) => $query->public()])
            ->orderByDesc('live_count')->latest('published_at')->orderByDesc('id')
            ->pluck('id')->all();

        $this->assertSame($live, $feeds->mostCommented()->pluck('id')->all());

        $cursor = '';
        $paged = [];
        do {
            $page = $feeds->page($feeds->mostCommented(), 2, $cursor);
            array_push($paged, ...collect($page->items())->pluck('id')->all());
            $cursor = (string) $page->nextCursor()?->encode();
        } while ($cursor !== '');

        $this->assertSame($live, $paged, 'every page in the same order, each video once');
    }

    public function test_the_count_follows_every_way_a_comment_is_written_hidden_shown_deleted_or_restored(): void
    {
        $member = User::factory()->create();
        $a = Video::factory()->for(User::factory(), 'user')->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDays(2)]);
        $b = Video::factory()->for(User::factory(), 'user')->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subDay()]);
        $feeds = app(PublicVideoFeedService::class);
        $order = fn (): array => $feeds->mostCommented()->pluck('id')->all();

        $first = Comment::factory()->for($member)->for($a)->create();
        Comment::factory()->for($member)->for($a)->create();
        Comment::factory()->for($member)->for($b)->create();
        $this->assertSame([$a->id, $b->id], $order(), 'written: a 2, b 1');

        $first->update(['banned_at' => now()]);
        $this->assertSame([$b->id, $a->id], $order(), 'hidden: a 1 and b 1, b is newer');

        $first->update(['banned_at' => null]);
        $this->assertSame([$a->id, $b->id], $order(), 'shown again: a 2');

        $first->delete();
        $this->assertSame([$b->id, $a->id], $order(), 'deleted: a 1');

        $first->restore();
        $this->assertSame([$a->id, $b->id], $order(), 'restored: a 2');

        // a change outside the site (a database tool): the recount (daily, and the update's pass) puts it right
        DB::table('videos')->where('id', $b->id)->update(['comments_count' => 50]);
        $this->assertSame([$b->id, $a->id], $order());
        EngagementCounters::recount([$a->id, $b->id]);
        $this->assertSame([$a->id, $b->id], $order());
        $this->assertSame([2, 1], [(int) $a->fresh()->comments_count, (int) $b->fresh()->comments_count]);
    }
}
