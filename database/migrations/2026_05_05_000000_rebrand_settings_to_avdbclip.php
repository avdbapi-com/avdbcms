<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        $settings = [
            ['key' => 'site_name', 'value' => 'AVDBCMS', 'group' => 'general'],
            ['key' => 'contact_email', 'value' => 'support@example.com', 'group' => 'general'],
            ['key' => 'footer_text', 'value' => '(c) 2026 AVDBCMS. All rights reserved.', 'group' => 'appearance'],
            ['key' => 'mail_from_address', 'value' => 'noreply@example.com', 'group' => 'email'],
            ['key' => 'mail_from_name', 'value' => 'AVDBCMS', 'group' => 'email'],
            ['key' => 'social_telegram', 'value' => 'https://t.me/+jj6McO-iYfA0MTM1', 'group' => 'sociallinks'],
        ];

        foreach ($settings as $setting) {
            $query = DB::table('settings')->where('key', $setting['key']);

            if ($query->exists()) {
                $query->update([
                    'value' => $setting['value'],
                    'group' => $setting['group'],
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('settings')->insert([
                'key' => $setting['key'],
                'value' => $setting['value'],
                'group' => $setting['group'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->clearSettingsCache();
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', 'social_telegram')
            ->whereIn('value', ['https://t.me/avdb_api', 'https://t.me/+jj6McO-iYfA0MTM1'])
            ->delete();

        $this->clearSettingsCache();
    }

    private function clearSettingsCache(): void
    {
        Cache::forget('settings.__all_grouped');

        foreach (['general', 'appearance', 'email', 'sociallinks'] as $group) {
            Cache::forget("settings.{$group}");
        }

        foreach (['site_name', 'contact_email', 'footer_text', 'mail_from_address', 'mail_from_name', 'social_telegram'] as $key) {
            Cache::forget("setting.{$key}");
        }
    }
};
