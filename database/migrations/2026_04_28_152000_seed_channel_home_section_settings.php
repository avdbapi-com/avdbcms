<?php

use App\Models\Setting;
use App\Support\ChannelCustomizationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = [
        'channel_customization_latest_uploads_enabled',
        'channel_customization_latest_layout_enabled',
        'channel_customization_latest_sizing_enabled',
        'channel_customization_playlists_enabled',
        'channel_customization_playlists_layout_enabled',
        'channel_customization_playlists_sizing_enabled',
        'channel_default_latest_uploads_layout',
        'channel_default_latest_uploads_columns',
        'channel_default_latest_uploads_rows',
        'channel_default_playlists_layout',
        'channel_default_playlists_columns',
        'channel_default_playlists_rows',
        'channel_default_playlist_video_columns',
        'channel_default_playlist_video_rows',
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
