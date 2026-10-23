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

        DB::table('settings')->where('key', 'home_feed_rows')->delete();

        if (! DB::table('settings')->where('key', 'home_feed_load_mode')->exists()) {
            DB::table('settings')->insert([
                'key' => 'home_feed_load_mode',
                'value' => 'infinite',
                'group' => 'appearance',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        Setting::clearCache(['home_feed_rows', 'home_feed_load_mode'], ['appearance']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->where('key', 'home_feed_load_mode')->delete();

        if (! DB::table('settings')->where('key', 'home_feed_rows')->exists()) {
            DB::table('settings')->insert([
                'key' => 'home_feed_rows',
                'value' => '6',
                'group' => 'appearance',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        Setting::clearCache(['home_feed_rows', 'home_feed_load_mode'], ['appearance']);
    }
};
