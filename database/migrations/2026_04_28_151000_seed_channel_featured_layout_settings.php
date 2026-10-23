<?php

use App\Models\Setting;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = [
        'channel_customization_featured_layout_enabled',
        'channel_customization_featured_sizing_enabled',
        'channel_default_featured_layout',
        'channel_default_featured_columns',
        'channel_default_featured_rows',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $defaults = ChannelCustomizationSettings::defaults();

        foreach ($this->keys as $key) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => (string) $defaults[$key],
                    'group' => 'users',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }

        Setting::clearCache($this->keys, ['users']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', $this->keys)
            ->delete();

        Setting::clearCache($this->keys, ['users']);
    }
};
