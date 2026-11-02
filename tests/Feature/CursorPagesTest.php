<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\EngagementCounters;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every "load more" list of the API (App\Support\SafeCursor): the cursors a list writes walk it once, row by row, and a
 * cursor that does not fit the list (hand made, cut, from another sort, or kept from before an update that renamed an
 * order column) gives the first page instead of HTTP 500.
 */
class CursorPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $member;

    private Category $category;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // one row a page, so every list has page edges; no guest feed cache unless a test turns it on
        config(['site.feed_cache_ttl' => 0, 'site.per_page_videos' => 1, 'site.per_page_users' => 1]);
        // well over a hundred API requests a test: the per-minute API limit (throttle:api) is not what this class tests
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->creator = User::factory()->create();
        $this->member = User::factory()->create();
        $this->category = Category::query()->create(['title' => 'Cursor Pages', 'slug' => 'cursor-pages', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);

        // name => [published days ago, views, public comments, banned comments]
        foreach (['v1' => [1, 10, 1, 5], 'v2' => [2, 30, 3, 0], 'v3' => [3, 20, 2, 0]] as $name => [$daysAgo, $views, $public, $banned]) {
            $video = Video::factory()->for($this->creator)->for($this->category)->withStatus(VideoStatus::PUBLIC)->create([
                'title' => 'Cursor '.$name,
                'published_at' => now()->subDays($daysAgo),
                'uploaded_at' => now()->subDays($daysAgo),
                'views' => $views,
            ]);
            Comment::factory()->count($public)->for($this->member)->for($video)->createQuietly();
            if ($banned > 0) {
                Comment::factory()->count($banned)->for($this->member)->for($video)->banned()->createQuietly();
            }
            $this->ids[$name] = $video->id;
        }
        // the count of public comments each video keeps, as the site keeps it (Most commented orders by it: DB-X1)
        EngagementCounters::recount(array_values($this->ids));

        foreach (['p1' => 1, 'p2' => 2, 'p3' => 3] as $name => $daysAgo) {
            $playlist = Playlist::factory()->for($this->creator)->create(['status' => PlaylistStatus::PUBLIC, 'title' => 'Cursor '.$name]);
            $playlist->videos()->attach($this->ids['v1'], ['position' => 1]);
            DB::table('playlists')->where('id', $playlist->id)->update(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
            $this->ids[$name] = $playlist->id;
        }

        foreach (['m1' => 1, 'm2' => 2, 'm3' => 3] as $name => $daysAgo) {
            $video = Video::factory()->for($this->member)->withStatus(VideoStatus::PRIVATE)->create(['title' => 'Cursor '.$name]);
            DB::table('videos')->where('id', $video->id)->update(['updated_at' => now()->subDays($daysAgo)]);
            $this->ids[$name] = $video->id;
        }

        foreach (['mp1' => 1, 'mp2' => 2, 'mp3' => 3] as $name => $daysAgo) {
            $playlist = Playlist::factory()->for($this->member)->create(['title' => 'Cursor '.$name, 'is_deletable' => true]);
            DB::table('playlists')->where('id', $playlist->id)->update(['created_at' => now()->subDays($daysAgo)]);
            $this->ids[$name] = $playlist->id;
        }

        // the member follows the creator and three more channels, the last two in the same second
        $sameSecond = now()->subDays(3)->startOfSecond();
        foreach (['c1' => now()->subDays(1), 'c2' => now()->subDays(2), 'c3' => $sameSecond, 'c4' => $sameSecond] as $name => $subscribedAt) {
            $channel = $name === 'c1' ? $this->creator : User::factory()->create();
            DB::table('subscriptions')->insert(['user_id' => $channel->id, 'subscriber_id' => $this->member->id, 'subscribe_at' => $subscribedAt, 'read_at' => now()]);
            $this->ids[$name] = $channel->id;
        }
    }

    public function test_every_list_walks_its_rows_once_with_the_cursors_it_writes(): void
    {
        foreach ($this->lists() as $label => [$url, $expected, $asMember]) {
            $pages = $this->walk($url, $asMember);

            $this->assertSame(array_map(fn (string $name) => [$this->ids[$name]], $expected), $pages, $label);
        }
    }

    public function test_a_cursor_that_does_not_fit_the_list_gives_its_first_page(): void
    {
        $cursors = [
            'not a cursor' => '%%%not-a-cursor',
            'the flag only' => $this->encode(['_pointsToNextItems' => true]),
            'no flag' => $this->encode(['published_at' => now()->toDateTimeString(), 'id' => 1, 'views' => 1]),
            'a number' => $this->encode(7),
            'a string' => $this->encode('next'),
            'a list' => $this->encode([1, 2]),
        ];

        foreach ($this->lists() + ['notifications' => [fn (?string $c) => route('notifications.index', array_filter(['cursor' => $c])), [], true]] as $label => [$url, $expected, $asMember]) {
            $firstPage = $this->page($url(null), $asMember)->json('data');

            foreach ($cursors as $kind => $cursor) {
                $response = $this->page($url($cursor), $asMember);

                $this->assertSame(200, $response->getStatusCode(), "{$label}, cursor: {$kind}");
                $this->assertSame($firstPage, $response->json('data'), "{$label}, cursor: {$kind}");
            }
        }

        // a cursor of another sort of the same channel list
        $popular = $this->getJson(route('users.videos', ['user' => $this->creator->id, 'sort' => 'popular']))->json('meta.next_cursor');
        $this->assertIsString($popular);
        $this->getJson(route('users.videos', ['user' => $this->creator->id, 'sort' => 'latest', 'cursor' => $popular]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->ids['v1']);
    }

    public function test_a_cursor_with_an_empty_value_never_fails(): void
    {
        // Laravel compares each order column of a cursor with "<" or ">", which an empty value (NULL) cannot take: before
        // 1.2.3 every such cursor answered HTTP 500 ("Illegal operator and value combination").
        foreach ($this->lists() as $label => [$url, $expected, $asMember]) {
            $next = $this->page($url(null), $asMember)->assertOk()->json('meta.next_cursor');
            $this->assertIsString($next, $label);
            $parameters = Cursor::fromEncoded($next)->toArray();
            unset($parameters['_pointsToNextItems']);

            foreach ([true, false] as $pointsToNextItems) {
                $empty = array_map(fn () => null, $parameters);

                foreach (array_merge(array_map(fn (string $key) => [$key => null] + $parameters, array_keys($parameters)), [$empty]) as $values) {
                    $cursor = (new Cursor($values, $pointsToNextItems))->encode();
                    $response = $this->page($url($cursor), $asMember);
                    $nulls = implode(',', array_keys(array_filter($values, 'is_null')));

                    $this->assertSame(200, $response->getStatusCode(), "{$label}, empty: {$nulls}, ".($pointsToNextItems ? 'next' : 'previous'));
                    $this->assertIsArray($response->json('data'), "{$label}, empty: {$nulls}");
                }
            }
        }
    }

    public function test_a_page_that_ends_on_a_video_without_a_publish_date_goes_on_after_it(): void
    {
        // 1.2.2's Unban made a video that was banned before it was ever published public without a publish date. As the
        // last card of a page of Most viewed or Trending its cursor holds that empty date: the next page answered 500
        // for every visitor. It goes on with the rest of the list now, nothing twice and nothing left out.
        $undated = Video::factory()->for($this->creator)->for($this->category)->withStatus(VideoStatus::PUBLIC)->create([
            'title' => 'Cursor undated', 'uploaded_at' => now()->subDays(5), 'views' => 900,
        ]);
        DB::table('videos')->where('id', $undated->id)->update(['published_at' => null]);
        $this->ids['undated'] = $undated->id;

        $feed = fn (string $source) => fn (?string $c) => route('videos.index', array_filter(['source' => $source, 'per_page' => 1, 'cursor' => $c]));
        foreach (['most_viewed' => $feed('most_viewed'), 'trending' => $feed('trending'), 'trend page' => fn (?string $c) => route('videos.trend', array_filter(['cursor' => $c]))] as $label => $url) {
            $first = $this->getJson($url(null))->assertOk();
            $this->assertNull(Cursor::fromEncoded($first->json('meta.next_cursor'))->parameter('published_at'), "{$label}: the cursor holds the empty date");

            $this->assertSame(array_map(fn (string $name) => [$this->ids[$name]], ['undated', 'v2', 'v3', 'v1']), $this->walk($url, false), $label);
        }

        // the channel's popular videos, and the previous page of the second one
        $channel = fn (?string $c) => route('users.videos', array_filter(['user' => $this->creator->id, 'sort' => 'popular', 'cursor' => $c]));
        $this->assertSame(array_map(fn (string $name) => [$this->ids[$name]], ['undated', 'v2', 'v3', 'v1']), $this->walk($channel, false));
        $second = $this->getJson($channel($this->getJson($channel(null))->json('meta.next_cursor')))->assertOk();
        $this->getJson($channel($second->json('meta.prev_cursor')))->assertOk()->assertJsonPath('data.0.id', $undated->id);
    }

    public function test_videos_without_a_publish_date_are_listed_once_after_the_dated_ones_both_ways(): void
    {
        // A public video an older version made public without publishing it has no publish date. The lists order it after
        // the dated ones (NULL comes last going down, first going up). "Load more" skipped it after a page that ended on a
        // dated video (all of them under Newest after its first page, those of a group of equal views under Most viewed),
        // while "back" listed it: the conditions compared the date with "<" or ">", which NULL never passes.
        $make = function (string $name, int $views, ?int $daysAgo): void {
            $video = Video::factory()->for($this->creator)->for($this->category)->withStatus(VideoStatus::PUBLIC)->create([
                'title' => 'Cursor '.$name, 'uploaded_at' => now()->subDays(9), 'published_at' => now()->subDays(9), 'views' => $views,
            ]);
            DB::table('videos')->where('id', $video->id)->update(['published_at' => $daysAgo === null ? null : now()->subDays($daysAgo)]);
            $this->ids[$name] = $video->id;
        };
        $make('a', 900, null);
        $make('b', 900, null);
        $make('c', 900, 5);
        $make('d', 500, 6);
        $make('e', 100, 7);

        $feed = fn (string $source) => fn (?string $c) => route('videos.index', array_filter(['source' => $source, 'per_page' => 1, 'cursor' => $c]));
        $channel = fn (string $sort) => fn (?string $c) => route('users.videos', array_filter(['user' => $this->creator->id, 'sort' => $sort, 'cursor' => $c]));
        $newest = ['v1', 'v2', 'v3', 'c', 'd', 'e', 'b', 'a'];

        foreach ([
            'home feed newest' => [$feed('newest'), $newest],
            'home feed most viewed' => [$feed('most_viewed'), ['c', 'b', 'a', 'd', 'e', 'v2', 'v3', 'v1']],
            'home feed trending' => [$feed('trending'), ['c', 'b', 'a', 'd', 'e', 'v2', 'v3', 'v1']],
            'category page' => [fn (?string $c) => route('categories.videos', array_filter(['category' => $this->category->id, 'cursor' => $c])), $newest],
            'channel videos latest' => [$channel('latest'), $newest],
            'channel videos oldest' => [$channel('oldest'), ['a', 'b', 'e', 'd', 'c', 'v3', 'v2', 'v1']],
        ] as $label => [$url, $names]) {
            $expected = array_map(fn (string $name) => [$this->ids[$name]], $names);

            $this->assertSame($expected, $this->walk($url, false), "{$label}: load more");
            $this->assertSame(array_reverse($expected), $this->walkBack($url, false), "{$label}: back from the last page");
        }
    }

    public function test_channel_playlists_changed_in_the_same_second_are_each_listed_once(): void
    {
        // ordered by the time they were changed only, the next page started after that second: the others were skipped
        DB::table('playlists')->whereIn('id', [$this->ids['p1'], $this->ids['p2'], $this->ids['p3']])->update(['updated_at' => now()->subHour()->startOfSecond()]);
        $url = fn (?string $c) => route('users.playlists', array_filter(['user' => $this->creator->id, 'cursor' => $c]));

        $this->assertSame([[$this->ids['p3']], [$this->ids['p2']], [$this->ids['p1']]], $this->walk($url, false), 'the newest playlist first, each once');

        // a cursor written before the tie-break (no playlists.id): the first page, as for any cursor that does not fit
        $old = (new Cursor(['updated_at' => now()->subHour()->startOfSecond()->toDateTimeString()], true))->encode();
        $this->getJson($url($old))->assertOk()->assertJsonPath('data.0.id', $this->ids['p3']);
    }

    public function test_hand_made_array_values_give_the_first_newest_page(): void
    {
        foreach (['videos.index', 'videos.trend', 'categories.videos'] as $route) {
            $url = route($route, $route === 'categories.videos' ? ['category' => $this->category->id] : []);
            $this->getJson($url.(str_contains($url, '?') ? '&' : '?').'cursor[]=1')->assertOk()->assertJsonCount(1, 'data');
        }

        $this->getJson(route('videos.index').'?per_page=1&source[]=most_viewed')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->ids['v1']);
    }

    public function test_a_most_commented_cursor_from_before_1_2_3_goes_on_where_it_was(): void
    {
        $first = $this->getJson(route('videos.index', ['source' => 'most_commented', 'per_page' => 1]))->assertOk();
        $this->assertSame([$this->ids['v2']], array_column($first->json('data'), 'id'));

        // the count of public comments each video keeps: the key 1.2.2's cursors carry too (it named its live count so)
        $current = Cursor::fromEncoded($first->json('meta.next_cursor'));
        $this->assertSame(['comments_count', 'published_at', 'id', '_pointsToNextItems'], array_keys($current->toArray()));

        // an earlier 1.2.3 build named its live count public_comments_count
        $parameters = $current->toArray();
        $earlier = ['public_comments_count' => $parameters['comments_count']] + array_diff_key($parameters, ['comments_count' => true, '_pointsToNextItems' => true]);
        $earlierCursor = (new Cursor($earlier, true))->encode();

        foreach ([$first->json('meta.next_cursor'), $earlierCursor] as $cursor) {
            $this->getJson(route('videos.index', ['source' => 'most_commented', 'per_page' => 1, 'cursor' => $cursor]))
                ->assertOk()
                ->assertJsonPath('data.0.id', $this->ids['v3']);
        }
    }

    public function test_a_cursor_in_a_get_body_never_changes_the_cached_first_page_of_guests(): void
    {
        config(['site.feed_cache_ttl' => 30]);
        $next = $this->getJson(route('videos.index', ['per_page' => 1]))->assertOk()->json('meta.next_cursor');
        $this->assertIsString($next);
        Cache::flush();

        // the guest cache key is made from the query string, which has no cursor here: so is the page
        $this->json('GET', route('videos.index', ['per_page' => 1]), ['cursor' => $next])
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->ids['v1']);

        $this->getJson(route('videos.index', ['per_page' => 1]))
            ->assertOk()
            ->assertHeader('X-Feed-Cache', 'HIT')
            ->assertJsonPath('data.0.id', $this->ids['v1']);
    }

    /**
     * label => [url for a cursor (null: the first page), names in order, asks as the member].
     *
     * @return array<string, array{0: Closure(?string): string, 1: list<string>, 2: bool}>
     */
    private function lists(): array
    {
        $feed = fn (string $source) => fn (?string $c) => route('videos.index', array_filter(['source' => $source, 'per_page' => 1, 'cursor' => $c]));
        $channel = fn (string $sort) => fn (?string $c) => route('users.videos', array_filter(['user' => $this->creator->id, 'sort' => $sort, 'cursor' => $c]));
        $me = fn (string $name) => fn (?string $c) => route($name, array_filter(['cursor' => $c]));

        return [
            'home feed newest' => [$feed('newest'), ['v1', 'v2', 'v3'], false],
            'home feed most viewed' => [$feed('most_viewed'), ['v2', 'v3', 'v1'], false],
            'home feed most commented (public comments only)' => [$feed('most_commented'), ['v2', 'v3', 'v1'], false],
            'home feed trending' => [$feed('trending'), ['v2', 'v3', 'v1'], false],
            'trend page' => [fn (?string $c) => route('videos.trend', array_filter(['cursor' => $c])), ['v2', 'v3', 'v1'], false],
            'category page' => [fn (?string $c) => route('categories.videos', array_filter(['category' => $this->category->id, 'cursor' => $c])), ['v1', 'v2', 'v3'], false],
            'channel videos latest' => [$channel('latest'), ['v1', 'v2', 'v3'], false],
            'channel videos popular' => [$channel('popular'), ['v2', 'v3', 'v1'], false],
            'channel videos oldest' => [$channel('oldest'), ['v3', 'v2', 'v1'], false],
            'channel playlists' => [fn (?string $c) => route('users.playlists', array_filter(['user' => $this->creator->id, 'cursor' => $c])), ['p1', 'p2', 'p3'], false],
            'my videos' => [$me('me.videos'), ['m1', 'm2', 'm3'], true],
            'my playlists' => [$me('me.playlists'), ['mp1', 'mp2', 'mp3'], true],
            // the Save dialog's list (resources/js/api/avdbclip/playlists.ts)
            'my playlists for a video' => [fn (?string $c) => route('me.playlists', array_filter(['video_id' => $this->ids['v1'], 'cursor' => $c])), ['mp1', 'mp2', 'mp3'], true],
            'my subscriptions videos' => [$me('me.subscriptions-videos'), ['v1', 'v2', 'v3'], true],
            // channels followed in the same second: the newer account first (the order's tie-break)
            'my subscriptions channels' => [$me('me.subscriptions-channels'), ['c1', 'c2', 'c4', 'c3'], true],
        ];
    }

    /**
     * The ids of each page, following next_cursor from the first page.
     *
     * @return list<list<int>>
     */
    private function walk(Closure $url, bool $asMember): array
    {
        $pages = [];
        $cursor = null;

        do {
            $response = $this->page($url($cursor), $asMember)->assertOk();
            $pages[] = array_column($response->json('data'), 'id');
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null && count($pages) < 10);

        return $pages;
    }

    /**
     * The ids of each page going back from the last one (following prev_cursor), the last page first.
     *
     * @return list<list<int>>
     */
    private function walkBack(Closure $url, bool $asMember): array
    {
        $cursor = null;
        $steps = 0;

        do {
            $last = $this->page($url($cursor), $asMember)->assertOk();
            $cursor = $last->json('meta.next_cursor');
        } while ($cursor !== null && ++$steps < 10);

        $pages = [array_column($last->json('data'), 'id')];
        $cursor = $last->json('meta.prev_cursor');

        while ($cursor !== null && count($pages) < 10) {
            $response = $this->page($url($cursor), $asMember)->assertOk();
            $pages[] = array_column($response->json('data'), 'id');
            $cursor = $response->json('meta.prev_cursor');
        }

        return $pages;
    }

    private function page(string $url, bool $asMember)
    {
        return $asMember ? $this->actingAs($this->member, 'sanctum')->getJson($url) : $this->getJson($url);
    }

    private function encode(mixed $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
    }
}
