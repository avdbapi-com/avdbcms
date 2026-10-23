<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PublicApiScaleCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('scout.driver', 'collection');
        Config::set('cache.public_search_ttl', 60);
        Config::set('site.api_rate_limit', 10000);
        Config::set('site.search_rate_limit', 1000);
    }

    public function test_public_video_feeds_send_short_edge_cache_headers(): void
    {
        $category = $this->category();

        $this->assertPublicCacheHeader(
            $this->getJson(route('videos.index')),
            30
        );

        $this->assertPublicCacheHeader(
            $this->getJson(route('videos.trend')),
            30
        );

        $this->assertPublicCacheHeader(
            $this->getJson(route('categories.videos', $category)),
            30
        );
    }

    public function test_public_search_sends_short_edge_cache_headers(): void
    {
        $this->assertPublicCacheHeader(
            $this->getJson(route('search', ['q' => 'repeatable'])),
            60
        );
    }

    public function test_public_search_payload_is_cached_for_repeated_anonymous_queries(): void
    {
        $video = $this->publicVideo('Cached Search Needle');

        $this->getJson(route('search', ['q' => 'Cached Search Needle']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.title', 'Cached Search Needle');

        $video->forceFill([
            'title' => 'Renamed Search Result',
            'slug' => 'renamed-search-result',
        ])->save();

        $this->getJson(route('search', ['q' => 'Cached Search Needle']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.title', 'Cached Search Needle');

        Cache::flush();

        $this->getJson(route('search', ['q' => 'Cached Search Needle']))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', []);
    }

    private function assertPublicCacheHeader($response, int $maxAge): void
    {
        $response->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString("max-age={$maxAge}", $cacheControl);
    }

    private function publicVideo(string $title): Video
    {
        return Video::factory()
            ->for(User::factory(), 'user')
            ->for($this->category())
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'description' => 'A stable description for cache testing.',
                'published_at' => now()->subMinute(),
                'uploaded_at' => now()->subMinute(),
                'language' => null,
            ]);
    }

    private function category(): Category
    {
        return Category::query()->create([
            'title' => 'Scale Cache',
            'slug' => 'scale-cache-' . str()->random(8),
            'icon' => 'play',
            'in_menu' => true,
            'position' => 1,
        ]);
    }
}
