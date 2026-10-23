<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\AdaptiveTrendingCollectionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrendingCollectionsSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_trending_collections_settings_are_seeded_from_service_defaults(): void
    {
        $defaults = AdaptiveTrendingCollectionsService::settingsDefaults();

        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_enabled',
            'value' => $defaults['enabled'],
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_limit',
            'value' => (string) $defaults['limit'],
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'trending_collections_lookback_days',
            'value' => (string) $defaults['lookback_days'],
            'group' => 'discovery',
        ]);

        $this->assertSame(
            [
                'trending_collections_enabled',
                'trending_collections_limit',
                'trending_collections_lookback_days',
            ],
            Setting::query()
                ->whereIn('key', [
                    'trending_collections_enabled',
                    'trending_collections_limit',
                    'trending_collections_lookback_days',
                ])
                ->orderBy('key')
                ->pluck('key')
                ->all()
        );
    }
}
