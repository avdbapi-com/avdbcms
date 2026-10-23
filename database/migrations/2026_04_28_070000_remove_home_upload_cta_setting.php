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

        DB::table('settings')->where('key', 'home_upload_cta_enabled')->delete();

        Setting::clearCache(['home_upload_cta_enabled'], ['appearance']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! DB::table('settings')->where('key', 'home_upload_cta_enabled')->exists()) {
            DB::table('settings')->insert([
                'key' => 'home_upload_cta_enabled',
                'value' => '1',
                'group' => 'appearance',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        Setting::clearCache(['home_upload_cta_enabled'], ['appearance']);
    }
};
