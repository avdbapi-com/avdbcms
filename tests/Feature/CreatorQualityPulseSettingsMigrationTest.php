<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\CreatorQualityPulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorQualityPulseSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_quality_pulse_settings_are_seeded_from_service_defaults(): void
    {
        $defaults = CreatorQualityPulseService::settingsDefaults();

        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_enabled',
            'value' => $defaults['enabled'],
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_limit',
            'value' => (string) $defaults['limit'],
            'group' => 'users',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'creator_quality_pulse_lookback_days',
            'value' => (string) $defaults['lookback_days'],
            'group' => 'users',
        ]);

        $this->assertSame(
            [
                'creator_quality_pulse_enabled',
                'creator_quality_pulse_limit',
                'creator_quality_pulse_lookback_days',
            ],
            Setting::query()
                ->whereIn('key', [
                    'creator_quality_pulse_enabled',
                    'creator_quality_pulse_limit',
                    'creator_quality_pulse_lookback_days',
                ])
                ->orderBy('key')
                ->pluck('key')
                ->all()
        );
    }
}
