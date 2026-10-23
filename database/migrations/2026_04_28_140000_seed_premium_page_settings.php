<?php

use App\Models\Setting;
use App\Support\PremiumPageSettings;
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

        foreach (PremiumPageSettings::defaults() as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => (string) $value,
                    'group' => 'pages',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        Setting::clearCache(array_keys(PremiumPageSettings::defaults()), ['pages']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys(PremiumPageSettings::defaults()))
            ->delete();

        Setting::clearCache(array_keys(PremiumPageSettings::defaults()), ['pages']);
    }
};
