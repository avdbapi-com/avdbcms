<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\StudioHealthAlertsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioHealthAlertsSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_health_alerts_settings_are_seeded_from_service_defaults(): void
    {
        $defaults = StudioHealthAlertsService::settingsDefaults();

        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_enabled',
            'value' => $defaults['enabled'],
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_limit',
            'value' => (string) $defaults['limit'],
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'studio_health_alerts_lookback_days',
            'value' => (string) $defaults['lookback_days'],
            'group' => 'users',
        ]);

        $this->assertSame(
            [
                'studio_health_alerts_enabled',
                'studio_health_alerts_limit',
                'studio_health_alerts_lookback_days',
            ],
            Setting::query()
                ->whereIn('key', [
                    'studio_health_alerts_enabled',
                    'studio_health_alerts_limit',
                    'studio_health_alerts_lookback_days',
                ])
                ->orderBy('key')
                ->pluck('key')
                ->all()
        );
    }
}
