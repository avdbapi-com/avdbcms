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

        foreach ([
            'honeypot_enabled' => '1',
            'captcha_provider' => 'built_in',
            'captcha_site_key' => '',
            'captcha_secret_key' => '',
            'captcha_on_login' => '1',
            'captcha_on_register' => '1',
            'captcha_on_contact' => '1',
            'captcha_on_password_reset' => '1',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $value,
                    'group' => 'security',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        Setting::clearCache([
            'honeypot_enabled',
            'captcha_provider',
            'captcha_site_key',
            'captcha_secret_key',
            'captcha_on_login',
            'captcha_on_register',
            'captcha_on_contact',
            'captcha_on_password_reset',
        ], ['security']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('group', 'security')
            ->whereIn('key', [
                'honeypot_enabled',
                'captcha_provider',
                'captcha_site_key',
                'captcha_secret_key',
                'captcha_on_login',
                'captcha_on_register',
                'captcha_on_contact',
                'captcha_on_password_reset',
            ])
            ->delete();

        Setting::clearCache([], ['security']);
    }
};
