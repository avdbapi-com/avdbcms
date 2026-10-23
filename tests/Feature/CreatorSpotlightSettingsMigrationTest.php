<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\AdaptiveCreatorSpotlightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorSpotlightSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_spotlight_settings_are_seeded_from_service_defaults(): void
    {
        $defaults = AdaptiveCreatorSpotlightService::settingsDefaults();

        $this->assertDatabaseHas('settings', [
            'key' => 'creator_spotlight_enabled',
            'value' => $defaults['enabled'],
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_spotlight_limit',
            'value' => (string) $defaults['limit'],
            'group' => 'discovery',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_spotlight_lookback_days',
            'value' => (string) $defaults['lookback_days'],
            'group' => 'discovery',
        ]);

        $this->assertSame(
            [
                'creator_spotlight_enabled',
                'creator_spotlight_limit',
                'creator_spotlight_lookback_days',
            ],
            Setting::query()
                ->whereIn('key', [
                    'creator_spotlight_enabled',
                    'creator_spotlight_limit',
                    'creator_spotlight_lookback_days',
                ])
                ->orderBy('key')
                ->pluck('key')
                ->all()
        );
    }
}
