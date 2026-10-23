<?php

use App\Models\Setting;
use App\Support\HomeWidgetVideoSettings;
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

        foreach (HomeWidgetVideoSettings::defaults() as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'group' => 'discovery',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        Setting::clearCache(array_keys(HomeWidgetVideoSettings::defaults()), ['discovery']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys(HomeWidgetVideoSettings::defaults()))
            ->delete();

        Setting::clearCache(array_keys(HomeWidgetVideoSettings::defaults()), ['discovery']);
    }
};
