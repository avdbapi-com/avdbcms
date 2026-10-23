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

        $defaultLocale = (string) config('app.locale', 'en');

        if (Schema::hasTable('languages')) {
            $languageDefault = DB::table('languages')
                ->where('is_default', true)
                ->value('code');

            if ($languageDefault) {
                $defaultLocale = (string) $languageDefault;
            }
        }

        $this->setDefault('localization', 'default_locale', $defaultLocale);
        $this->setDefault('localization', 'allow_language_switch', '1');
        $this->setDefault('uploads', 'thumbnail_width', '640');
        $this->setDefault('uploads', 'thumbnail_height', '360');

        Setting::clearCache(
            ['default_locale', 'allow_language_switch', 'thumbnail_width', 'thumbnail_height'],
            ['localization', 'uploads']
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->whereIn('key', ['default_locale', 'allow_language_switch', 'thumbnail_width', 'thumbnail_height'])
            ->delete();

        Setting::clearCache(
            ['default_locale', 'allow_language_switch', 'thumbnail_width', 'thumbnail_height'],
            ['localization', 'uploads']
        );
    }

    private function setDefault(string $group, string $key, string $value): void
    {
        $existing = DB::table('settings')->where('key', $key)->first();

        if ($existing) {
            if ($existing->group !== $group) {
                DB::table('settings')
                    ->where('key', $key)
                    ->update([
                        'group' => $group,
                        'updated_at' => now(),
                    ]);
            }

            return;
        }

        DB::table('settings')->insert([
            'key' => $key,
            'value' => $value,
            'group' => $group,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
