<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREROLL_SAMPLE = 'https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/1080/Big_Buck_Bunny_1080_10s_30MB.mp4';
    private const MIDROLL_SAMPLE = 'https://content.jwplatform.com/manifests/yp34SRmf.m3u8';

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ([
            'ad_midroll_enabled' => '1',
            'ad_midroll_position_percent' => '50',
            'ad_preroll_media_url' => self::PREROLL_SAMPLE,
            'ad_midroll_media_url' => self::MIDROLL_SAMPLE,
        ] as $key => $value) {
            $this->put($key, $value, false);
        }

        Setting::clearCache([
            'ad_midroll_enabled',
            'ad_midroll_position_percent',
            'ad_preroll_media_url',
            'ad_midroll_media_url',
        ], ['monetization']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach ([
            'ad_preroll_media_url' => self::PREROLL_SAMPLE,
            'ad_midroll_media_url' => self::MIDROLL_SAMPLE,
        ] as $key => $value) {
            DB::table('settings')
                ->where('group', 'monetization')
                ->where('key', $key)
                ->where('value', $value)
                ->update([
                    'value' => '',
                    'updated_at' => now(),
                ]);
        }

        Setting::clearCache([
            'ad_midroll_enabled',
            'ad_midroll_position_percent',
            'ad_preroll_media_url',
            'ad_midroll_media_url',
        ], ['monetization']);
    }

    private function put(string $key, string $value, bool $overwrite = true): void
    {
        $setting = DB::table('settings')
            ->where('key', $key)
            ->first();

        if (! $setting) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'group' => 'monetization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if (! $overwrite && trim((string) $setting->value) !== '') {
            return;
        }

        DB::table('settings')
            ->where('key', $key)
            ->update([
                'value' => $value,
                'group' => 'monetization',
                'updated_at' => now(),
            ]);
    }
};
