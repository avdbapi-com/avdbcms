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
            'mobile_video_columns',
            'mobile_video_card_density',
            'studio_video_preview_mode',
            'enhanced_selects_enabled',
            'horizontal_rails_hide_scrollbars_enabled',
            'scroll_panel_hover_guard_enabled',
        ]));
    }
};
