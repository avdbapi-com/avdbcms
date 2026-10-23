<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'premium_comparison_downloads_row_enabled';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $setting = DB::table('settings')->where('key', self::KEY);

        if ($setting->exists()) {
            $setting->update([
                'value' => '0',
                'group' => 'pages',
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')->insert([
                'key' => self::KEY,
                'value' => '0',
                'group' => 'pages',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        Setting::clearCache([self::KEY], ['pages']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', self::KEY)
            ->update([
                'value' => '1',
                'group' => 'pages',
                'updated_at' => now(),
            ]);

        Setting::clearCache([self::KEY], ['pages']);
    }
};
