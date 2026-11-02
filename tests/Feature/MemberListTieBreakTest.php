<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /api/me/playlists and /api/me/subscriptions-videos ordered by one time column only, so two
 * rows of the same second lay on a page edge and the next page's cursor skipped the second one. Both lists now break ties
 * by the row's id: walked one row a page, every row comes exactly once (and a cursor that does not fit still gives the
 * first page: App\Support\SafeCursor, CursorPagesTest).
 */
class MemberListTieBreakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['site.per_page_videos' => 1, 'site.feed_cache_ttl' => 0]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_my_playlists_made_in_the_same_second_are_all_listed_one_per_page(): void
    {
        $member = User::factory()->create();
        $second = now()->subDay()->startOfSecond();
        $ids = [];

        foreach (['Tie one', 'Tie two', 'Tie three'] as $title) {
            $playlist = Playlist::factory()->for($member)->create(['title' => $title, 'is_deletable' => true]);
            DB::table('playlists')->where('id', $playlist->id)->update(['created_at' => $second]);
            $ids[] = $playlist->id;
        }

        $walked = $this->walk($member, 'me.playlists');

        // newest id first among the playlists of the same second, each exactly once
        $this->assertSame(array_reverse($ids), $walked);
    }

    public function test_videos_of_my_channels_published_in_the_same_second_are_all_listed_one_per_page(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create();
        DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $member->id, 'subscribe_at' => now(), 'read_at' => now()]);
        $second = now()->subHour()->startOfSecond();
        $ids = [];

        foreach (['Same second A', 'Same second B', 'Same second C'] as $title) {
            $ids[] = Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create([
                'title' => $title,
                'published_at' => $second,
                'uploaded_at' => $second,
            ])->id;
        }

        $walked = $this->walk($member, 'me.subscriptions-videos');

        $this->assertSame(array_reverse($ids), $walked);
    }

    /**
     * The ids of the list, following next_cursor from the first page (at most 10 pages).
     *
     * @return list<int>
     */
    private function walk(User $member, string $route): array
    {
        $ids = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->actingAs($member, 'sanctum')
                ->getJson(route($route, array_filter(['cursor' => $cursor])))
                ->assertOk();
            foreach ($response->json('data') as $row) {
                $ids[] = (int) $row['id'];
            }
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null && ++$pages < 10);

        return $ids;
    }
}
