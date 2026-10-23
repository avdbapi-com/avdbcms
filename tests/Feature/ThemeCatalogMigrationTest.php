<?php

namespace Tests\Feature;

use App\Support\AppearanceApplier;
use App\Support\AppearanceSettings;
use App\Support\SettingsRevision;
use App\Support\ThemeCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

/**
 * The theme catalogue v2 upgrade of saved appearance settings (migration plan B3): 120 old ids x {untouched,
 * customised colour, customised layout key} x {no look, the look that uses the preset}, the four factory states, the
 * two legacy look ids and an unknown option value. Every case also checks: light/dark never flips, revision #0
 * restores the stored rows byte for byte, a second run changes nothing, other settings groups are untouched.
 */
class ThemeCatalogMigrationTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    private const CUSTOM_COLOUR = '#123456';

    private const LOOK_KEYS = [
        'home_layout_mode', 'hero_layout_mode', 'watch_layout_mode', 'video_card_layout_style', 'header_style', 'sidebar_style',
        'category_card_style', 'creator_avatar_ring_style', 'video_card_duration_style', 'modal_presentation_style',
        'motion_engine_level', 'typography_pairing', 'background_atmosphere_effect', 'content_density', 'home_feed_columns',
        'grid_columns', 'mobile_video_columns', 'interactive_3d_card_tilt_enabled', 'cinema_ambient_light_diffusion_enabled',
        'watch_ambient_glow_intensity', 'hero_slider_autoplay_enabled', 'scrollbar_style', 'surface_style', 'radius_scale',
        'video_card_title_lines', 'theme_oled_black_enabled',
    ];

    private object $migration;

    private array $sources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshAdminSettingsSchema();
        Schema::dropIfExists('admin_setting_revisions');
        (require database_path('migrations/2026_10_20_000001_create_admin_setting_revisions_table.php'))->up();
        $this->migration = require database_path('migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php');
        $this->sources = json_decode((new ReflectionClassConstant($this->migration, 'FACTORY_SOURCES'))->getValue(), true);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('admin_setting_revisions');
        $this->dropAdminSettingsSchema();

        parent::tearDown();
    }

    public function test_untouched_presets_move_to_their_v2_theme_or_look(): void
    {
        $this->assertCount(120, $this->sources['preset_values']);

        foreach ($this->cases() as [$preset, $master]) {
            $stored = $this->appliedPreset($preset, $master);
            [$after, $plan] = $this->runCase($stored, "{$preset}+{$master}");
            $alias = ThemeCatalog::resolve($preset);

            if ($preset === 'cyberpunk_neon' && $master === '') {
                $this->assertSame('FACTORY', $plan['class'], $preset);
                $this->assertFactoryLook($after, $preset);

                continue;
            }

            if ($master === '') {
                $this->assertSame('UNTOUCHED_PRESET', $plan['class'], $preset);
                $this->assertSame([$alias['theme'], $alias['flavor']], [$after['theme_preset'], $after['theme_flavor']], $preset);
                $this->assertSame('', $after['active_master_experience'], $preset);
                $recommended = ThemeCatalog::recommendedSettings($alias['theme'], $alias['flavor']);

                foreach (self::LOOK_KEYS as $key) {
                    $this->assertSame($recommended[$key], $after[$key], "{$preset}: untouched preset renders the recommended {$key}");
                }

                $this->assertColoursFromStyle($after, $preset);

                continue;
            }

            $this->assertSame('UNTOUCHED_MASTER', $plan['class'], "{$preset}+{$master}");
            $policy = $this->callMigration('masterPolicy', $master);

            if ($policy['mode'] === 'reapply') {
                $look = $this->callMigration('masterSettings', $master);

                foreach (self::LOOK_KEYS as $key) {
                    $this->assertSame($look[$key], $after[$key], "{$master}: re-applied look {$key}");
                }

                $this->assertSame($master, $after['active_master_experience']);
                $this->assertSame($look['theme_preset'], $after['theme_preset'], $master);
                $this->assertSame($alias['flavor'], $after['theme_flavor'], "{$master} keeps the alias colour style");
            } else {
                $this->assertSame('colours_only', $policy['mode']);
                $this->assertLayoutKept($stored, $after, $master);
                $this->assertSame('', $after['active_master_experience']);
                $this->assertSame($master, $after['suggested_master_upgrade']);
                $this->assertSame([$alias['theme'], $alias['flavor']], [$after['theme_preset'], $after['theme_flavor']], $master);
            }

            $this->assertColoursFromStyle($after, "{$preset}+{$master}");
        }
    }

    public function test_customised_colours_are_kept_as_owner_overrides(): void
    {
        foreach ($this->cases() as [$preset, $master]) {
            $stored = $this->appliedPreset($preset, $master);
            $stored['primary_color'] = self::CUSTOM_COLOUR;
            [$after, $plan] = $this->runCase($stored, "colour {$preset}+{$master}");
            $alias = ThemeCatalog::resolve($preset);

            $this->assertSame('CUSTOMISED', $plan['class'], $preset);
            $this->assertContains('primary_color', $plan['customised']);
            $this->assertSame(self::CUSTOM_COLOUR, $after['primary_color'], "{$preset}: the custom colour is still stored");
            $this->assertSame(['primary_color' => self::CUSTOM_COLOUR], AppearanceSettings::customColours($after), $preset);
            // brand_contrast_autofix is on unless the owner opts out, so the fitted variant renders
            $this->assertTrue(AppearanceSettings::bool($after, 'brand_contrast_autofix'));
            $this->assertSame([$alias['theme'], $alias['flavor']], [$after['theme_preset'], $after['theme_flavor']], $preset);
            $this->assertLayoutKept($stored, $after, $preset);
        }
    }

    public function test_customised_layout_keys_are_never_changed(): void
    {
        foreach ($this->cases() as [$preset, $master]) {
            $stored = $this->appliedPreset($preset, $master);
            $stored['home_layout_mode'] = $this->customHomeLayout($preset, $master);
            [$after, $plan] = $this->runCase($stored, "layout {$preset}+{$master}");

            $this->assertSame('CUSTOMISED', $plan['class'], $preset);
            $this->assertSame(['home_layout_mode'], $plan['customised'], $preset);
            $this->assertLayoutKept($stored, $after, $preset);
            $this->assertSame($master === '' ? '' : $master, $after['active_master_experience'], $preset);
            $this->assertColoursFromStyle($after, $preset);
        }
    }

    public function test_every_factory_state_lands_on_the_youtube_spotlight_pro_look(): void
    {
        $states = [
            'no rows' => [],
            'example.sql rows' => $this->sources['example_sql_appearance_rows'],
            'reset default theme state' => $this->sources['reset_default_theme_state'],
            'only fallbacks' => array_replace(...array_values($this->sources['fallbacks'])),
            'old defaults + mixed-case colours' => array_replace($this->sources['old_defaults'], ['primary_color' => '#FF3D61', 'grid_columns' => '4', 'effects_enabled' => 'true']),
        ];

        foreach ($states as $label => $stored) {
            [$after, $plan] = $this->runCase(array_map('strval', $stored), $label);

            $this->assertSame('FACTORY', $plan['class'], $label);
            $this->assertFactoryLook($after, 'cyberpunk_neon', $label);
        }
    }

    public function test_legacy_look_ids_and_unknown_option_values(): void
    {
        $stored = $this->appliedPreset('tokyo_neon', 'youtube_spotlight_pro');
        $stored['active_master_experience'] = 'youtube_2026_pro';
        [$after, $plan] = $this->runCase($stored, 'youtube_2026_pro');
        $this->assertSame(['UNTOUCHED_MASTER', 'youtube_spotlight_pro', 'reapply'], [$plan['class'], $plan['master'], $plan['policy']]);
        $this->assertSame('youtube_spotlight_pro', $after['active_master_experience']);
        $this->assertSame(['tokyo_neon', 'signature'], [$after['theme_preset'], $after['theme_flavor']]);

        $stored = $this->appliedPreset('cyber_matrix', 'cyberpunk_hud');
        $stored['active_master_experience'] = 'cyberpunk_hud_2077';
        [$after, $plan] = $this->runCase($stored, 'cyberpunk_hud_2077');
        $this->assertSame(['UNTOUCHED_MASTER', 'cyberpunk_hud', 'colours_only'], [$plan['class'], $plan['master'], $plan['policy']]);
        $this->assertSame('cyberpunk_hud', $after['suggested_master_upgrade']);
        $this->assertSame('', $after['active_master_experience']);
        $this->assertLayoutKept($stored, $after, 'cyberpunk_hud_2077');

        $stored = $this->appliedPreset('abyssal_blue', '');
        $stored['header_style'] = 'not_a_header';
        $stored['typography_pairing'] = 'gone_pairing';
        $stored['performance_tier_mode'] = '';
        [$after, $plan] = $this->runCase($stored, 'unknown options');
        $recommended = ThemeCatalog::recommendedSettings($after['theme_preset'], $after['theme_flavor']);
        $this->assertSame('CUSTOMISED', $plan['class']);
        $this->assertSame($recommended['header_style'], $after['header_style']);
        $this->assertSame($recommended['typography_pairing'], $after['typography_pairing']);
        $this->assertSame('', $after['performance_tier_mode'], 'an empty optional value already renders its default');

        $stored = $this->appliedPreset('pulsar_cyan', '');
        $stored['header_style'] = 'sidebar_rail';
        [$after] = $this->runCase($stored, 'sidebar_rail');
        $this->assertSame(['glass_blur', 'rail'], [$after['header_style'], $after['sidebar_style']]);
    }

    public function test_light_and_dark_never_flip_and_absent_keys_keep_their_old_rendering(): void
    {
        foreach (['arctic_glacier', 'golden_hour_light', 'swiss_monolith'] as $preset) {
            foreach (['dark', 'light'] as $mode) {
                $stored = $this->appliedPreset($preset, '');
                $stored['theme'] = $mode;
                [$after] = $this->runCase($stored, "{$preset} {$mode}");

                $this->assertSame($mode, $after['theme'], "{$preset}: mode {$mode} kept");
            }
        }

        // A customised site that never stored a layout key keeps rendering the old default for it.
        $stored = $this->sources['example_sql_appearance_rows'];
        $stored['primary_color'] = self::CUSTOM_COLOUR;
        [$after, $plan] = $this->runCase($stored, 'customised factory rows');
        $this->assertSame('CUSTOMISED', $plan['class']);
        $this->assertSame(['tokyo_neon', 'coral'], [$after['theme_preset'], $after['theme_flavor']]);
        $this->assertSame('grid_modern', $after['home_layout_mode']);
        $this->assertSame('snap_slider_carousel', $after['hero_layout_mode']);
        $this->assertSame('full', $after['daily_design_scope'], 'daily_design_scope keeps the old default on upgraded sites');
        $this->assertSame('', $after['active_master_experience']);
        $this->assertSame('cyberpunk_neon', $after['theme_preset_legacy']);
    }

    public function test_the_shipped_example_sql_installs_the_v2_factory_look(): void
    {
        $sql = file_get_contents(database_path('install/example.sql'));
        preg_match_all("/^\\(\\d+,'((?:[^'\\\\]|\\\\.)*)','((?:[^'\\\\]|\\\\.)*)','appearance','[^']*','[^']*'\\)[,;]$/m", $sql, $matches, PREG_SET_ORDER);
        $rows = [];

        foreach ($matches as $match) {
            $rows[stripcslashes($match[1])] = stripcslashes($match[2]);
        }

        $this->assertGreaterThan(100, count($rows));
        $this->assertSame(AppearanceSettings::SCHEMA_VERSION, $rows['appearance_schema_version'] ?? null, 'fresh installs skip the upgrade');
        $this->assertSame('palette_only', $rows['daily_design_scope'] ?? null, 'D5: colours only on new installs');
        $this->assertArrayNotHasKey('theme_preset_legacy', $rows);
        $this->assertFactoryLook($rows, 'example.sql', 'example.sql');

        // the upgrade leaves a fresh install alone
        $this->seedRows($rows);
        $this->migration->up();
        $this->assertSame($this->normalise($rows), SettingsRevision::rows('appearance'));
        $this->assertSame(0, DB::table('admin_setting_revisions')->count());
    }

    public function test_nothing_is_written_without_the_revisions_table_and_runtime_resolves_unmigrated_rows(): void
    {
        Schema::dropIfExists('admin_setting_revisions');
        $stored = $this->appliedPreset('orion_nebula', '');
        $this->seedRows($stored);

        $this->migration->up();

        $this->assertSame($this->normalise($stored), SettingsRevision::rows('appearance'));

        // A site that never ran the upgrade still renders the alias theme + colour style with its own layout keys.
        $this->app->instance('request', Request::create('/'));
        $resolved = AppearanceSettings::resolveActiveDesign($stored);
        $alias = ThemeCatalog::resolve('orion_nebula');
        $this->assertSame([$alias['theme'], $alias['flavor']], [$resolved['_theme_id'], $resolved['_theme_flavor']]);
        $this->assertSame($stored['home_layout_mode'], $resolved['home_layout_mode']);
        $this->assertSame('#ff3d61', AppearanceSettings::text([], 'primary_color'), 'unmigrated rows fall back to the v1 defaults');
        $this->assertSame('#ff0055', AppearanceSettings::text(['appearance_schema_version' => '2'], 'primary_color'));
    }

    public function test_daily_designs_with_scope_full_keep_rotating_the_home_layout(): void
    {
        $factory = array_map('strval', $this->sources['example_sql_appearance_rows']);
        $cases = [
            'on, scope full' => [['daily_design_auto_enabled' => '1', 'daily_design_scope' => 'full'], '1'],
            'on, scope never stored (v1 default full)' => [['daily_design_auto_enabled' => 'true'], '1'],
            'on, palette only' => [['daily_design_auto_enabled' => '1', 'daily_design_scope' => 'palette_only'], null],
            'off, scope full' => [['daily_design_auto_enabled' => '0', 'daily_design_scope' => 'full'], null],
        ];

        foreach ($cases as $label => [$daily, $expected]) {
            [$after] = $this->runCase(array_replace($factory, $daily), $label);

            $this->assertSame($expected, $after['daily_design_layouts_enabled'] ?? null, $label);
            $this->assertSame($daily['daily_design_scope'] ?? 'full', $after['daily_design_scope'], "{$label}: the scope is kept");
        }
    }

    public function test_upgrade_runs_again_after_restoring_the_snapshot(): void
    {
        $this->seedRows(array_map('strval', $this->sources['example_sql_appearance_rows']));
        $stored = SettingsRevision::rows('appearance');

        $first = $this->migration->run();
        $upgraded = SettingsRevision::rows('appearance');
        $this->assertSame(['FACTORY', 'tokyo_neon', 'signature'], [$first['class'], $first['theme'], $first['flavor']]);
        $this->assertNull(AppearanceApplier::upgrade(), 'an upgraded site is not upgraded twice');

        $this->assertNotNull(AppearanceApplier::restoreRevision($first['snapshot']));
        $this->assertSame($stored, SettingsRevision::rows('appearance'));

        $again = AppearanceApplier::upgrade(7);
        $this->assertSame('FACTORY', $again['class']);
        $this->assertSame($upgraded, SettingsRevision::rows('appearance'), 'upgrading again lands on the same rows');
        $this->assertSame(7, SettingsRevision::find($again['snapshot'])['user_id']);
        $this->assertSame($stored, SettingsRevision::find($again['snapshot'])['before']);
        $this->assertSame($again['snapshot'], SettingsRevision::upgradeSnapshot()['id'], 'the newest snapshot backs "Restore my previous look"');
    }

    public function test_factory_reset_after_restoring_the_snapshot_uses_the_v2_defaults(): void
    {
        $this->seedRows(array_map('strval', $this->sources['example_sql_appearance_rows']));
        $first = $this->migration->run();
        AppearanceApplier::restoreRevision($first['snapshot']);
        $this->assertSame('full', AppearanceSettings::text(SettingsRevision::rows('appearance'), 'daily_design_scope'), 'restored rows render the old way');

        AppearanceApplier::resetToFactory();
        $rows = SettingsRevision::rows('appearance');

        $this->assertSame(AppearanceSettings::SCHEMA_VERSION, $rows['appearance_schema_version']);
        $this->assertSame('palette_only', AppearanceSettings::text($rows, 'daily_design_scope'));
        $this->assertSame(['tokyo_neon', 'signature', 'youtube_spotlight'], [$rows['theme_preset'], $rows['theme_flavor'], $rows['home_layout_mode']]);
    }

    /**
     * Run the upgrade on $stored and check the invariants every case shares. Returns [rows after, plan].
     */
    private function runCase(array $stored, string $label): array
    {
        $this->seedRows($stored);
        $stored = $this->normalise($stored);
        $plan = $this->migration->plan($stored);

        $this->migration->up();

        $after = SettingsRevision::rows('appearance');
        $snapshot = SettingsRevision::upgradeSnapshot();

        $this->assertSame($plan['target'], $after, "{$label}: written rows = plan");
        $this->assertSame(AppearanceSettings::SCHEMA_VERSION, $after['appearance_schema_version'], $label);
        $this->assertSame((string) ($stored['theme_preset'] ?? 'cyberpunk_neon'), $after['theme_preset_legacy'], $label);
        $this->assertSame($stored['theme'] ?? null, $after['theme'] ?? null, "{$label}: light/dark never flips");
        $this->assertTrue(ThemeCatalog::isThemeId($after['theme_preset']), $label);
        $this->assertNotNull(ThemeCatalog::flavor($after['theme_preset'], $after['theme_flavor']), $label);
        $this->assertSame(['site_name' => 'Pin Site'], DB::table('settings')->where('group', 'general')->pluck('value', 'key')->all(), "{$label}: other groups untouched");
        $this->assertNotNull($snapshot, $label);
        $this->assertSame($stored, SettingsRevision::find($snapshot['id'])['before'], "{$label}: revision #0 holds the stored rows");
        $this->assertSame(2, DB::table('admin_setting_revisions')->count(), $label);

        $this->migration->up();
        $this->assertSame($after, SettingsRevision::rows('appearance'), "{$label}: a second run changes nothing");
        $this->assertSame(2, DB::table('admin_setting_revisions')->count(), "{$label}: a second run records nothing");

        $restore = SettingsRevision::restore($snapshot['id']);
        $this->assertNotNull($restore, $label);
        $this->assertSame($stored, SettingsRevision::rows('appearance'), "{$label}: restoring revision #0 reproduces the rows byte for byte");
        $this->assertSame(['site_name' => 'Pin Site'], DB::table('settings')->where('group', 'general')->pluck('value', 'key')->all(), $label);

        return [$after, $plan];
    }

    private function seedRows(array $stored): void
    {
        DB::table('settings')->delete();

        if (Schema::hasTable('admin_setting_revisions')) {
            DB::table('admin_setting_revisions')->delete();
        }
        $now = now();
        $rows = [['key' => 'site_name', 'value' => 'Pin Site', 'group' => 'general', 'created_at' => $now, 'updated_at' => $now]];

        foreach ($stored as $key => $value) {
            $rows[] = ['key' => $key, 'value' => (string) $value, 'group' => 'appearance', 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('settings')->insert($chunk);
        }
    }

    /**
     * What the old admin stored after applying a preset (and a look that uses it): the seeded rows, the preset's
     * complete contract and the look's layout keys.
     */
    private function appliedPreset(string $preset, string $master): array
    {
        $stored = array_replace($this->sources['example_sql_appearance_rows'], $this->sources['preset_values'][$preset]);

        if ($master !== '') {
            $stored = array_replace($stored, array_intersect_key($this->sources['masters'][$master], array_flip($this->sources['master_controlled_keys'])));
            $stored['active_master_experience'] = $master;
        } else {
            $stored['active_master_experience'] = $preset === 'cyberpunk_neon' ? 'default_factory' : '';
        }

        $stored['daily_design_auto_enabled'] = '0';

        return array_map('strval', $stored);
    }

    /** @return array<int, array{0: string, 1: string}> preset id + '' and, for the 8 look presets, preset id + look id */
    private function cases(): array
    {
        $cases = [];

        foreach (array_keys($this->sources['preset_values']) as $preset) {
            $cases[] = [$preset, ''];

            foreach ($this->sources['masters'] as $master => $definition) {
                if ($definition['theme_preset'] === $preset) {
                    $cases[] = [$preset, $master];
                }
            }
        }

        return $cases;
    }

    private function customHomeLayout(string $preset, string $master): string
    {
        $taken = array_filter([
            $this->sources['preset_values'][$preset]['home_layout_mode'] ?? null,
            $this->sources['masters'][$master]['home_layout_mode'] ?? null,
            $this->sources['old_defaults']['home_layout_mode'],
            $this->sources['reset_default_theme_state']['home_layout_mode'] ?? null,
        ]);

        foreach (['tiktok_shorts', 'bento_mosaic', 'cyberpunk_hud', 'cinema_carousel', 'youtube_spotlight'] as $layout) {
            if (! in_array($layout, $taken, true)) {
                return $layout;
            }
        }

        $this->fail("no custom home layout for {$preset}");
    }

    private function assertFactoryLook(array $after, string $preset, string $label = ''): void
    {
        $look = $this->callMigration('masterSettings', 'youtube_spotlight_pro');
        $label = $label ?: $preset;

        $this->assertSame(['tokyo_neon', 'signature', 'youtube_spotlight_pro'], [$after['theme_preset'], $after['theme_flavor'], $after['active_master_experience']], $label);

        foreach (self::LOOK_KEYS as $key) {
            $this->assertSame($look[$key], $after[$key], "{$label}: demo1 parity {$key}");
        }

        // demo1 parity values the owner asked to keep
        $this->assertSame(['youtube_spotlight', 'live_ticker_stream', 'classic_two_column', 'card_standard', '4', 'brand', 'intense', 'glass'], [
            $after['home_layout_mode'], $after['hero_layout_mode'], $after['watch_layout_mode'], $after['video_card_layout_style'],
            $after['grid_columns'], $after['scrollbar_style'], $after['watch_ambient_glow_intensity'], $after['surface_style'],
        ], $label);
        $this->assertColoursFromStyle($after, $label);

        $resolved = AppearanceSettings::withThemeColours($after);
        $this->assertSame(['#ff0055', '#7928ca', '#00dfd8', '#0b0e15', '#12151e'], [
            $resolved['primary_color'], $resolved['brand_secondary_color'], $resolved['accent_color'], $resolved['dark_bg_color'], $resolved['dark_surface_color'],
        ], $label);
    }

    private function assertColoursFromStyle(array $after, string $label): void
    {
        foreach (ThemeCatalog::COLOUR_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $after, "{$label}: untouched {$key} is supplied by the colour style");
        }

        $this->assertSame([], AppearanceSettings::customColours($after), $label);
    }

    private function assertLayoutKept(array $stored, array $after, string $label): void
    {
        foreach (array_unique(array_merge($this->sources['preset_controlled_keys'], $this->sources['master_controlled_keys'])) as $key) {
            if (in_array($key, ['theme_preset', 'header_style'], true) || in_array($key, ThemeCatalog::COLOUR_KEYS, true)) {
                continue;
            }

            if (array_key_exists($key, $stored)) {
                $this->assertSame((string) $stored[$key], $after[$key] ?? null, "{$label}: customised site keeps {$key}");
            }
        }
    }

    private function callMigration(string $method, string ...$arguments): mixed
    {
        return (new ReflectionMethod($this->migration, $method))->invoke($this->migration, ...$arguments);
    }

    private function normalise(array $rows): array
    {
        $rows = array_map(fn ($value): ?string => $value === null ? null : (string) $value, $rows);
        ksort($rows, SORT_STRING);

        return $rows;
    }
}
