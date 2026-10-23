<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'ad_in_page_push_enabled',
        'ad_vignette_enabled',
        'ad_code_in_page_push',
        'ad_code_vignette',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'monetization')
            ->whereIn('key', self::KEYS)
            ->delete();

        $onclickSample = (string) DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_onclick')
            ->value('value');

        if (str_contains($onclickSample, '[data-avd-ad-close]')) {
            DB::table('settings')
                ->where('group', 'monetization')
                ->where('key', 'ad_code_onclick')
                ->update([
                    'value' => str_replace(
                        [', [data-avd-ad-close]', '[data-avd-ad-close], '],
                        '',
                        $onclickSample
                    ),
                    'updated_at' => now(),
                ]);
        }

        Setting::clearCache(self::KEYS, ['monetization']);
        Setting::clearCache(['ad_code_onclick'], ['monetization']);
    }

    public function down(): void
    {
        // Overlay ad formats were intentionally removed from the product surface.
    }
};
