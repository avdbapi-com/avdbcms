<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = [
        'ad_grid_layout_smart_discovery',
        'ad_grid_layout_creator_spotlight',
        'ad_grid_layout_trending_actors',
        'ad_grid_layout_new_videos',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ($this->keys as $key) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'value' => 'inherit',
                'group' => 'monetization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache($this->keys, ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->whereIn('key', $this->keys)
            ->delete();

        Setting::clearCache($this->keys, ['monetization']);
    }
};
