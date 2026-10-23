<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeDiscoveryFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_uses_menu_categories_when_smart_discovery_has_no_matches(): void
    {
        Cache::flush();

        config([
            'site.smart_discovery_rail_enabled' => true,
            'site.smart_discovery_rail_limit' => 2,
            'site.smart_discovery_rail_lookback_days' => 14,
        ]);

        Category::query()->create([
            'title' => 'Music',
            'slug' => 'music',
            'icon' => 'music',
            'in_menu' => true,
            'position' => 1,
        ]);
        Category::query()->create([
            'title' => 'Movies',
            'slug' => 'movies',
            'icon' => 'film',
            'in_menu' => true,
            'position' => 2,
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Explore categories')
            ->assertSee('Music')
            ->assertSee('Movies')
            ->assertDontSee('Categories gaining momentum');
    }

    public function test_home_category_rails_fallback_to_folder_icon_when_icon_is_invalid(): void
    {
        Cache::flush();

        config([
            'site.smart_discovery_rail_enabled' => true,
            'site.smart_discovery_rail_limit' => 1,
            'site.smart_discovery_rail_lookback_days' => 14,
        ]);

        Category::query()->create([
            'title' => 'Broken Icon',
            'slug' => 'broken-icon',
            'icon' => 'bad icon!',
            'in_menu' => true,
            'position' => 1,
        ]);

        $this->get(route('pages.home'))
            ->assertOk()
            ->assertSee('Broken Icon')
            ->assertSee('fa-folder', false)
            ->assertDontSee('fa-bad icon!', false);
    }
}
