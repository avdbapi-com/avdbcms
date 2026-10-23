<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppearanceApplier;
use App\Support\AppearanceSettings;
use App\Support\MasterExperienceCatalog;
use App\Support\SettingsRevision;
use App\Support\ThemeCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

class AdminAppearanceExperienceSettingsTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware();
        $this->refreshAdminSettingsSchema();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admin_setting_revisions');
        $this->dropAdminSettingsSchema();
        Cache::flush();

        parent::tearDown();
    }

    public function test_appearance_settings_form_renders_every_experience_control(): void
    {
        $this->withoutVite();
        Setting::set('video_card_title_lines', '4', 'appearance');

        $response = $this->actingAs($this->userWithAdminFlag(true))
            ->get(route('admin.settings.index', ['tab' => 'appearance']))
            ->assertOk()
            ->assertSee('Homepage controls')
            ->assertSee('Homepage video feed')
            ->assertSee('Header and sidebar controls')
            ->assertSee('Watch page controls')
            ->assertSee('Accent Color')
            ->assertSee('Brand assets')
            ->assertSee('Logo Path or URL')
            ->assertSee('Favicon Path or URL')
            ->assertSee('Footer and announcements')
            ->assertSee('Typography controls')
            ->assertSee('Font Family')
            ->assertSee('Base Font Size')
            ->assertSee('Text Contrast')
            ->assertSee('Headline Gradient Text')
            ->assertSee('Player Ambient Glow')
            ->assertSee('Action Panel Surface')
            ->assertSee('Player Frame Style')
            ->assertSee('Up Next Card')
            ->assertSee('Description Starts Open')
            ->assertSee('Sidebar Density')
            ->assertSee('Secondary page controls')
            ->assertSee('Secondary Page Density')
            ->assertSee('Secondary Page Width')
            ->assertSee('Contact Illustration')
            ->assertSee('Premium Comparison')
            ->assertSee('CSS and JavaScript effects')
            ->assertSee('Global affordances')
            ->assertSee('4 Lines (Maximum)')
            ->assertSee('theme-card-actions', false)
            ->assertSee('Theme JSON must be an object.', false)
            ->assertSee('saveTrigger.dispatchEvent', false)
            ->assertSee('@media (prefers-reduced-motion: reduce)', false)
            ->assertSee('min-width: 0 !important', false);

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $selectedTitleLines = (new \DOMXPath($document))
            ->query('//select[@name="video_card_title_lines"]/option[@selected]');
        $this->assertCount(1, $selectedTitleLines);
        $this->assertSame('4', $selectedTitleLines->item(0)->getAttribute('value'));

        // the v2 keys are edited on the new Appearance pages, not on the v1 form
        $v2Keys = [...AppearanceSettings::v2TextKeys(), ...AppearanceSettings::v2BooleanKeys()];

        foreach (array_diff(array_keys($this->appearanceSettingsSchema()), $v2Keys) as $key) {
            $response->assertSee('name="' . $key . '"', false);
        }

        $response
            ->assertDontSee('name="home_upload_cta_enabled"', false)
            ->assertDontSee('Upload CTA')
            ->assertDontSee('name="sidebar_upload_button_enabled"', false)
            ->assertDontSee('Sidebar Upload')
            ->assertDontSee('name="sidebar_autoplay_switch_enabled"', false)
            ->assertDontSee('name="watch_autoplay_toggle_enabled"', false)
            ->assertDontSee('name="js_player_memory_enabled"', false)
            ->assertDontSee('Autoplay Switch')
            ->assertDontSee('Autoplay Toggle')
            ->assertDontSee('Player Memory');
    }

    public function test_rendered_settings_labels_reference_their_form_controls(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->userWithAdminFlag(true))
            ->get(route('admin.settings.index', ['tab' => 'appearance']))
            ->assertOk();

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $labels = $xpath->query('//label[contains(concat(" ", normalize-space(@class), " "), " form-label ")]');
        $this->assertGreaterThan(200, $labels->length);

        $seenIds = [];
        $controlsById = [];
        foreach ($xpath->query('//input[@id] | //select[@id] | //textarea[@id]') as $control) {
            $id = $control->getAttribute('id');
            $this->assertNotSame('', $id);
            $this->assertArrayNotHasKey($id, $seenIds, "Duplicate settings control id: {$id}");
            $seenIds[$id] = true;
            $controlsById[$id] = $control;
        }

        foreach ($labels as $label) {
            $for = $label->getAttribute('for');
            $this->assertNotSame('', $for, 'Every rendered settings form label must have a for attribute.');
            $this->assertArrayHasKey($for, $controlsById, "Label target does not exist: {$for}");
        }
    }

    public function test_admin_can_update_experience_controls_and_clear_cache(): void
    {
        $schema = $this->appearanceSettingsSchema();

        foreach (array_keys($schema) as $key) {
            Cache::forever('setting.' . $key, 'stale');
        }
        Cache::forever('settings.appearance', ['stale' => true]);
        Cache::forever('settings.__all_grouped', ['appearance' => ['stale' => true]]);

        $payload = $this->validAppearancePayload([
            'accent_color' => '#12abcd',
            'home_intro_title' => 'Admin controlled home title',
            'home_widget_motion_duration_ms' => '880',
            'home_widget_motion_distance_px' => '48',
            'home_widget_motion_stagger_ms' => '90',
            'home_widget_motion_speed_px_per_second' => '56',
            'home_widget_motion_style' => 'zoom',
            'home_widget_smart_discovery_motion_direction' => 'left',
            'home_feed_source' => 'most_viewed',
            'home_feed_category_id' => '123',
            'home_feed_columns' => '5',
            'home_feed_load_mode' => 'load_more',
            'font_family' => 'serif',
            'base_font_size' => '18',
            'ui_font_scale' => '106',
            'heading_font_scale' => '114',
            'card_title_font_scale' => '108',
            'text_contrast' => 'strong',
            'mobile_video_columns' => '1',
            'mobile_video_card_density' => 'comfortable',
            'mobile_bottom_nav_enabled' => '0',
            'hero_layout_mode' => 'bento_spotlight',
            'back_to_top_style' => 'round_icon',
            'video_card_title_lines' => '4',
            'watch_player_frame_style' => 'minimal',
            'watch_sidebar_density' => 'relaxed',
            'secondary_page_density' => 'compact',
            'secondary_page_max_width' => 'full',
            'watch_related_videos_enabled' => '1',
            'video_card_hover_enabled' => '1',
        ]);

        unset(
            $payload['home_intro_enabled'],
            $payload['watch_share_enabled'],
            $payload['watch_player_ambient_glow_enabled'],
            $payload['watch_action_panel_enabled'],
            $payload['watch_up_next_card_enabled'],
            $payload['watch_description_default_open'],
            $payload['secondary_page_hero_enabled'],
            $payload['secondary_page_surfaces_enabled'],
            $payload['contact_illustration_enabled'],
            $payload['premium_comparison_enabled'],
            $payload['home_widget_motion_card_cascade_enabled'],
            $payload['home_widget_motion_pause_on_hover_enabled']
        );

        $this->actingAs($this->userWithAdminFlag(true))
            ->put(route('admin.settings.update.group', 'appearance'), $payload)
            ->assertRedirect(route('admin.settings.index', ['tab' => 'appearance']));

        $this->assertDatabaseHas('settings', [
            'key' => 'accent_color',
            'value' => '#12abcd',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_intro_title',
            'value' => 'Admin controlled home title',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_intro_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_share_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_widget_motion_style',
            'value' => 'zoom',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_widget_motion_duration_ms',
            'value' => '880',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_widget_motion_speed_px_per_second',
            'value' => '56',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_widget_motion_card_cascade_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_widget_motion_autorun_enabled',
            'value' => '1',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_widget_motion_pause_on_hover_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_feed_source',
            'value' => 'most_viewed',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_feed_category_id',
            'value' => '123',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_feed_columns',
            'value' => '5',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_feed_load_mode',
            'value' => 'load_more',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'font_family',
            'value' => 'serif',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'base_font_size',
            'value' => '18',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'ui_font_scale',
            'value' => '106',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'heading_font_scale',
            'value' => '114',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'card_title_font_scale',
            'value' => '108',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'mobile_video_columns',
            'value' => '1',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'mobile_video_card_density',
            'value' => 'comfortable',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'mobile_bottom_nav_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'mobile_bottom_dock_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'hero_layout_mode',
            'value' => 'bento_spotlight',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'back_to_top_style',
            'value' => 'round_icon',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'video_card_title_lines',
            'value' => '4',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'text_contrast',
            'value' => 'strong',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_player_frame_style',
            'value' => 'minimal',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_sidebar_density',
            'value' => 'relaxed',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_player_ambient_glow_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_action_panel_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_up_next_card_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'watch_description_default_open',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'secondary_page_density',
            'value' => 'compact',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'secondary_page_max_width',
            'value' => 'full',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'secondary_page_hero_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'secondary_page_surfaces_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'contact_illustration_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'premium_comparison_enabled',
            'value' => '0',
            'group' => 'appearance',
        ]);

        $this->assertFalse(Cache::has('settings.appearance'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));

        foreach (array_keys($schema) as $key) {
            $this->assertFalse(Cache::has('setting.' . $key), 'Per-key cache was not cleared for ' . $key);
        }
    }

    public function test_appearance_schema_contains_all_bool_defaults(): void
    {
        $schema = $this->appearanceSettingsSchema();
        $defaults = AppearanceSettings::defaults();

        foreach (AppearanceSettings::booleanKeys() as $key) {
            $this->assertArrayHasKey($key, $schema);
            $this->assertSame('bool', $schema[$key]['type'] ?? null);
            $this->assertArrayHasKey($key, $defaults, 'Missing appearance default for ' . $key);
            $this->assertContains((string) $defaults[$key], ['0', '1'], 'Invalid appearance boolean default for ' . $key);
        }

        // The v2 keys (theme_flavor, sidebar_style, ...) are written by AppearanceApplier and the new save endpoint.
        foreach (array_diff(AppearanceSettings::textKeys(), AppearanceSettings::v2TextKeys()) as $key) {
            $this->assertArrayHasKey($key, $schema);
        }

        foreach ([...AppearanceSettings::v2TextKeys(), ...AppearanceSettings::v2BooleanKeys()] as $key) {
            $this->assertArrayHasKey($key, AppearanceSettings::defaults());
        }

        $this->assertArrayNotHasKey('sidebar_autoplay_switch_enabled', $schema);
        $this->assertArrayNotHasKey('watch_autoplay_toggle_enabled', $schema);
        $this->assertArrayNotHasKey('home_upload_cta_enabled', $schema);
        $this->assertArrayNotHasKey('js_player_memory_enabled', $schema);
        $this->assertArrayNotHasKey('sidebar_upload_button_enabled', $schema);
    }

    public function test_theme_catalog_contains_complete_mobile_and_structural_tokens(): void
    {
        $heroLayouts = array_keys(AppearanceSettings::heroLayoutModes());
        $backToTopStyles = array_keys(AppearanceSettings::backToTopStyles());
        $mobileDensities = array_keys(AppearanceSettings::mobileVideoCardDensities());

        foreach (AppearanceSettings::themePresets() as $presetKey => $preset) {
            $this->assertArrayHasKey('hero_layout_mode', $preset, $presetKey);
            $this->assertContains($preset['hero_layout_mode'], $heroLayouts, $presetKey);
            $this->assertArrayHasKey('back_to_top_style', $preset, $presetKey);
            $this->assertContains($preset['back_to_top_style'], $backToTopStyles, $presetKey);
            $this->assertContains((string) $preset['mobile_video_columns'], ['1', '2'], $presetKey);
            $this->assertContains($preset['mobile_video_card_density'], $mobileDensities, $presetKey);
            $this->assertContains((string) $preset['video_card_title_lines'], ['1', '2', '3', '4'], $presetKey);
        }
    }

    public function test_saved_custom_appearance_values_are_not_replaced_without_explicit_preview(): void
    {
        $settings = AppearanceSettings::defaults();
        $settings['theme_preset'] = 'cyberpunk_neon';
        $settings['primary_color'] = '#123456';
        $settings['hero_layout_mode'] = 'off';
        $settings['mobile_video_columns'] = '1';

        $resolved = AppearanceSettings::resolveActiveDesign($settings);

        $this->assertSame('#123456', $resolved['primary_color']);
        $this->assertSame('off', $resolved['hero_layout_mode']);
        $this->assertSame('1', $resolved['mobile_video_columns']);
        $this->assertArrayNotHasKey('_theme_preview', $resolved);
    }

    public function test_explicit_theme_preview_applies_complete_structural_values_and_beats_daily_design(): void
    {
        $request = Request::create('/?theme_preset=cyber_matrix');
        $this->app->instance('request', $request);

        $settings = AppearanceSettings::defaults();
        $settings['daily_design_auto_enabled'] = '1';
        $settings['primary_color'] = '#123456';

        $resolved = AppearanceSettings::resolveActiveDesign($settings);
        $preset = AppearanceSettings::themePresets()['cyber_matrix'];

        $this->assertTrue($resolved['_theme_preview'] ?? false);
        $this->assertSame($preset['primary'], $resolved['primary_color']);
        $this->assertSame($preset['hero_layout_mode'], $resolved['hero_layout_mode']);
        $this->assertSame($preset['back_to_top_style'], $resolved['back_to_top_style']);
        $this->assertSame($preset['mobile_video_columns'], $resolved['mobile_video_columns']);
        $this->assertSame($preset['mobile_video_card_density'], $resolved['mobile_video_card_density']);
        $this->assertFalse(AppearanceSettings::isDailyDesignActive($resolved));
    }

    public function test_preset_values_map_catalog_color_aliases_to_saved_setting_keys(): void
    {
        $raw = AppearanceSettings::themePresets()['cyber_matrix'];
        $values = AppearanceSettings::presetValues('cyber_matrix');

        $this->assertSame($raw['primary'], $values['primary_color']);
        $this->assertSame($raw['secondary'], $values['brand_secondary_color']);
        $this->assertSame($raw['accent'], $values['accent_color']);
        $this->assertArrayNotHasKey('primary', $values);
        $this->assertArrayNotHasKey('secondary', $values);
        $this->assertArrayNotHasKey('accent', $values);
    }

    public function test_admin_apply_theme_persists_complete_structural_and_mobile_tokens(): void
    {
        $presetKey = 'cyber_matrix';
        $preset = AppearanceSettings::themePresets()[$presetKey];

        $this->actingAs($this->userWithAdminFlag(true))
            ->postJson(route('admin.settings.apply-theme'), ['theme_preset' => $presetKey])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preset_key', $presetKey);

        foreach ([
            'theme_preset' => $presetKey,
            'primary_color' => $preset['primary'],
            'dark_bg_color' => $preset['dark_bg_color'],
            'font_family' => $preset['font_family'],
            'home_layout_mode' => $preset['home_layout_mode'],
            'hero_layout_mode' => $preset['hero_layout_mode'],
            'back_to_top_style' => $preset['back_to_top_style'],
            'mobile_video_columns' => $preset['mobile_video_columns'],
            'mobile_video_card_density' => $preset['mobile_video_card_density'],
            'mobile_bottom_nav_enabled' => $preset['mobile_bottom_nav_enabled'],
            'mobile_bottom_dock_enabled' => $preset['mobile_bottom_dock_enabled'],
            'video_card_title_lines' => $preset['video_card_title_lines'],
        ] as $key => $value) {
            $this->assertDatabaseHas('settings', [
                'key' => $key,
                'value' => (string) $value,
                'group' => 'appearance',
            ]);
        }
    }

    public function test_admin_apply_master_experience_applies_theme_then_experience_layout_overrides(): void
    {
        $experience = MasterExperienceCatalog::find('netflix_cinema');
        $themePresetKey = $this->experienceValue($experience, 'theme_preset');
        $themePreset = AppearanceSettings::themePresets()[$themePresetKey];

        $this->actingAs($this->userWithAdminFlag(true))
            ->postJson(route('admin.settings.apply-experience'), ['experience_key' => 'netflix_cinema'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('experience_key', 'netflix_cinema');

        $this->assertDatabaseHas('settings', ['key' => 'active_master_experience', 'value' => 'netflix_cinema', 'group' => 'appearance']);
        $this->assertDatabaseHas('settings', ['key' => 'theme_preset', 'value' => $themePresetKey, 'group' => 'appearance']);
        $this->assertDatabaseHas('settings', ['key' => 'hero_layout_mode', 'value' => $this->experienceValue($experience, 'hero_layout_mode', $themePreset['hero_layout_mode']), 'group' => 'appearance']);
        $this->assertDatabaseHas('settings', ['key' => 'back_to_top_style', 'value' => $themePreset['back_to_top_style'], 'group' => 'appearance']);

        foreach (['home_layout_mode', 'watch_layout_mode', 'video_card_layout_style', 'modal_presentation_style'] as $key) {
            $this->assertDatabaseHas('settings', ['key' => $key, 'value' => $this->experienceValue($experience, $key), 'group' => 'appearance']);
        }

        $this->assertSame(
            ThemeCatalog::resolve($themePresetKey)['theme'],
            AppearanceSettings::resolveThemeFlavor(Setting::getGroup('appearance'))['theme'],
        );
    }

    public function test_admin_apply_all_master_experiences_and_factory_reset_keep_catalog_tokens_aligned(): void
    {
        $admin = $this->userWithAdminFlag(true);
        $this->actingAs($admin);
        Setting::set('logo_url', '/images/brand.svg', 'appearance');
        Setting::set('custom_css', '.brand { color: red; }', 'appearance');

        foreach (MasterExperienceCatalog::all() as $experienceKey => $experience) {
            $preset = AppearanceSettings::themePresets()[$this->experienceValue($experience, 'theme_preset')];

            $this->postJson(route('admin.settings.apply-experience'), ['experience_key' => $experienceKey])
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('experience_key', $experienceKey);

            foreach ([
                'active_master_experience' => $experienceKey,
                'theme_preset' => $this->experienceValue($experience, 'theme_preset'),
                'home_layout_mode' => $this->experienceValue($experience, 'home_layout_mode'),
                'watch_layout_mode' => $this->experienceValue($experience, 'watch_layout_mode'),
                'video_card_layout_style' => $this->experienceValue($experience, 'video_card_layout_style'),
                'modal_presentation_style' => $this->experienceValue($experience, 'modal_presentation_style'),
                'mobile_video_columns' => $this->experienceValue($experience, 'mobile_video_columns', $preset['mobile_video_columns']),
                'mobile_video_card_density' => $preset['mobile_video_card_density'],
                'mobile_bottom_nav_enabled' => $preset['mobile_bottom_nav_enabled'],
                'mobile_bottom_dock_enabled' => $preset['mobile_bottom_dock_enabled'],
                'video_card_title_lines' => $this->experienceValue($experience, 'video_card_title_lines', $preset['video_card_title_lines']),
            ] as $key => $value) {
                $this->assertDatabaseHas('settings', [
                    'key' => $key,
                    'value' => (string) $value,
                    'group' => 'appearance',
                ]);
            }
        }

        $this->postJson(route('admin.settings.reset-theme'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        // The factory look is YouTube Spotlight Pro on Midnight Spotlight (demo1); logo and custom code stay.
        $appearance = Setting::getGroup('appearance');
        $this->assertSame('youtube_spotlight_pro', $appearance['active_master_experience']);
        $this->assertSame(['tokyo_neon', 'signature'], [$appearance['theme_preset'], $appearance['theme_flavor']]);
        $this->assertSame('youtube_spotlight', $appearance['home_layout_mode']);
        $this->assertSame('/images/brand.svg', $appearance['logo_url']);
        $this->assertSame('.brand { color: red; }', $appearance['custom_css']);

        foreach (ThemeCatalog::COLOUR_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $appearance, "{$key} comes from the colour style after a reset");
        }
    }

    public function test_appearance_applier_writes_only_look_keys_and_records_revisions(): void
    {
        (require database_path('migrations/2026_10_20_000001_create_admin_setting_revisions_table.php'))->up();
        $admin = $this->userWithAdminFlag(true);
        $this->actingAs($admin);

        $kept = [
            'logo_url' => '/images/brand.svg',
            'favicon_url' => '/favicon.ico',
            'custom_css' => '.x { color: red; }',
            'custom_js' => 'console.log(1);',
            'custom_head_html' => '<meta name="x" content="y">',
            'footer_text' => 'My footer',
            'home_intro_title' => 'My intro',
            'header_premium_label' => 'VIP',
            'sidebar_primary_links' => "Home|house|/\nMine|star|/mine",
            'announcement_banner' => 'Hello',
            'effects_enabled' => '0',
        ];

        foreach ($kept as $key => $value) {
            Setting::set($key, $value, 'appearance');
        }

        Setting::set('ad_preroll_enabled', '1', 'monetization');
        Setting::set('primary_color', '#123456', 'appearance');
        $before = SettingsRevision::rows('appearance');
        $others = DB::table('settings')->where('group', '!=', 'appearance')->orderBy('key')->get(['key', 'value', 'group'])->all();

        $result = AppearanceApplier::applyTheme('broadsheet', 'navy');
        $this->assertNotNull($result['revision']);
        $this->assertSame([], array_values(array_diff($result['changed'], AppearanceSettings::lookKeys())), 'only look keys change');
        $this->assertContains('primary_color', $result['changed'], 'the custom colour is replaced by the chosen colour style');
        $appearance = Setting::getGroup('appearance');
        $this->assertSame(['broadsheet', 'navy', ''], [$appearance['theme_preset'], $appearance['theme_flavor'], $appearance['active_master_experience']]);
        $this->assertSame(ThemeCatalog::recommendedSettings('broadsheet', 'navy')['home_layout_mode'], $appearance['home_layout_mode']);
        $this->assertArrayNotHasKey('primary_color', $appearance);

        foreach ($kept as $key => $value) {
            $this->assertSame($value, $appearance[$key], "{$key} is not a look key");
        }

        $revision = SettingsRevision::find($result['revision']);
        $this->assertSame(['apply_theme', 'appearance', $admin->id], [$revision['kind'], $revision['group'], $revision['user_id']]);
        $this->assertSame($before, $revision['before']);

        Setting::set('home_layout_mode', 'tiktok_shorts', 'appearance');
        $result = AppearanceApplier::applyTheme('laser_grid', null, true);
        $appearance = Setting::getGroup('appearance');
        $this->assertSame(['tokyo_neon', 'signature', 'tiktok_shorts'], [$appearance['theme_preset'], $appearance['theme_flavor'], $appearance['home_layout_mode']], 'an old id applies its theme; keepLayout keeps the layout');
        $this->assertSame([], array_values(array_diff($result['changed'], AppearanceSettings::lookKeys())));

        $result = AppearanceApplier::applyMaster('youtube_2026_pro');
        $this->assertSame('youtube_spotlight_pro', Setting::getGroup('appearance')['active_master_experience']);
        $this->assertSame([], array_values(array_diff($result['changed'], AppearanceSettings::lookKeys())));
        $this->assertSame('apply_master', SettingsRevision::find($result['revision'])['kind']);

        $result = AppearanceApplier::resetToFactory();
        $appearance = Setting::getGroup('appearance');
        $this->assertSame(['tokyo_neon', 'signature', 'youtube_spotlight_pro', 'youtube_spotlight'], [
            $appearance['theme_preset'], $appearance['theme_flavor'], $appearance['active_master_experience'], $appearance['home_layout_mode'],
        ]);
        $this->assertSame(['appearance_schema_version'], array_values(array_diff($result['changed'], AppearanceSettings::lookKeys())), 'a reset also moves unstored keys to the v2 defaults');
        $this->assertSame(AppearanceSettings::SCHEMA_VERSION, $appearance['appearance_schema_version']);
        $this->assertSame([], AppearanceApplier::resetToFactory()['changed'], 'a second reset changes nothing');

        foreach ($kept as $key => $value) {
            $this->assertSame($value, $appearance[$key], "{$key} survives the factory reset");
        }

        $published = AppearanceApplier::applyValues(['sidebar_style' => 'floating', 'not_a_key' => 'x', 'brand_contrast_autofix' => false], 'publish');
        $this->assertSame(['brand_contrast_autofix', 'sidebar_style'], $published['changed']);

        $first = SettingsRevision::latest(20, 'appearance');
        $this->assertSame(['publish', 'reset', 'apply_master', 'apply_theme', 'apply_theme'], array_column($first, 'kind'));
        $restore = AppearanceApplier::restoreRevision(end($first)['id']);
        $this->assertNotNull($restore);
        $this->assertSame($before, SettingsRevision::rows('appearance'), 'undo of the first apply restores the rows exactly');
        $this->assertEquals($others, DB::table('settings')->where('group', '!=', 'appearance')->orderBy('key')->get(['key', 'value', 'group'])->all());
        $this->assertSame('restore', SettingsRevision::latest(1)[0]['kind']);
    }

    public function test_v1_settings_form_shows_what_the_site_renders_and_a_plain_save_keeps_the_look(): void
    {
        $this->withoutVite();
        $admin = $this->userWithAdminFlag(true);
        $colours = fn (array $form): array => array_combine(ThemeCatalog::COLOUR_KEYS, array_map(fn (string $key) => $form[$key] ?? null, ThemeCatalog::COLOUR_KEYS));

        // Before "Finish update": keys the site never stored show the v1 defaults it renders, absent colours the
        // old id's colour style.
        Setting::set('footer_text', 'Kept', 'appearance');
        $form = $this->appearanceForm($admin);
        $alias = ThemeCatalog::resolve('cyberpunk_neon');
        $this->assertSame(
            ['cyberpunk_neon', 'grid_modern', 'snap_slider_carousel', 'modal_glassmorphic_center', 'ambient', 'subtle'],
            [$form['theme_preset'], $form['home_layout_mode'], $form['hero_layout_mode'], $form['modal_presentation_style'], $form['watch_ambient_glow_intensity'], $form['scrollbar_style']],
        );
        $this->assertSame(ThemeCatalog::flavorColours($alias['theme'], $alias['flavor']), $colours($form));

        // After the upgrade the colour rows are gone (the colour style supplies them): the form shows the style's.
        foreach (['appearance_schema_version' => '2', 'theme_preset' => 'tokyo_neon', 'theme_flavor' => 'signature'] as $key => $value) {
            Setting::set($key, $value, 'appearance');
        }
        $form = $this->appearanceForm($admin);
        $this->assertSame(ThemeCatalog::flavorColours('tokyo_neon', 'signature'), $colours($form));
        $this->assertSame(['tokyo_neon', 'youtube_spotlight', 'modal_slide_sheet'], [$form['theme_preset'], $form['home_layout_mode'], $form['modal_presentation_style']]);

        AppearanceApplier::applyTheme('obsidian_gold');
        $form = $this->appearanceForm($admin);
        $this->assertSame(ThemeCatalog::flavorColours('obsidian_gold', 'signature'), $colours($form));

        // A plain Save posts every field as the page does: nothing becomes a custom colour and the look stays.
        $before = AppearanceSettings::resolveActiveDesign(Setting::getGroup('appearance'));
        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'appearance'), $form)
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        Setting::clearCache();
        $after = AppearanceSettings::resolveActiveDesign(Setting::getGroup('appearance'));

        $this->assertSame([], $after['_custom_colours']);
        $this->assertSame([$before['_theme_id'], $before['_theme_flavor']], [$after['_theme_id'], $after['_theme_flavor']]);
        foreach (['theme', 'home_layout_mode', 'hero_layout_mode', 'header_style', 'watch_layout_mode', 'modal_presentation_style', 'typography_pairing', ...ThemeCatalog::COLOUR_KEYS] as $key) {
            $this->assertSame(AppearanceSettings::text($before, $key), AppearanceSettings::text($after, $key), "{$key} survives a plain Save");
        }

        // The colours that Save stored do not hold back a later colour style switch; a real custom colour stays.
        Setting::set('accent_color', '#123456', 'appearance');
        $flavor = array_keys(ThemeCatalog::flavors('obsidian_gold'))[1];
        AppearanceApplier::applyValues(['theme_flavor' => $flavor]);
        $stored = Setting::getGroup('appearance');
        $this->assertSame(['accent_color' => '#123456'], array_intersect_key($stored, array_flip(ThemeCatalog::COLOUR_KEYS)));
        $resolved = AppearanceSettings::resolveActiveDesign($stored);
        $this->assertSame(ThemeCatalog::flavorColours('obsidian_gold', $flavor)['primary_color'], $resolved['primary_color']);
        $this->assertSame(['accent_color' => '#123456'], $resolved['_custom_colours']);
    }

    public function test_appearance_applier_null_removes_a_value(): void
    {
        Setting::set('appearance_schema_version', '2', 'appearance');
        Setting::set('primary_color', '#123456', 'appearance');
        Setting::set('home_layout_mode', 'netflix_rows', 'appearance');

        $result = AppearanceApplier::applyValues(['primary_color' => null, 'home_layout_mode' => null, 'footer_text' => 'Kept']);

        $stored = Setting::getGroup('appearance');
        $this->assertSame(['footer_text', 'home_layout_mode', 'primary_color'], $result['changed']);
        $this->assertArrayNotHasKey('primary_color', $stored, 'back to the colour style, never an empty colour');
        $this->assertArrayNotHasKey('home_layout_mode', $stored);
        $this->assertSame('#ff0055', AppearanceSettings::resolveActiveDesign($stored)['primary_color']);
    }

    public function test_settings_revisions_record_saves_of_chosen_keys_and_restore_only_safe_ones(): void
    {
        (require database_path('migrations/2026_10_20_000001_create_admin_setting_revisions_table.php'))->up();
        Setting::set('home_layout_mode', 'grid_modern', 'appearance');
        Setting::set('footer_text', 'Footer', 'appearance');
        Setting::set('meta_title_template', '{title}', 'seo');

        // A settings save (the I7 shape): only the keys it wrote, nested by group; null = the key was not stored.
        Setting::set('home_layout_mode', 'netflix_rows', 'appearance');
        Setting::set('sidebar_style', 'rail', 'appearance');
        $save = SettingsRevision::record(
            'settings_save',
            'appearance',
            ['appearance' => ['home_layout_mode' => 'grid_modern', 'sidebar_style' => null]],
            ['appearance' => ['home_layout_mode' => 'netflix_rows', 'sidebar_style' => 'rail']],
            7,
        );
        $revision = SettingsRevision::find($save);
        $this->assertSame(['keys', ['home_layout_mode', 'sidebar_style'], true, 7], [$revision['scope'], $revision['changed'], $revision['restorable'], $revision['user_id']]);

        Setting::set('footer_text', 'Changed later', 'appearance');
        $restored = SettingsRevision::restore($save);
        Setting::clearCache();
        $appearance = Setting::getGroup('appearance');
        $this->assertSame(['home_layout_mode', 'sidebar_style'], $restored['changed']);
        $this->assertSame('grid_modern', $appearance['home_layout_mode']);
        $this->assertArrayNotHasKey('sidebar_style', $appearance);
        $this->assertSame('Changed later', $appearance['footer_text'], 'keys the save did not write stay as they are');
        $this->assertSame(['restore', 'keys'], [SettingsRevision::find($restored['revision'])['kind'], SettingsRevision::find($restored['revision'])['scope']]);

        // A save of several groups is listed for each of them and never restored (it covers SEO).
        $multi = SettingsRevision::record(
            'settings_save',
            'appearance,seo',
            ['appearance' => ['footer_text' => 'A'], 'seo' => ['meta_title_template' => '{title}']],
            ['appearance' => ['footer_text' => 'B'], 'seo' => ['meta_title_template' => 'x']],
        );
        $this->assertSame(['appearance.footer_text', 'seo.meta_title_template'], SettingsRevision::find($multi)['changed']);
        $this->assertContains($multi, array_column(SettingsRevision::latest(20, 'seo'), 'id'));
        $this->assertContains($multi, array_column(SettingsRevision::latest(20, 'appearance'), 'id'));
        $this->assertNull(SettingsRevision::restore($multi));

        // Another group, or a masked password or key, is never written back.
        Setting::set('meta_title_template', 'x', 'seo');
        Setting::set('custom_js', 'console.log(2);', 'appearance');
        $seo = SettingsRevision::record('settings_save', 'seo', ['seo' => ['meta_title_template' => '{title}']], ['seo' => ['meta_title_template' => 'x']]);
        $masked = SettingsRevision::record('settings_save', 'appearance', ['appearance' => ['custom_js' => SettingsRevision::MASK]], ['appearance' => ['custom_js' => 'console.log(2);']]);
        $this->assertSame([false, false], [SettingsRevision::find($seo)['restorable'], SettingsRevision::find($masked)['restorable']]);
        $this->assertNull(SettingsRevision::restore($seo));
        $this->assertNull(SettingsRevision::restore($masked));
        Setting::clearCache();
        $this->assertSame('x', Setting::getGroup('seo')['meta_title_template']);
        $this->assertSame('console.log(2);', Setting::getGroup('appearance')['custom_js'], 'a masked value is never written back');

        foreach ([
            'nested value' => fn () => SettingsRevision::record('settings_save', 'appearance', ['home_layout_mode' => ['x']], []),
            'flat rows of several groups' => fn () => SettingsRevision::record('settings_save', 'appearance,seo', ['footer_text' => 'a'], ['footer_text' => 'b']),
            'object value' => fn () => SettingsRevision::record('settings_save', 'appearance', ['appearance' => ['footer_text' => new \stdClass()]], []),
        ] as $label => $call) {
            try {
                $call();
                $this->fail("{$label} must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Retention: expired revisions go, each group keeps its newest KEEP, the live upgrade snapshot stays.
        $rows = SettingsRevision::rows('appearance');
        $snapshot = SettingsRevision::record('upgrade_snapshot', 'appearance', $rows, $rows, null, now()->addDays(30));
        $this->assertSame('group', SettingsRevision::find($snapshot)['scope']);
        $expired = SettingsRevision::record('upgrade_snapshot', 'appearance', $rows, $rows, null, now()->subMinute());
        $this->assertNull(SettingsRevision::find($expired));

        for ($i = 0; $i < SettingsRevision::KEEP + 5; $i++) {
            SettingsRevision::record('publish', 'appearance', ['appearance' => ['footer_text' => "v{$i}"]], ['appearance' => ['footer_text' => 'v' . ($i + 1)]]);
        }

        $this->assertSame(SettingsRevision::KEEP, DB::table('admin_setting_revisions')->where('group', 'appearance')->where('kind', '!=', 'upgrade_snapshot')->count());
        $this->assertNotNull(SettingsRevision::find($snapshot));
        $this->assertSame($snapshot, SettingsRevision::upgradeSnapshot()['id']);
        $this->assertNotNull(SettingsRevision::find($multi), 'other group lists are pruned on their own');
    }

    public function test_appearance_applier_works_before_the_revisions_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('admin_setting_revisions'));

        $result = AppearanceApplier::applyTheme('daylight_paper');

        $this->assertNull($result['revision']);
        $this->assertSame('daylight_paper', Setting::getGroup('appearance')['theme_preset']);
        $this->assertSame([], SettingsRevision::latest());
        $this->assertNull(SettingsRevision::upgradeSnapshot());
        $this->assertNull(AppearanceApplier::restoreRevision(1));
    }

    public function test_brand_asset_settings_render_in_public_layout(): void
    {
        $this->withoutVite();

        Setting::set('logo_url', '/images/custom-logo.svg', 'appearance');
        Setting::set('favicon_url', '/icons/custom-favicon.ico', 'appearance');

        config([
            'site.logo_url' => '/images/custom-logo.svg',
            'site.favicon_url' => '/icons/custom-favicon.ico',
            'site.og_image' => '',
        ]);

        $html = view('layouts.default')->render();
        $logoUrl = site_asset_url('/images/custom-logo.svg');
        $faviconUrl = site_asset_url('/icons/custom-favicon.ico');

        $this->assertStringContainsString('src="' . $logoUrl . '"', $html);
        $this->assertStringContainsString('rel="icon" href="' . $faviconUrl . '"', $html);
        $this->assertStringContainsString('property="og:image" content="' . $logoUrl . '"', $html);
    }

    public function test_watch_page_select_settings_reject_invalid_values(): void
    {
        $this->actingAs($this->userWithAdminFlag(true))
            ->from(route('admin.settings.index', ['tab' => 'appearance']))
            ->put(route('admin.settings.update.group', 'appearance'), $this->validAppearancePayload([
                'watch_player_frame_style' => 'unsafe-custom-frame',
                'watch_sidebar_density' => 'unsafe-density',
                'secondary_page_density' => 'unsafe-secondary-density',
                'secondary_page_max_width' => 'unsafe-secondary-width',
                'font_family' => 'unsafe-font',
                'text_contrast' => 'unsafe-contrast',
            ]))
            ->assertSessionHasErrors(['watch_player_frame_style', 'watch_sidebar_density', 'secondary_page_density', 'secondary_page_max_width', 'font_family', 'text_contrast']);

        $this->assertDatabaseMissing('settings', [
            'key' => 'watch_player_frame_style',
            'value' => 'unsafe-custom-frame',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'watch_sidebar_density',
            'value' => 'unsafe-density',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'secondary_page_density',
            'value' => 'unsafe-secondary-density',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'secondary_page_max_width',
            'value' => 'unsafe-secondary-width',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'font_family',
            'value' => 'unsafe-font',
        ]);
        $this->assertDatabaseMissing('settings', [
            'key' => 'text_contrast',
            'value' => 'unsafe-contrast',
        ]);
    }

    private function userWithAdminFlag(bool $isAdmin): User
    {
        $name = $isAdmin ? 'appearance-admin' : 'appearance-member';

        return tap(new User(), function (User $user) use ($isAdmin, $name) {
            $user->forceFill([
                'username' => $name,
                'slug' => $name,
                'email' => $name . '@example.test',
                'password' => 'password',
                'is_admin' => $isAdmin,
                'email_verified_at' => now(),
            ]);
            $user->save();
        });
    }

    /**
     * The fields of the v1 appearance form as the page posts them (FormData: enabled controls, checked boxes only,
     * the selected option or else the first).
     *
     * @return array<string, string|array<int, string>>
     */
    private function appearanceForm(User $admin): array
    {
        Setting::clearCache();
        $html = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'appearance']))->assertOk()->getContent();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $form = $xpath->query('//form[contains(@action, "/settings/update/appearance")]')->item(0);
        $this->assertNotNull($form, 'the v1 appearance form');
        $fields = [];

        foreach ($xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form) as $element) {
            $name = $element->getAttribute('name');
            $type = strtolower($element->getAttribute('type'));

            if ($element->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file', 'image', 'reset'], true)
                || (in_array($type, ['checkbox', 'radio'], true) && ! $element->hasAttribute('checked'))) {
                continue;
            }

            if ($element->nodeName === 'select') {
                $options = $xpath->query('.//option', $element);
                $selected = $xpath->query('.//option[@selected]', $element)->item(0) ?? $options->item(0);
                $value = $selected === null ? '' : ($selected->hasAttribute('value') ? $selected->getAttribute('value') : trim($selected->textContent));
            } elseif ($element->nodeName === 'textarea') {
                $value = $element->textContent;
            } else {
                $value = $element->hasAttribute('value') ? $element->getAttribute('value') : (in_array($type, ['checkbox', 'radio'], true) ? 'on' : '');
            }

            if (str_ends_with($name, '[]')) {
                $fields[substr($name, 0, -2)][] = $value;
            } else {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    private function appearanceSettingsSchema(): array
    {
        $method = new ReflectionMethod(SettingsController::class, 'settingsSchema');
        $method->setAccessible(true);

        return $method->invoke(app(SettingsController::class))['appearance'];
    }

    /**
     * A look's value for a key: v2 looks carry apply_settings, v1 looks the keys themselves.
     */
    private function experienceValue(array $experience, string $key, ?string $fallback = null): string
    {
        return (string) ($experience['apply_settings'][$key] ?? $experience[$key] ?? $fallback);
    }

    private function validAppearancePayload(array $overrides = []): array
    {
        $defaults = AppearanceSettings::defaults();

        foreach (AppearanceSettings::booleanKeys() as $key) {
            $defaults[$key] = '1';
        }

        return array_replace($defaults, $overrides);
    }
}
