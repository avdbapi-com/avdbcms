<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeCreatorSpotlightTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_creator_spotlight_when_creators_qualify(): void
    {
        Cache::flush();

        config([
            'site.creator_spotlight_enabled' => true,
            'site.creator_spotlight_limit' => 2,
            'site.creator_spotlight_lookback_days' => 14,
        ]);

        $creator = User::factory()->create([
            'username' => 'Creator Alpha',
            'show_subscribers' => true,
        ]);
        Video::factory()
            ->for($creator)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
            ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Creators posting now')
            ->assertSee('Creator Alpha')
            ->assertDontSee('Find creators by category');
    }

    public function test_home_uses_category_fallback_when_no_creators_qualify(): void
    {
        Cache::flush();

        config([
            'site.creator_spotlight_enabled' => true,
            'site.creator_spotlight_limit' => 1,
            'site.creator_spotlight_lookback_days' => 14,
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
            ->assertSee('Find creators by category')
            ->assertSee('Music')
            ->assertSee('Browse creators');
    }

    public function test_home_backfills_creator_spotlight_with_active_users_without_recent_videos(): void
    {
        Cache::flush();

        config([
            'site.creator_spotlight_enabled' => true,
            'site.creator_spotlight_limit' => 2,
            'site.creator_spotlight_lookback_days' => 14,
        ]);

        User::factory()->create([
            'username' => 'Quiet Creator',
            'show_subscribers' => true,
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Creators posting now')
            ->assertSee('Quiet Creator')
            ->assertSee('Discover channel')
            ->assertDontSee('Find creators by category');
    }

    public function test_home_hides_creator_spotlight_when_feature_is_disabled(): void
    {
        Cache::flush();

        config([
            'site.creator_spotlight_enabled' => false,
            'site.creator_spotlight_limit' => 1,
            'site.creator_spotlight_lookback_days' => 14,
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
            ->assertDontSee('Creator spotlight')
            ->assertDontSee('Find creators by category')
            ->assertDontSee('Browse creators');
    }
}
