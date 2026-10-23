<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AdminSettingsSchemaCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_settings_and_admin_schema_are_aligned(): void
    {
        $schema = $this->settingsSchema();
        $schemaByGroup = [];

        foreach ($schema as $group => $fields) {
            $schemaByGroup[$group] = array_fill_keys(array_keys($fields), true);
        }

        $settingsByGroup = Setting::query()
            ->get(['group', 'key'])
            ->groupBy('group');

        $unknownDatabaseKeys = [];
        foreach ($settingsByGroup as $group => $settings) {
            foreach ($settings as $setting) {
                if (!isset($schemaByGroup[$group][$setting->key])) {
                    $unknownDatabaseKeys[] = "{$group}.{$setting->key}";
                }
            }
        }

        $requiredSeededKeys = [
            'uploads.upload_max_duration',
            'uploads.upload_allowed_formats',
            'uploads.default_video_status',
            'uploads.default_video_language',
            'uploads.allow_custom_thumbnails',
            'uploads.thumbnail_width',
            'uploads.thumbnail_height',
            'comments.allow_replies',
            'localization.default_locale',
            'localization.allow_language_switch',
            'users.show_subscriber_count',
            'seo.home_meta_title_template',
            'seo.category_meta_description_template',
            'seo.profile_uid_urls_enabled',
            'sociallinks.social_telegram',
        ];

        $this->assertSame([], $unknownDatabaseKeys, 'Database settings missing from admin schema.');

        foreach ($requiredSeededKeys as $setting) {
            [$group, $key] = explode('.', $setting, 2);

            $this->assertTrue(
                (bool) ($settingsByGroup[$group] ?? collect())->contains('key', $key),
                "{$setting} should be seeded."
            );
        }
    }

    private function settingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class));
    }
}
