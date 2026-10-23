<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'sidebar_autoplay_switch_enabled',
        'watch_autoplay_toggle_enabled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', self::KEYS)
            ->delete();

        Setting::clearCache(self::KEYS, ['appearance']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::KEYS as $key) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => '1',
                    'group' => 'appearance',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        Setting::clearCache(self::KEYS, ['appearance']);
    }
};
