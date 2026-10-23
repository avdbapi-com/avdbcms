<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\AppearanceSettings;
use App\Support\ThemeCatalog;
use App\Support\ThemeCatalogData;
use Illuminate\Http\Request;
use ReflectionClassConstant;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

/**
 * Theme catalogue v2: 32 themes / 13 families / 174 colour styles, and every one of the 120 old theme ids resolving to
 * a theme + colour style at runtime (previews, the v1 preset contract, "formerly" labels and search).
 */
class ThemeCatalogAliasTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    private const HEX = '/^#[0-9a-f]{6}$/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshAdminSettingsSchema();
    }

    protected function tearDown(): void
    {
        $this->dropAdminSettingsSchema();

        parent::tearDown();
    }

    public function test_catalog_has_the_v2_counts_and_every_theme_is_complete(): void
    {
        $themes = ThemeCatalog::themes();
        $families = ThemeCatalog::families();

        $this->assertCount(32, $themes);
        $this->assertCount(13, $families);
        $this->assertSame(174, array_sum(array_map(fn (array $theme): int => count($theme['flavors']), $themes)));
        $this->assertCount(120, ThemeCatalog::aliases());
        $this->assertSame(ThemeCatalog::DEFAULT_THEME, ThemeCatalogData::meta()['default_theme']);
        $this->assertSame(array_keys($themes), array_merge(...array_values(array_map(fn (array $family): array => $family['themes'], $families))));

        $options = AppearanceSettings::optionLists();

        foreach ($themes as $id => $theme) {
            $this->assertSame($id, $theme['id']);
            $this->assertArrayHasKey($theme['family'], $families, $id);
            $this->assertSame('signature', array_key_first($theme['flavors']), $id);
            $this->assertContains($theme['default_mode'], ['dark', 'light'], $id);
            $this->assertArrayHasKey($theme['typography']['pairing'], AppearanceSettings::typographyPairings(), $id);

            foreach (['dark', 'light'] as $mode) {
                $this->assertArrayHasKey('bg', $theme['palette'][$mode], $id);
            }

            foreach ($theme['flavors'] as $flavorId => $flavor) {
                $this->assertSame($flavorId, $flavor['id']);
                $this->assertSame(ThemeCatalog::COLOUR_KEYS, array_keys($flavor['colours']), "{$id}/{$flavorId}");

                foreach ($flavor['colours'] as $key => $colour) {
                    $this->assertMatchesRegularExpression(self::HEX, $colour, "{$id}/{$flavorId} {$key}");
                }
            }

            $this->assertSame($theme['theme_owned_recipe'], ThemeCatalog::recipe($id));

            foreach (['header_deco', 'hero_deco', 'card_frame', 'chip_style', 'section_style', 'backdrop', 'avatar_shape'] as $key) {
                $this->assertArrayHasKey($key, ThemeCatalog::recipe($id), "{$id} recipe {$key}");
            }

            $recommended = ThemeCatalog::recommendedSettings($id);
            $this->assertSame($id, $recommended['theme_preset']);
            $this->assertSame('signature', $recommended['theme_flavor']);

            foreach ($recommended as $key => $value) {
                if (isset($options[$key])) {
                    $this->assertContains($value, $options[$key], "{$id}: {$key}={$value} is not a valid option");
                }
            }
        }
    }

    public function test_every_old_id_resolves_to_its_theme_and_colour_style(): void
    {
        $oldIds = array_keys($this->frozenSources()['preset_values']);
        $this->assertCount(120, $oldIds);
        $this->assertSame($oldIds, array_keys(ThemeCatalog::aliases()), 'aliases keep the old catalogue order');

        foreach ($oldIds as $oldId) {
            $alias = ThemeCatalog::aliases()[$oldId];
            $resolved = ThemeCatalog::resolve($oldId);

            $this->assertNotNull($resolved, $oldId);
            $this->assertSame([$alias['theme'], $alias['flavor']], [$resolved['theme'], $resolved['flavor']], $oldId);
            $this->assertSame(! ThemeCatalog::isThemeId($oldId), $resolved['legacy'], $oldId);
            $this->assertNotNull(ThemeCatalog::flavor($resolved['theme'], $resolved['flavor']), $oldId);
            $this->assertTrue(ThemeCatalog::isKnownId($oldId));

            if (ThemeCatalog::isThemeId($oldId)) {
                // an old id that became a v2 theme keeps its own design and the signature style
                $this->assertSame([$oldId, 'signature'], [$alias['theme'], $alias['flavor']]);
            }
        }

        foreach (ThemeCatalog::themeIds() as $id) {
            $this->assertSame(['theme' => $id, 'flavor' => 'signature', 'legacy' => false, 'id' => $id], ThemeCatalog::resolve($id));
        }

        $this->assertSame(ThemeCatalog::recipe('tokyo_neon'), ThemeCatalog::recipe('laser_grid'));
        $this->assertSame([], ThemeCatalog::recipe('no_such_theme'));
        $this->assertNull(ThemeCatalog::resolve('no_such_theme'));
        $this->assertNull(ThemeCatalog::resolve(''));
        $this->assertSame('tokyo_neon', ThemeCatalog::resolveOrDefault('no_such_theme')['theme']);
        $this->assertSame('volt', ThemeCatalog::resolve('tokyo_neon', 'volt')['flavor']);
        $this->assertSame('coral', ThemeCatalog::resolve('cyberpunk_neon', 'not_a_flavor')['flavor']);
        $this->assertSame(['theme' => 'tokyo_neon', 'flavor' => 'coral', 'legacy' => true, 'id' => 'cyberpunk_neon'], ThemeCatalog::resolve('cyberpunk_neon'));
    }

    public function test_old_names_stay_searchable_and_show_as_formerly_labels(): void
    {
        $this->assertSame(['Cyberpunk Neon (Default)', 'Tokyo Neon Horizon', 'Laser Grid Arcade'], ThemeCatalog::legacyLabels('tokyo_neon'));
        $this->assertSame(['Cyberpunk Neon (Default)'], ThemeCatalog::legacyLabels('tokyo_neon', 'coral'));
        $this->assertSame('formerly Cyberpunk Neon (Default)', ThemeCatalog::formerlyLabel('tokyo_neon', 'coral'));
        $this->assertSame('', ThemeCatalog::formerlyLabel('neo_pop'));
        $this->assertSame([], ThemeCatalog::legacyLabels('no_such_theme'));

        foreach (ThemeCatalog::aliases() as $oldId => $alias) {
            $hits = ThemeCatalog::search($alias['old_label']);
            $this->assertContains(
                [$alias['theme'], $alias['flavor']],
                array_map(fn (array $hit): array => [$hit['theme'], $hit['flavor']], $hits),
                "old name {$alias['old_label']} must find {$alias['theme']}/{$alias['flavor']}",
            );
            $this->assertNotEmpty(ThemeCatalog::search($oldId), $oldId);
        }

        $byFamily = ThemeCatalog::search('neon nights');
        $this->assertSame(['shibuya_rain', 'electric_violet'], array_column($byFamily, 'theme'));
        $this->assertSame('family', $byFamily[0]['match']);
        $this->assertSame([], ThemeCatalog::search('   '));
    }

    public function test_old_id_labels_translate_formerly_per_locale(): void
    {
        $alias = ThemeCatalog::aliases()['laser_grid'];
        $name = ThemeCatalog::theme($alias['theme'])['name'] . ' - ' . ThemeCatalog::flavor($alias['theme'], $alias['flavor'])['name'];
        $this->assertSame($name . ' (formerly Laser Grid Arcade)', ThemeCatalog::get('laser_grid')['label']);

        app('translator')->addLines(['*.formerly :names' => 'xx-before :names'], 'xx');
        app()->setLocale('xx');

        $this->assertSame($name . ' (xx-before Laser Grid Arcade)', ThemeCatalog::get('laser_grid')['label']);
        $this->assertSame('xx-before Cyberpunk Neon (Default)', ThemeCatalog::formerlyLabel('tokyo_neon', 'coral'));

        app()->setLocale('en');
        $this->assertSame($name . ' (formerly Laser Grid Arcade)', ThemeCatalog::get('laser_grid')['label']);
    }

    public function test_v1_preset_contract_covers_every_accepted_id(): void
    {
        $all = ThemeCatalog::all();
        $this->assertCount(125, $all);
        $this->assertSame('cyberpunk_neon', array_key_first($all), 'the old first preset stays first (legacy endpoint payloads)');
        $this->assertSame(['silver_screen', 'ivory_salon', 'broadsheet', 'holo_pearl', 'neo_pop'], array_slice(array_keys($all), 120));
        $this->assertSame(ThemeCatalog::categories(), array_keys(ThemeCatalog::byCategory()));
        $this->assertSame(125, array_sum(array_map('count', ThemeCatalog::byCategory())));
        $this->assertSame(array_keys($all), array_keys(AppearanceSettings::themePresets()));
        $this->assertArrayHasKey(ThemeCatalog::random()['id'], array_flip(ThemeCatalog::themeIds()));
        $this->assertNull(ThemeCatalog::find('no_such_theme'));
        $this->assertNotEmpty(ThemeCatalog::categoryDefaults('Studio'));
        $this->assertSame(ThemeCatalog::categoryDefaults('studio'), ThemeCatalog::categoryDefaults('Studio'));
        $this->assertSame([], ThemeCatalog::categoryDefaults('Cyberpunk & Tokyo Neon'));
        $this->assertCount(125, ThemeCatalog::themeCustomizations());

        $options = AppearanceSettings::optionLists();
        $defaults = AppearanceSettings::defaults();

        foreach ($all as $id => $preset) {
            $resolved = ThemeCatalog::resolve($id);
            $this->assertSame($id, $preset['id']);
            $this->assertSame([$resolved['theme'], $resolved['flavor']], [$preset['theme_id'], $preset['theme_flavor']], $id);

            foreach (['label', 'category', 'description', 'primary', 'secondary', 'accent', 'theme', 'theme_oled_black_enabled', 'surface_style',
                'radius_scale', 'font_family', 'header_style', 'category_card_style', 'creator_avatar_ring_style', 'video_card_duration_style',
                'watch_ambient_glow_intensity', 'scrollbar_style', 'hero_layout_mode', 'back_to_top_style', 'mobile_video_columns',
                'mobile_video_card_density', 'video_card_title_lines', 'home_layout_mode', 'watch_layout_mode'] as $key) {
                $this->assertArrayHasKey($key, $preset, "{$id} misses {$key}");
                $this->assertNotSame('', (string) $preset[$key], "{$id} has an empty {$key}");
            }

            $values = AppearanceSettings::presetValues($id);
            $this->assertSame($id, $values['theme_preset']);
            $this->assertSame($resolved['flavor'], $values['theme_flavor']);
            $this->assertSame(ThemeCatalog::theme($id)['default_mode'], $values['theme']);

            foreach ($values as $key => $value) {
                $this->assertArrayHasKey($key, $defaults, "{$id}: {$key} is not a setting");
                $this->assertIsString($value);

                if (isset($options[$key])) {
                    $this->assertContains($value, $options[$key], "{$id}: {$key}={$value}");
                }
            }

            foreach (ThemeCatalog::flavorColours($resolved['theme'], $resolved['flavor']) as $key => $colour) {
                $this->assertSame($colour, $values[$key], "{$id}: {$key} comes from its colour style");
            }
        }
    }

    public function test_every_old_and_new_id_previews_its_theme_for_one_request(): void
    {
        $stored = ['appearance_schema_version' => '2', 'theme_preset' => 'deep_trench', 'theme_flavor' => 'signature', 'primary_color' => '#123456'];

        foreach (array_keys(ThemeCatalog::all()) as $id) {
            $this->app->instance('request', Request::create('/?theme=' . $id . '&theme_mode=light'));
            $resolved = AppearanceSettings::resolveActiveDesign($stored);
            $expected = ThemeCatalog::resolve($id);

            $this->assertTrue($resolved['_theme_preview'], $id);
            $this->assertSame($id, $resolved['theme_preset'], $id);
            $this->assertSame([$expected['theme'], $expected['flavor']], [$resolved['_theme_id'], $resolved['_theme_flavor']], $id);
            $this->assertSame('light', $resolved['theme'], $id);
            $this->assertSame([], $resolved['_custom_colours'], "{$id}: a preview shows the style's colours");
            $this->assertSame(ThemeCatalog::get($id)['home_layout_mode'], $resolved['home_layout_mode'], $id);
        }

        // a colour style preview on top of a theme preview, and on its own
        $this->app->instance('request', Request::create('/?theme=laser_grid&theme_flavor=volt'));
        $resolved = AppearanceSettings::resolveActiveDesign($stored);
        $this->assertSame(['tokyo_neon', 'volt', 'tokyo_neon'], [$resolved['_theme_id'], $resolved['_theme_flavor'], $resolved['theme_preset']]);
        $this->assertSame(ThemeCatalog::flavorColours('tokyo_neon', 'volt')['primary_color'], $resolved['primary_color']);

        $this->app->instance('request', Request::create('/?theme_flavor=midnight'));
        $resolved = AppearanceSettings::resolveActiveDesign($stored);
        $this->assertSame('deep_trench', $resolved['_theme_id']);
        $this->assertSame(array_key_exists('midnight', ThemeCatalog::flavors('deep_trench')) ? 'midnight' : 'signature', $resolved['_theme_flavor']);

        // without a preview the stored theme, its style and the owner's colour win
        $this->app->instance('request', Request::create('/'));
        $resolved = AppearanceSettings::resolveActiveDesign($stored);
        $this->assertArrayNotHasKey('_theme_preview', $resolved);
        $this->assertSame(['deep_trench', 'signature'], [$resolved['_theme_id'], $resolved['_theme_flavor']]);
        $this->assertSame(['primary_color' => '#123456'], $resolved['_custom_colours']);
    }

    public function test_old_and_new_ids_render_as_previews_in_the_public_layout(): void
    {
        Setting::set('appearance_schema_version', '2', 'appearance');

        foreach (['cyberpunk_neon', 'laser_grid', 'arctic_glacier', 'valorant_crimson', 'golden_hour_light', 'tokyo_neon', 'broadsheet', 'neo_pop'] as $id) {
            $this->app->instance('request', Request::create('/?theme=' . $id));
            $html = view('layouts.default')->render();

            $this->assertStringContainsString('data-avd-theme-preset="' . $id . '"', $html, $id);
            $this->assertStringContainsString('data-avd-home-layout="' . ThemeCatalog::get($id)['home_layout_mode'] . '"', $html, $id);
            $this->assertStringContainsString('data-bs-theme="' . ThemeCatalog::theme($id)['default_mode'] . '"', $html, $id);
        }

        $this->app->instance('request', Request::create('/?theme=not_a_theme'));
        $this->assertStringContainsString('data-avd-theme-preset="tokyo_neon"', view('layouts.default')->render());
    }

    private function frozenSources(): array
    {
        $migration = require database_path('migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php');

        return json_decode((new ReflectionClassConstant($migration, 'FACTORY_SOURCES'))->getValue(), true);
    }
}
