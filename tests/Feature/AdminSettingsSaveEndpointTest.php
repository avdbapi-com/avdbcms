<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AppearanceApplier;
use App\Support\AppearanceSettings;
use App\Support\SettingsRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PUT /admin/settings/save (interface I7): one save for a page whose fields belong to several settings groups.
 * Only the listed keys are validated and written, with the legacy group rules, in one transaction.
 */
class AdminSettingsSaveEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_the_listed_keys_of_several_groups_in_one_request(): void
    {
        Setting::set('contact_email', 'owner@example.com', 'general');
        Setting::set('mail_from_name', 'Old sender', 'email');
        Setting::set('support_email', 'help@example.com', 'email');
        $before = $this->rows();

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => [
                    'general' => ['site_name' => 'Night Owl Clips', 'contact_email' => 'ignored@example.com'],
                    'email' => ['mail_from_name' => 'Night Owl'],
                    'security' => ['force_https' => '1'],
                ],
                'fields' => [
                    'general' => ['site_name'],
                    'email' => ['mail_from_name'],
                    'security' => ['force_https'],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'saved' => ['general' => ['site_name'], 'email' => ['mail_from_name'], 'security' => ['force_https']],
                'values' => ['general' => ['site_name' => 'Night Owl Clips'], 'email' => ['mail_from_name' => 'Night Owl'], 'security' => ['force_https' => '1']],
            ]);

        $after = $this->rows();
        $this->assertSame(['Night Owl Clips', 'general'], $after['site_name']);
        $this->assertSame(['Night Owl', 'email'], $after['mail_from_name']);
        $this->assertSame(['1', 'security'], $after['force_https']);

        // A key that was sent but not listed, and every other key of those groups, stay as they were.
        foreach ($before as $key => $row) {
            if (! in_array($key, ['site_name', 'mail_from_name', 'force_https'], true)) {
                $this->assertSame($row, $after[$key], $key);
            }
        }
        $this->assertSame([], array_values(array_diff(array_keys($after), array_keys($before), ['site_name', 'force_https'])));
    }

    public function test_a_listed_switch_that_is_absent_is_saved_as_off(): void
    {
        Setting::set('registration_enabled', '1', 'general');
        Setting::set('allow_uploads', '1', 'general');

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', ['fields' => ['general' => ['registration_enabled']]])
            ->assertOk();

        $this->assertSame('0', Setting::get('registration_enabled'));
        $this->assertSame('1', Setting::get('allow_uploads'));
    }

    public function test_one_invalid_key_writes_nothing_in_any_group(): void
    {
        Setting::set('site_name', 'Before', 'general');
        $before = $this->rows();

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => [
                    'general' => ['site_name' => 'After', 'per_page_videos' => 'lots'],
                    'email' => ['mail_from_name' => 'After'],
                    'maintenance' => ['maintenance_mode_enabled' => '1'],
                ],
                'fields' => [
                    'general' => ['site_name', 'per_page_videos'],
                    'email' => ['mail_from_name'],
                    'maintenance' => ['maintenance_mode_enabled'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonValidationErrors(['general.per_page_videos'])
            ->assertJsonMissingValidationErrors(['general.site_name', 'email.mail_from_name']);

        $this->assertSame($before, $this->rows());
    }

    public function test_unknown_groups_and_keys_are_rejected(): void
    {
        $siteName = Setting::get('site_name');

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => ['cdn' => ['cdn_enabled' => '1'], 'general' => ['made_up_key' => 'x', 'site_name' => 'Ok']],
                'fields' => ['cdn' => ['cdn_enabled'], 'general' => ['made_up_key', 'site_name']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cdn', 'general.made_up_key']);

        $this->assertNull(Setting::get('cdn_enabled'));
        $this->assertSame($siteName, Setting::get('site_name'));

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', ['groups' => ['general' => ['site_name' => 'Ok']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields']);
    }

    public function test_values_are_cast_and_have_the_same_side_effects_as_the_group_save(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => [
                    'seo' => ['video_url_prefix' => 'Movies//Clips/', 'video_url_pattern' => '/{slug}-{id}/'],
                    'localization' => ['default_locale' => 'vi'],
                    'appearance' => ['mobile_bottom_nav_enabled' => '1'],
                ],
                'fields' => [
                    'seo' => ['video_url_prefix', 'video_url_pattern'],
                    'localization' => ['default_locale'],
                    'appearance' => ['mobile_bottom_nav_enabled'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('values.seo.video_url_prefix', 'movies/clips');

        $this->assertSame('movies/clips', Setting::get('video_url_prefix'));
        $this->assertSame('{slug}-{id}', Setting::get('video_url_pattern'));
        $this->assertSame('vi', Setting::get('default_locale'));
        $this->assertTrue((bool) Language::query()->where('code', 'vi')->value('is_default'));
        $this->assertSame('1', Setting::get('mobile_bottom_nav_enabled'));
        $this->assertSame('1', Setting::get('mobile_bottom_dock_enabled'));
        // Keys of those groups that were not listed are not reset (the legacy group save would write them all).
        $this->assertNull(Setting::get('timezone'));
        $this->assertNull(Setting::get('meta_title_template'));
    }

    public function test_the_new_appearance_keys_of_the_theme_catalogue_are_accepted(): void
    {
        if (! method_exists(AppearanceSettings::class, 'v2TextKeys')) {
            $this->markTestSkipped('The theme catalogue v2 (WP2) adds these settings.');
        }

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => ['appearance' => ['theme_flavor' => 'signature', 'sidebar_style' => 'rail', 'brand_contrast_autofix' => '1']],
                'fields' => ['appearance' => ['theme_flavor', 'sidebar_style', 'brand_contrast_autofix', 'daily_design_layouts_enabled']],
            ])
            ->assertOk();

        $appearance = Setting::getGroup('appearance');
        $this->assertSame('signature', $appearance['theme_flavor'] ?? null);
        $this->assertSame('rail', $appearance['sidebar_style'] ?? null);
        $this->assertSame('1', $appearance['brand_contrast_autofix'] ?? null);
        $this->assertSame('0', $appearance['daily_design_layouts_enabled'] ?? null);

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => ['appearance' => ['sidebar_style' => 'sideways']],
                'fields' => ['appearance' => ['sidebar_style']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appearance.sidebar_style']);

        // The catalogue's bookkeeping (upgrade state, old theme) is written by the upgrade and the applier only.
        $version = Setting::getGroup('appearance')['appearance_schema_version'] ?? null;
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => ['appearance' => ['appearance_schema_version' => '1', 'theme_preset_legacy' => 'x', 'suggested_master_upgrade' => 'y']],
                'fields' => ['appearance' => ['appearance_schema_version', 'theme_preset_legacy', 'suggested_master_upgrade']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appearance.appearance_schema_version', 'appearance.theme_preset_legacy', 'appearance.suggested_master_upgrade']);
        $this->assertSame($version, Setting::getGroup('appearance')['appearance_schema_version'] ?? null);
    }

    public function test_theme_and_look_settings_are_written_by_the_appearance_applier(): void
    {
        if (! class_exists(AppearanceApplier::class) || ! method_exists(AppearanceSettings::class, 'lookKeys')) {
            $this->markTestSkipped('AppearanceApplier (interface I5) arrives with the theme catalogue v2 (WP2).');
        }

        // The applier's colour rule: a colour equal to the active colour style's is not stored (the style supplies
        // it). A direct write would store it.
        $styleColour = AppearanceSettings::withThemeColours(Setting::getGroup('appearance'))['primary_color'];
        DB::table('settings')->where('key', 'primary_color')->delete();
        Setting::clearCache();

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', [
                'groups' => ['appearance' => ['primary_color' => $styleColour, 'footer_text' => 'Plain footer'], 'general' => ['site_name' => 'Look Site']],
                'fields' => ['appearance' => ['primary_color', 'footer_text'], 'general' => ['site_name']],
            ])
            ->assertOk()
            ->assertJsonPath('saved.appearance', ['primary_color', 'footer_text']);

        $appearance = Setting::getGroup('appearance');
        $this->assertArrayNotHasKey('primary_color', $appearance);
        $this->assertSame('Plain footer', $appearance['footer_text'] ?? null);
        $this->assertSame('Look Site', Setting::get('site_name'));
    }

    public function test_a_save_is_kept_as_one_revision_without_passwords(): void
    {
        if (! class_exists(SettingsRevision::class) || ! Schema::hasTable('admin_setting_revisions')) {
            $this->markTestSkipped('Settings revisions (interface I6) arrive with the theme catalogue v2 (WP2).');
        }

        Setting::set('mail_from_name', 'Old sender', 'email');
        Setting::set('smtp_password', 'Old-Pass-1f', 'email');
        $admin = $this->admin();

        $id = $this->actingAs($admin)
            ->putJson('/admin/settings/save', [
                'groups' => ['general' => ['site_name' => 'Revision Site'], 'email' => ['mail_from_name' => 'New sender', 'smtp_password' => 'New-Pass-2e']],
                'fields' => ['general' => ['site_name'], 'email' => ['mail_from_name', 'smtp_password']],
            ])
            ->assertOk()
            ->json('revision');

        $this->assertIsInt($id);
        $row = DB::table('admin_setting_revisions')->where('id', $id)->first();
        $this->assertSame('settings_save', $row->kind);
        $this->assertSame('general,email', $row->group);
        $this->assertSame($admin->getKey(), (int) $row->user_id);

        $before = json_decode($row->before, true);
        $after = json_decode($row->after, true);
        $this->assertSame('Old sender', $before['email']['mail_from_name']);
        $this->assertSame('New sender', $after['email']['mail_from_name']);
        $this->assertSame('Revision Site', $after['general']['site_name']);
        $this->assertSame(SettingsRevision::MASK, $after['email']['smtp_password']);
        $this->assertStringNotContainsString('Old-Pass-1f', $row->before.$row->after);
        $this->assertStringNotContainsString('New-Pass-2e', $row->before.$row->after);
    }

    public function test_a_save_that_changes_nothing_answers_with_empty_objects(): void
    {
        Setting::set('smtp_password', 'Kept-Pass-9', 'email');

        $response = $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', ['groups' => ['email' => ['smtp_password' => '']], 'fields' => ['email' => ['smtp_password']]])
            ->assertOk()
            ->assertJsonPath('message', 'Nothing to save.');

        $this->assertStringContainsString('"saved":{},"values":{}', $response->getContent());
        $this->assertSame('Kept-Pass-9', Setting::get('smtp_password'));
    }

    public function test_a_plain_form_post_redirects_back_with_a_message_or_errors_keyed_by_setting(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/settings/site')
            ->put('/admin/settings/save', [
                'groups' => ['general' => ['site_name' => 'Form Site']],
                'fields' => ['general' => ['site_name', 'registration_enabled']],
            ])
            ->assertRedirect('/admin/settings/site')
            ->assertSessionHas('success');

        $this->assertSame('Form Site', Setting::get('site_name'));
        $this->assertSame('0', Setting::get('registration_enabled'));

        $this->actingAs($this->admin())
            ->from('/admin/settings/site')
            ->put('/admin/settings/save', [
                'groups' => ['general' => ['site_name' => '']],
                'fields' => ['general' => ['site_name']],
            ])
            ->assertRedirect('/admin/settings/site')
            ->assertSessionHasErrors(['general.site_name']);

        $this->assertSame('Form Site', Setting::get('site_name'));
    }

    public function test_the_sharing_picture_is_uploaded_then_saved_with_the_page(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        Setting::set('og_image', 'https://example.com/old.jpg', 'seo');

        $value = $this->actingAs($admin)
            ->postJson('/admin/settings/upload', ['key' => 'og_image', 'file' => $this->fakeImageUpload('share.jpg', 1200, 630)])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->json('value');

        $this->assertMatchesRegularExpression('#^storage/settings/[A-Za-z0-9]+\.jpe?g$#', $value);
        Storage::disk('public')->assertExists(substr($value, strlen('storage/')));
        // Choosing a picture saves nothing: the page saves the address with everything else.
        $this->assertSame('https://example.com/old.jpg', Setting::get('og_image'));

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', ['groups' => ['seo' => ['og_image' => $value]], 'fields' => ['seo' => ['og_image']]])
            ->assertOk();
        $this->assertSame($value, Setting::get('og_image'));

        $this->actingAs($admin)
            ->postJson('/admin/settings/upload', ['key' => 'og_image', 'file' => $this->fakeUpload('notes.txt', 1, 'text/plain')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
        $this->actingAs($admin)
            ->postJson('/admin/settings/upload', ['key' => 'site_name', 'file' => $this->fakeImageUpload('logo.jpg', 200, 200)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
        $this->actingAs(User::factory()->create())
            ->postJson('/admin/settings/upload', ['key' => 'og_image', 'file' => $this->fakeImageUpload('share.jpg', 1200, 630)])
            ->assertNotFound();
        $this->assertCount(1, Storage::disk('public')->allFiles('settings'));
    }

    public function test_only_admins_reach_the_save_endpoint(): void
    {
        $payload = ['groups' => ['general' => ['site_name' => 'Hacked']], 'fields' => ['general' => ['site_name']]];
        $siteName = Setting::get('site_name');

        $this->putJson('/admin/settings/save', $payload)->assertNotFound();
        $this->actingAs(User::factory()->create())->putJson('/admin/settings/save', $payload)->assertNotFound();
        $this->assertSame($siteName, Setting::get('site_name'));
    }

    public function test_the_legacy_group_endpoint_still_writes_the_whole_group(): void
    {
        Setting::set('maintenance_message', 'Kept by the new save', 'maintenance');

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', ['groups' => ['maintenance' => ['maintenance_retry_after' => '60']], 'fields' => ['maintenance' => ['maintenance_retry_after']]])
            ->assertOk();
        $this->assertSame('Kept by the new save', Setting::get('maintenance_message'));

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/update/maintenance', ['maintenance_retry_after' => '60'])
            ->assertOk()
            ->assertJsonPath('message', 'Maintenance settings saved in real-time.');
        $this->assertSame('', Setting::get('maintenance_message'));
    }

    public function test_the_theme_reset_hands_over_to_the_appearance_applier(): void
    {
        $applier = new class {
            public int $calls = 0;

            public function resetToFactory(): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance('App\Support\AppearanceApplier', $applier);
        Setting::set('logo_url', '/images/my-logo.png', 'appearance');
        $before = $this->rows();

        $this->actingAs($this->admin())
            ->postJson('/admin/settings/reset-theme')
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Only the applier writes look keys now (and it keeps the logo, texts, menus, ads and custom code).
        $this->assertSame(1, $applier->calls);
        $this->assertSame($before, $this->rows());
    }

    public function test_expert_settings_are_saved_in_developer_mode_only(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = $this->admin();
        $before = $this->rows();
        $payload = [
            'groups' => [
                'general' => ['site_name' => 'Not saved either'],
                'api' => ['cors_max_age' => '600'],
                'seo' => ['robots_txt' => "User-agent: *\nDisallow: /"],
                'appearance' => ['custom_js' => 'alert(1)', 'custom_head_html' => '<meta name="x" content="y">'],
            ],
            'fields' => [
                'general' => ['site_name'],
                'api' => ['cors_max_age'],
                'seo' => ['robots_txt'],
                'appearance' => ['custom_js', 'custom_head_html'],
            ],
        ];

        // Developer mode off: the pages hide these fields, and the server refuses them (nothing is written).
        $this->actingAs($admin)
            ->putJson('/admin/settings/save', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors', [
                'api.cors_max_age' => ['This setting cannot be saved here.'],
                'seo.robots_txt' => ['This setting cannot be saved here.'],
                'appearance.custom_js' => ['This setting cannot be saved here.'],
                'appearance.custom_head_html' => ['This setting cannot be saved here.'],
            ]);
        $this->assertSame($before, $this->rows());

        // A hidden setting is never saved by the pages, and an expert password cannot be removed either.
        $this->actingAs($admin)
            ->putJson('/admin/settings/save', ['groups' => ['appearance' => ['font_family' => 'Inter']], 'fields' => ['appearance' => ['font_family']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['appearance.font_family']);
        $this->actingAs($admin)
            ->putJson('/admin/settings/save', ['fields' => ['uploads' => ['upload18_api_key']], 'clear' => ['uploads' => ['upload18_api_key']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['uploads.upload18_api_key']);
        $this->assertSame($before, $this->rows());

        // Developer mode on: saved.
        AdminPreferences::set($admin, 'developer_mode', true);

        $this->actingAs($admin)
            ->putJson('/admin/settings/save', $payload)
            ->assertOk()
            ->assertJsonPath('saved.api', ['cors_max_age'])
            ->assertJsonPath('saved.seo', ['robots_txt']);
        $this->assertSame('600', Setting::get('cors_max_age'));
        $this->assertSame('alert(1)', Setting::get('custom_js'));
        $this->assertSame('Not saved either', Setting::get('site_name'));
    }

    public function test_the_old_admin_and_an_unfinished_update_keep_saving_expert_settings(): void
    {
        // The old admin (v1, the test default) shows every setting, as before.
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', ['groups' => ['api' => ['cors_max_age' => '700']], 'fields' => ['api' => ['cors_max_age']]])
            ->assertOk();
        $this->assertSame('700', Setting::get('cors_max_age'));

        // Uploaded over an older version: Developer mode cannot be switched on before "Finish update", so the
        // server does not refuse what it cannot let the admin unlock.
        config(['admin.ui' => 'v2']);
        Schema::table('users', fn ($table) => $table->dropColumn('admin_preferences'));

        $this->actingAs($this->admin())
            ->putJson('/admin/settings/save', ['groups' => ['api' => ['cors_max_age' => '800']], 'fields' => ['api' => ['cors_max_age']]])
            ->assertOk();
        $this->assertSame('800', Setting::get('cors_max_age'));
    }

    public function test_a_refused_form_post_never_puts_passwords_or_keys_into_the_session(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/settings/email')
            ->put('/admin/settings/save', [
                'groups' => [
                    'email' => ['smtp_host' => 'mail.example.com', 'smtp_password' => 'Typed-Smtp-Secret-1', 'mail_from_address' => 'not an email'],
                    'security' => ['captcha_secret_key' => 'Typed-Captcha-Secret-2'],
                    'maintenance' => ['maintenance_secret' => 'typed-bypass-word-3'],
                ],
                'fields' => ['email' => ['smtp_host', 'smtp_password', 'mail_from_address'], 'security' => ['captcha_secret_key'], 'maintenance' => ['maintenance_secret']],
            ])
            ->assertRedirect('/admin/settings/email')
            ->assertSessionHasErrors(['email.mail_from_address']);

        // The other typed values come back into the form; passwords and keys are not kept anywhere.
        $this->assertSame('mail.example.com', session()->getOldInput('groups.email.smtp_host'));
        $this->assertSame('not an email', session()->getOldInput('groups.email.mail_from_address'));
        $session = serialize(session()->all());

        foreach (['Typed-Smtp-Secret-1', 'Typed-Captcha-Secret-2', 'typed-bypass-word-3'] as $secret) {
            $this->assertStringNotContainsString($secret, $session);
        }

        $this->assertNotSame('Typed-Smtp-Secret-1', Setting::get('smtp_password'));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function rows(): array
    {
        return DB::table('settings')->orderBy('key')->get(['key', 'value', 'group'])
            ->mapWithKeys(fn (object $row): array => [$row->key => [(string) $row->value, (string) $row->group]])
            ->all();
    }
}
