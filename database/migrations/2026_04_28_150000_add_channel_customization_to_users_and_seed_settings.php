<?php

use App\Models\Setting;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'channel_customization')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->json('channel_customization')->nullable()->after('show_subscribers');
            });
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (ChannelCustomizationSettings::defaults() as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => (string) $value,
                    'group' => 'users',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        Setting::clearCache(array_keys(ChannelCustomizationSettings::defaults()), ['users']);
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'channel_customization')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('channel_customization');
            });
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', array_keys(ChannelCustomizationSettings::defaults()))
            ->delete();

        Setting::clearCache(array_keys(ChannelCustomizationSettings::defaults()), ['users']);
    }
};
