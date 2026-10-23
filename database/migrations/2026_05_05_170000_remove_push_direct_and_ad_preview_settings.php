<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REMOVED_KEYS = [
        'ad_push_notifications_enabled',
        'ad_direct_link_enabled',
        'ad_direct_link_url',
        'ad_code_push_notifications',
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

        $onclickSample = (string) DB::table('settings')
            ->where('group', 'monetization')
            ->where('key', 'ad_code_onclick')
            ->value('value');

        if (str_contains($onclickSample, 'Ad preview loaded. Click empty page space once')) {
            $onclickSample = preg_replace(
                "/\\n\\s*document\\.addEventListener\\('DOMContentLoaded', function \\(\\) \\{\\n\\s*if \\(window\\.AD_CONFIG && window\\.AD_CONFIG\\.preview\\) \\{\\n\\s*showToast\\('Ad preview loaded\\. Click empty page space once to test the OnClick \\/ Popunder sample\\.'\\);\\n\\s*}\\n\\s*}\\);\\n/s",
                "\n",
                $onclickSample
            ) ?? $onclickSample;

            DB::table('settings')
                ->where('group', 'monetization')
                ->where('key', 'ad_code_onclick')
                ->update([
                    'value' => $onclickSample,
                    'updated_at' => now(),
                ]);
        }

        Setting::clearCache(array_merge(self::REMOVED_KEYS, ['ad_code_onclick']), ['monetization']);
    }

    public function down(): void
    {
        // Push notifications, direct link settings, and admin ad preview were intentionally removed.
    }
};
