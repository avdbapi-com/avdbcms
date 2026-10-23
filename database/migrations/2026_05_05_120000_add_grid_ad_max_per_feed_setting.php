<?php

use App\Models\Setting;
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

        if (! DB::table('settings')->where('key', 'ad_grid_max_per_feed')->exists()) {
            DB::table('settings')->insert([
                'key' => 'ad_grid_max_per_feed',
                'value' => '1',
                'group' => 'monetization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache(['ad_grid_max_per_feed'], ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_grid_max_per_feed')
            ->delete();

        Setting::clearCache(['ad_grid_max_per_feed'], ['monetization']);
    }
};
