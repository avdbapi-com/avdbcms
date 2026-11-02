<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Support\CategoryVideoCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R3-022: the side menu's category counts (also the footer's, Home's, Trend's, the Categories page's and a category
 * page's) are counted once for every category and kept until a write or a scheduled moment can change them
 * (App\Support\CategoryVideoCounts). Before, every page view recounted every category (0.6 s at 300,000 videos). A kept
 * count never lies: each kind of change shows at the next page view; frequent writes that change no count (views,
 * likes, a creator's avatar) keep the counts; each language has its own.
 */
class CategoryVideoCountsTest extends TestCase
{
    use RefreshDatabase;

    private Category $a;

    private Category $b;

    private User $creator;

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-10-29 12:00:00'));
        Cache::flush();
        CategoryVideoCounts::flush();
        $this->a = $this->category('Alpha', 1);
        $this->b = $this->category('Beta', 2);
        $this->creator = User::factory()->create();
    }

    protected function tearDown(): void
    {
        CategoryVideoCounts::flush();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_pages_count_once_and_keep_the_counts_until_something_changes(): void
    {
        $this->video($this->a);
        $this->video($this->a);
        $this->video($this->a, ['status' => VideoStatus::PRIVATE]);
        $this->video($this->b);
        // a category that is not in the menu: the Categories page lists it, the side menu does not
        $hidden = $this->category('Gamma', 3);
        $hidden->updateQuietly(['in_menu' => false]);
        $this->video($hidden);
        $this->listen();

        // the first view of each page counts its categories in one statement: the side menu's (Trend, Home and every page
        // with the menu share it), all of them (the Categories page), one (a category's page)
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.trend'))->assertOk()->assertViewHas('categories', fn ($categories) => $this->counts($categories) === ['Alpha' => 2, 'Beta' => 1]);
        $this->assertSame(1, $this->recounts(), 'one statement counts the categories of the menu');
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.home'))->assertOk();
        $this->assertSame(1, $this->recounts(), 'Home: the counts of the menu kept');
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.categories'))->assertOk()->assertViewHas('categories', fn ($categories) => $this->counts($categories) === ['Alpha' => 2, 'Beta' => 1, 'Gamma' => 1]);
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.category', $this->b->slug))->assertOk()->assertViewHas('category', fn ($category) => $category->videos_count === 1);
        $this->assertSame(3, $this->recounts(), 'the Categories page and a category page: one statement each (the side menu of the Categories page: from its own count of every category)');

        // the next views of every page keep the counts
        $this->statements = [];
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.trend'))->assertOk()->assertViewHas('categories', fn ($categories) => $this->counts($categories) === ['Alpha' => 2, 'Beta' => 1]);
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.home'))->assertOk();
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.categories'))->assertOk()->assertViewHas('categories', fn ($categories) => $this->counts($categories) === ['Alpha' => 2, 'Beta' => 1, 'Gamma' => 1]);
        CategoryVideoCounts::flush(); // each view its own process, as on a site
        $this->get(route('pages.category', $this->b->slug))->assertOk()->assertViewHas('category', fn ($category) => $category->videos_count === 1);
        $this->assertSame(0, $this->recounts(), 'kept: no category is counted again');
    }

    public function test_every_kind_of_change_shows_at_the_next_page_view(): void
    {
        $one = $this->video($this->a);
        $two = $this->video($this->a);
        $private = $this->video($this->a, ['status' => VideoStatus::PRIVATE]);
        $this->video($this->b);
        $other = User::factory()->create();
        $theirs = $this->video($this->b, [], $other);
        $this->assertCounts(['Alpha' => 2, 'Beta' => 2], 'start');

        $private->update(['status' => VideoStatus::PUBLIC]);
        $this->assertCounts(['Alpha' => 3, 'Beta' => 2], 'a video published (model)');

        Video::query()->whereKey([$one->id, $two->id])->update(['status' => VideoStatus::PRIVATE->value]);
        $this->assertCounts(['Alpha' => 1, 'Beta' => 2], 'made private in bulk (query builder)');

        Video::query()->whereKey($private->id)->update(['category_id' => $this->b->id]);
        $this->assertCounts(['Alpha' => 0, 'Beta' => 3], 'moved to another category');

        DB::table('videos')->insert(array_merge(Video::factory()->for($this->creator)->withStatus(VideoStatus::PUBLIC)->make()->getAttributes(), [
            'uuid' => 'r3db-raw-insert', 'category_id' => $this->a->id, 'uploaded_at' => now()->subDay(), 'published_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now()]));
        $this->assertCounts(['Alpha' => 1, 'Beta' => 3], 'an import (raw insert)');

        DB::table('videos')->where('uuid', 'r3db-raw-insert')->delete();
        $this->assertCounts(['Alpha' => 0, 'Beta' => 3], 'an import removed (raw delete)');

        $theirs->delete();
        $this->assertCounts(['Alpha' => 0, 'Beta' => 2], 'to the bin');
        $theirs->restore();
        $this->assertCounts(['Alpha' => 0, 'Beta' => 3], 'back from the bin');

        $other->update(['banned_at' => now()]);
        $this->assertCounts(['Alpha' => 0, 'Beta' => 2], 'its creator banned');
        $other->update(['banned_at' => null]);
        $this->assertCounts(['Alpha' => 0, 'Beta' => 3], 'its creator unbanned');
        User::query()->whereKey($other->id)->update(['email_verified_at' => null]);
        $this->assertCounts(['Alpha' => 0, 'Beta' => 2], 'its creator unconfirmed (bulk)');
        User::query()->whereKey($other->id)->update(['email_verified_at' => now()]);

        Video::query()->whereKey($two->id)->update(['status' => VideoStatus::PUBLIC->value, 'language' => 'vi']);
        $this->assertCounts(['Alpha' => 0, 'Beta' => 3], 'a video in another language is not counted in English');
        $this->assertSame([$this->a->id => 1, $this->b->id => 3], CategoryVideoCounts::forIds([$this->a->id, $this->b->id], 'vi'), 'and is in Vietnamese');

        $two->forceDelete();
        $this->assertSame([$this->b->id => 3], CategoryVideoCounts::forIds([$this->a->id, $this->b->id], 'vi'), 'deleted for good');
    }

    public function test_a_video_that_becomes_visible_by_time_alone_is_counted_from_its_moment(): void
    {
        // within the 10 minutes an entry is kept at most (an hour later it is counted again anyway)
        $this->video($this->a);
        $this->video($this->a, ['status' => VideoStatus::PLANNED, 'scheduled_at' => now()->addMinutes(4), 'published_at' => now()->addMinutes(4)]);
        $this->video($this->b, ['published_at' => now()->addMinutes(7)]);
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'before');
        $this->listen();

        Carbon::setTestNow(now()->addMinutes(3));
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'a minute before');
        $this->assertSame(0, $this->recounts(), 'kept until the scheduled moment');

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertCounts(['Alpha' => 2, 'Beta' => 0], 'the scheduled video is due: counted without any write');
        Carbon::setTestNow(now()->addMinutes(3));
        $this->assertCounts(['Alpha' => 2, 'Beta' => 1], 'the public video dated later');
        $this->assertSame(2, $this->recounts());
    }

    public function test_an_entry_is_kept_at_most_ten_minutes(): void
    {
        $this->video($this->a);
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'start');
        $this->listen();

        Carbon::setTestNow(now()->addMinutes(9));
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'nine minutes later');
        $this->assertSame(0, $this->recounts(), 'kept');

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'eleven minutes later');
        $this->assertSame(1, $this->recounts(), 'counted again after 10 minutes without any change');
    }

    public function test_writes_that_change_no_count_keep_the_counts(): void
    {
        $video = $this->video($this->a);
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'start');
        $this->listen();

        Video::query()->whereKey($video->id)->increment('views');
        DB::table('videos')->where('id', $video->id)->update(['likes_count' => 3, 'comments_count' => 2]);
        $this->creator->update(['avatar' => 'r3db.svg']);
        User::factory()->create(); // a sign-up
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'after');
        $this->assertSame(0, $this->recounts(), 'views, likes, comments, an avatar and a sign-up count nothing again');
    }

    public function test_a_change_inside_a_transaction_changes_the_stamp_again_when_it_commits(): void
    {
        $video = $this->video($this->a, ['status' => VideoStatus::PRIVATE]);
        $this->assertCounts(['Alpha' => 0, 'Beta' => 0], 'start');
        $seen = [];

        DB::transaction(function () use ($video, &$seen): void {
            $video->update(['status' => VideoStatus::PUBLIC]);
            $seen[] = Cache::get(CategoryVideoCounts::STAMP_KEY);
        });
        $seen[] = Cache::get(CategoryVideoCounts::STAMP_KEY);

        $this->assertNotSame($seen[0], $seen[1], 'a request that counted before the commit kept its counts under a stamp nobody reads any more');
        $this->assertCounts(['Alpha' => 1, 'Beta' => 0], 'after the commit');
    }

    public function test_counts_worked_out_inside_a_rolled_back_transaction_are_shown_but_never_kept(): void
    {
        $this->video($this->a);
        $ids = [$this->a->id, $this->b->id];
        $this->assertSame([$this->a->id => 1], CategoryVideoCounts::forIds($ids, 'en'), 'start');

        // a view-only demo page runs inside a transaction that is rolled back (App\Http\Middleware\ViewOnlyDemo)
        DB::beginTransaction();
        try {
            $this->video($this->a);
            $this->assertSame([$this->a->id => 2], CategoryVideoCounts::forIds($ids, 'en'), 'the demo page shows its own change');
        } finally {
            DB::rollBack();
        }

        CategoryVideoCounts::flush(); // the next request
        $this->assertSame([$this->a->id => 1], CategoryVideoCounts::forIds($ids, 'en'), 'the rolled-back video was never kept');
    }

    public function test_one_entry_per_set_is_kept_and_overwritten_in_place(): void
    {
        $this->video($this->a);
        $ids = [$this->a->id, $this->b->id];
        $store = Cache::store();
        $keys = function () use ($store): array {
            $prop = new \ReflectionProperty($store->getStore(), 'storage');

            return array_values(array_filter(array_keys($prop->getValue($store->getStore())), fn (string $key) => str_starts_with($key, 'catalogue.category_counts.v')));
        };

        CategoryVideoCounts::forIds($ids, 'en');
        $before = $keys();
        foreach (range(1, 5) as $i) {
            $this->video($this->b); // a new stamp each time
            CategoryVideoCounts::flush();
            $this->assertSame([$this->a->id => 1, $this->b->id => $i], CategoryVideoCounts::forIds($ids, 'en'), 'change '.$i);
        }

        $this->assertCount(1, $before);
        $this->assertSame($before, $keys(), 'five changes later the same one entry: no entry is left behind under an old stamp');
    }

    public function test_another_default_language_is_another_set_of_counts(): void
    {
        // forLocale(): the default language counts its videos and those without a language, another language every
        // imported video too; the stamp does not change with the setting, so the default is part of the entry's name
        $native = $this->video($this->a, ['language' => 'vi']);
        $imported = $this->video($this->a, ['language' => 'en']);
        DB::table('videos')->where('id', $imported->id)->update(['avdb_id' => 'r3db-imported-1']);
        CategoryVideoCounts::flush();
        Cache::flush();
        $this->assertSame([$this->a->id => 2], CategoryVideoCounts::forIds([$this->a->id], 'vi'), 'Vietnamese, English the default: its own and the imported one');

        config(['app.fallback_locale' => 'vi']);
        CategoryVideoCounts::flush();
        $this->assertSame([$this->a->id => 1], CategoryVideoCounts::forIds([$this->a->id], 'vi'), 'Vietnamese the default: its own videos only');
        $this->assertNotNull($native);
    }

    public function test_the_admins_bulk_actions_show_at_once(): void
    {
        $videos = [$this->video($this->a), $this->video($this->a), $this->video($this->b)];
        $this->assertCounts(['Alpha' => 2, 'Beta' => 1], 'start');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['ids' => [$videos[0]->id], 'action' => 'make_private'])->assertRedirect();
        $this->assertCounts(['Alpha' => 1, 'Beta' => 1], 'made private by the admin');

        $this->actingAs($admin)->post(route('admin.bulk.videos'), ['ids' => [$videos[1]->id], 'action' => 'change_category', 'category_id' => $this->b->id])->assertRedirect();
        $this->assertCounts(['Alpha' => 0, 'Beta' => 2], 'moved by the admin');

        $this->actingAs($admin)->post(route('admin.bulk.users'), ['ids' => [$this->creator->id], 'action' => 'ban'])->assertRedirect();
        $this->assertCounts(['Alpha' => 0, 'Beta' => 0], 'its creator banned by the admin');
    }

    public function test_the_newest_feed_keeps_its_rows_and_order(): void
    {
        $older = $this->video($this->a, ['published_at' => now()->subDays(2)]);
        $newer = $this->video($this->b, ['published_at' => now()->subDay()]);
        $due = $this->video($this->a, ['status' => VideoStatus::PLANNED, 'scheduled_at' => now()->subHours(3), 'published_at' => now()->subHours(3)]);
        $this->video($this->a, ['status' => VideoStatus::PLANNED, 'scheduled_at' => now()->addHour(), 'published_at' => now()->addHour()]);
        $this->video($this->a, ['published_at' => now()->addDay()]);
        $this->video($this->a, ['status' => VideoStatus::PRIVATE]);
        // a public video without a publish date (1.2.0 to 1.2.2 made some: Unban, Make public): the newest feed lists it last
        $undated = $this->video($this->b);
        DB::table('videos')->where('id', $undated->id)->update(['published_at' => null]);

        $feed = app(PublicVideoFeedService::class);
        $this->assertSame([$due->id, $newer->id, $older->id, $undated->id], $feed->latest()->pluck('id')->all(), 'the newest first, the undated last');
        $first = $feed->page($feed->latest(), 2, null);
        $this->assertSame([$due->id, $newer->id], $first->getCollection()->pluck('id')->all());
        $second = $feed->page($feed->latest(), 2, $first->nextCursor()?->encode());
        $this->assertSame([$older->id, $undated->id], $second->getCollection()->pluck('id')->all());
    }

    /** @param  array<string, int>  $expected */
    private function assertCounts(array $expected, string $what): void
    {
        $this->get(route('pages.trend'))->assertOk()->assertViewHas('categories', function ($categories) use ($expected, $what): bool {
            $this->assertSame($expected, $this->counts($categories), $what);

            return true;
        });
    }

    /** @return array<string, int> */
    private function counts(iterable $categories): array
    {
        $out = [];
        foreach ($categories as $category) {
            $out[$category->title] = (int) $category->videos_count;
        }

        return $out;
    }

    private function recounts(): int
    {
        return count(array_filter($this->statements, fn (string $sql) => str_contains($sql, 'as "videos_count"')));
    }

    private function listen(): void
    {
        $this->statements = [];
        DB::listen(function ($query): void {
            $this->statements[] = $query->sql;
        });
    }

    private function video(Category $category, array $attributes = [], ?User $owner = null): Video
    {
        return Video::factory()->for($owner ?? $this->creator)->withStatus(VideoStatus::PUBLIC)->create(array_merge([
            'category_id' => $category->id, 'uploaded_at' => now()->subDays(3), 'published_at' => now()->subDays(3),
        ], $attributes));
    }

    private function category(string $title, int $position): Category
    {
        $category = Category::query()->create(['title' => $title, 'slug' => str($title)->slug()->toString(), 'icon' => 'folder', 'in_menu' => true]);
        $category->updateQuietly(['position' => $position]);

        return $category;
    }
}
