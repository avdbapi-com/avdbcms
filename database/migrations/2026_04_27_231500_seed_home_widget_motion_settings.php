<?php

use App\Models\Setting;
use App\Support\AppearanceSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->settings() as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => (string) $value,
                    'group' => 'appearance',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        Setting::clearCache(array_keys($this->settings()), ['appearance']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys($this->settings()))
            ->delete();

        Setting::clearCache(array_keys($this->settings()), ['appearance']);
    }

    private function settings(): array
    {
        return array_intersect_key(AppearanceSettings::defaults(), array_flip([
            'home_widget_motion_enabled',
            'home_widget_motion_autorun_enabled',
            'home_widget_motion_card_cascade_enabled',
            'home_widget_motion_pause_on_hover_enabled',
            'home_widget_motion_style',
            'home_widget_motion_duration_ms',
            'home_widget_motion_distance_px',
            'home_widget_motion_stagger_ms',
            'home_widget_motion_speed_px_per_second',
            'home_widget_fresh_picks_motion_direction',
            'home_widget_smart_discovery_motion_direction',
            'home_widget_creator_spotlight_motion_direction',
            'home_widget_trending_collections_motion_direction',
        ]));
    }
};
