<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeTrendingCollectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_trending_collections_when_categories_receive_recent_views(): void
    {
        Cache::flush();

        config([
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 2,
            'site.trending_collections_lookback_days' => 14,
        ]);

        $category = Category::query()->create([
            'title' => 'Action',
            'slug' => 'action',
            'icon' => 'film',
            'in_menu' => true,
            'position' => 1,
        ]);
        $creator = User::factory()->create();
        $video = Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'avdb_actor' => 'Mio Futaba',
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
            ]);

        View::query()->create([
            'video_id' => $video->id,
            'user_id' => null,
            'ip' => '127.0.0.1',
            'view_at' => now()->subHour(),
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Trending actors')
            ->assertSee('Actors viewers are watching now')
            ->assertSee('Mio Futaba')
            ->assertSee('q=Mio%20Futaba', false);
    }

    public function test_home_uses_category_fallback_when_no_collections_qualify(): void
    {
        Cache::flush();

        config([
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 1,
            'site.trending_collections_lookback_days' => 14,
        ]);

        Category::query()->create([
            'title' => 'Music',
            'slug' => 'music',
            'icon' => 'music',
            'in_menu' => true,
            'position' => 1,
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Collections to start with')
            ->assertSee('Music')
            ->assertSee('Browse videos')
            ->assertDontSee('Collections viewers are opening now');
    }

    public function test_home_omits_trending_collections_when_feature_is_disabled(): void
    {
        Cache::flush();

        config([
            'site.trending_collections_enabled' => false,
            'site.trending_collections_limit' => 2,
            'site.trending_collections_lookback_days' => 14,
        ]);

        $category = $this->createCategory('Disabled Trend');
        $creator = User::factory()->create();
        $video = $this->createPublicVideo($category, $creator);

        View::query()->create([
            'video_id' => $video->id,
            'user_id' => null,
            'ip' => '127.0.0.10',
            'view_at' => now()->subHour(),
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertDontSee('Trending collections')
            ->assertDontSee('Actors viewers are watching now')
            ->assertDontSee('Collections to start with');
    }

    public function test_home_query_count_stays_bounded_with_all_discovery_rails_enabled(): void
    {
        Cache::flush();
        $this->withoutVite();

        config([
            'site.smart_discovery_rail_enabled' => true,
            'site.smart_discovery_rail_limit' => 4,
            'site.smart_discovery_rail_lookback_days' => 14,
            'site.creator_spotlight_enabled' => true,
            'site.creator_spotlight_limit' => 4,
            'site.creator_spotlight_lookback_days' => 21,
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 4,
            'site.trending_collections_lookback_days' => 14,
        ]);

        $action = $this->createCategory('Action');
        $music = $this->createCategory('Music');
        $firstCreator = User::factory()->create(['show_subscribers' => true]);
        $secondCreator = User::factory()->create(['show_subscribers' => true]);
        $firstVideo = $this->createPublicVideo($action, $firstCreator, 'Actor One');
        $secondVideo = $this->createPublicVideo($music, $secondCreator, 'Actor Two');
        $firstVideo->tags()->attach($this->createTag('Popular')->id);
        $secondVideo->tags()->attach($this->createTag('Featured')->id);

        foreach ([$firstVideo, $secondVideo] as $index => $video) {
            View::query()->create([
                'video_id' => $video->id,
                'user_id' => null,
                'ip' => '127.0.1.' . ($index + 1),
                'view_at' => now()->subMinutes($index + 1),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Tags with the most videos')
            ->assertSee('Actors viewers are watching now')
            ->assertSee('Creators posting now');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(24, $queryCount, 'Homepage discovery rail query count exceeded bound.');
    }

    public function test_home_discovery_widgets_use_admin_configured_text(): void
    {
        Cache::flush();
        $this->withoutVite();

        config([
            'site.smart_discovery_rail_enabled' => true,
            'site.smart_discovery_rail_limit' => 2,
            'site.smart_discovery_rail_kicker' => 'Custom Smart Kicker',
            'site.smart_discovery_rail_title' => 'Custom Smart Title',
            'site.smart_discovery_rail_link_label' => 'Custom Smart Link',
            'site.creator_spotlight_enabled' => true,
            'site.creator_spotlight_limit' => 2,
            'site.creator_spotlight_kicker' => 'Custom Creator Kicker',
            'site.creator_spotlight_title' => 'Custom Creator Title',
            'site.creator_spotlight_link_label' => 'Custom Creator Link',
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 2,
            'site.trending_collections_lookback_days' => 14,
            'site.trending_collections_kicker' => 'Custom Actor Kicker',
            'site.trending_collections_title' => 'Custom Actor Title',
            'site.trending_collections_link_label' => 'Custom Actor Link',
        ]);

        $category = $this->createCategory('Config Copy');
        $creator = User::factory()->create(['show_subscribers' => true]);
        $video = $this->createPublicVideo($category, $creator, 'Config Actor');
        $video->tags()->attach($this->createTag('Config Tag')->id);

        View::query()->create([
            'video_id' => $video->id,
            'user_id' => null,
            'ip' => '127.0.3.1',
            'view_at' => now()->subHour(),
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Custom Smart Kicker')
            ->assertSee('Custom Smart Title')
            ->assertSee('Custom Smart Link')
            ->assertSee('Custom Creator Kicker')
            ->assertSee('Custom Creator Title')
            ->assertSee('Custom Creator Link')
            ->assertSee('Custom Actor Kicker')
            ->assertSee('Custom Actor Title')
            ->assertSee('Custom Actor Link');
    }

    private function createCategory(string $title): Category
    {
        return Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'film',
            'in_menu' => true,
            'position' => 1,
        ]);
    }

    private function createPublicVideo(Category $category, User $creator, ?string $actor = null): Video
    {
        return Video::factory()
            ->for($category)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'avdb_actor' => $actor,
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
            ]);
    }

    private function createTag(string $name): Tag
    {
        return Tag::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'usage_count' => 0,
        ]);
    }
}
