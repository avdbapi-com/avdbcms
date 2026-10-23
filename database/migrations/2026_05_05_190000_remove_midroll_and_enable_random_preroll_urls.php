<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREROLL_SAMPLE = 'https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/1080/Big_Buck_Bunny_1080_10s_30MB.mp4';
    private const HLS_SAMPLE = 'https://content.jwplatform.com/manifests/yp34SRmf.m3u8';

    private const REMOVED_KEYS = [
        'ad_midroll_enabled',
        'ad_midroll_media_url',
        'ad_midroll_position_percent',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->whereIn('key', self::REMOVED_KEYS)
            ->delete();

        $current = (string) DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_preroll_media_url')
            ->value('value');

        $trimmed = trim($current);
        if ($trimmed === '' || $trimmed === self::PREROLL_SAMPLE) {
            $this->put('ad_preroll_media_url', self::PREROLL_SAMPLE . "\n" . self::HLS_SAMPLE);
        }

        Setting::clearCache(array_merge(self::REMOVED_KEYS, ['ad_preroll_media_url']), ['monetization']);
    }

    public function down(): void
    {
        // Mid-roll player timing was removed because iframe embeds cannot expose reliable playback progress.
    }

    private function put(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $value,
                'group' => 'monetization',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
};
