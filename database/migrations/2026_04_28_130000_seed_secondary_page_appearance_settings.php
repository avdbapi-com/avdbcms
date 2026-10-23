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
            'secondary_page_design_enabled',
            'secondary_page_hero_enabled',
            'secondary_page_surfaces_enabled',
            'secondary_page_form_polish_enabled',
            'secondary_page_table_polish_enabled',
            'secondary_page_density',
            'secondary_page_max_width',
            'contact_illustration_enabled',
            'premium_comparison_enabled',
        ]));
    }
};
