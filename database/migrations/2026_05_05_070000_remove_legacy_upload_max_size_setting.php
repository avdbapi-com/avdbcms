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

        DB::table('settings')
            ->where('group', 'uploads')
            ->where('key', 'upload_max_size')
            ->delete();

        Setting::clearCache(['upload_max_size'], ['uploads']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        if (! DB::table('settings')->where('key', 'upload_max_size')->exists()) {
            DB::table('settings')->insert([
                'key' => 'upload_max_size',
                'value' => '2048',
                'group' => 'uploads',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache(['upload_max_size'], ['uploads']);
    }
};
