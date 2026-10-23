<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppearanceSettings;
use App\Support\DailyDesignCatalog;
use App\Support\MasterExperienceCatalog;
use App\Support\ThemeCatalog;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

/**
 * The 12 Complete looks (theme_spec.json masters.items) and their old ids.
 */
class MasterExperienceCatalogTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    private const GALLERY = [
        'youtube_spotlight_pro',
        'netflix_cinema',
        'velvet_lounge',
        'tiktok_shorts_hub',
        'apple_bento_studio',
        'cyberpunk_hud',
        'twitch_esports_live',
        'cosmic_astronomy_theater',
        'luxury_imperial_salon',
        'editorial_magazine',
        'glass_lounge',
        'daylight_creator',
    ];

    private const V1_LOOKS = [
        'netflix_cinema',
        'youtube_spotlight_pro',
        'tiktok_shorts_hub',
        'apple_bento_studio',
        'cyberpunk_hud',
        'twitch_esports_live',
        'cosmic_astronomy_theater',
        'luxury_imperial_salon',
    ];

    // Setting => AppearanceSettings option list.
    private const OPTION_LISTS = [
        'home_layout_mode' => 'homeLayoutModes',
        'hero_layout_mode' => 'heroLayoutModes',
        'watch_layout_mode' => 'watchLayoutModes',
        'video_card_layout_style' => 'videoCardLayoutStyles',
        'header_style' => 'headerStyles',
        'sidebar_style' => 'sidebarStyles',
        'category_card_style' => 'categoryCardStyles',
        'creator_avatar_ring_style' => 'creatorAvatarRingStyles',
        'video_card_duration_style' => 'videoCardDurationStyles',
        'modal_presentation_style' => 'modalPresentationStyles',
        'motion_engine_level' => 'motionEngineLevels',
        'typography_pairing' => 'typographyPairings',
        'background_atmosphere_effect' => 'backgroundAtmosphereEffects',
        'watch_ambient_glow_intensity' => 'ambientGlowIntensities',
        'scrollbar_style' => 'scrollbarStyles',
    ];

    // What the v2 settings layer adds (theme_spec.json settings_changes). Only consulted while
    // AppearanceSettings still has the v1 lists, i.e. before the theme catalogue v2 is merged.
    private const V2_KEYS = ['theme_flavor', 'sidebar_style'];

    private const V2_OPTIONS = [
        'header_style' => ['transparent_overlay'],
        'hero_layout_mode' => ['editorial_lead'],
        'sidebar_style' => ['full', 'rail', 'floating', 'hidden'],
        'typography_pairing' => [
            'geist_clean', 'pro_mono', 'cinema_bold', 'wide_future', 'club_display', 'retro_exo', 'y2k_pixel', 'velvet_serif',
            'salon_bodoni', 'broadsheet_serif', 'nordic_lexend', 'organic_serif', 'travel_poster', 'zen_garamond', 'ocean_sora',
            'space_orbit', 'aurora_manrope', 'glass_onest', 'holo_grotesk', 'bubble_round', 'fairy_baloo', 'neo_grotesk',
            'brutal_mono', 'sport_condensed', 'toxic_tektur', 'pulse_saira', 'crt_vt323',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware();
        $this->refreshAdminSettingsSchema();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->dropAdminSettingsSchema();
        Cache::flush();

        parent::tearDown();
    }

    public function test_the_twelve_looks_in_gallery_order_with_phase_a_first_built(): void
    {
        $all = MasterExperienceCatalog::all();

        $this->assertSame(self::GALLERY, array_keys($all));
        $this->assertCount(9, MasterExperienceCatalog::PHASE_A);
        $this->assertSame([], array_diff(MasterExperienceCatalog::PHASE_A, self::GALLERY));
        $this->assertSame(['apple_bento_studio', 'twitch_esports_live', 'cosmic_astronomy_theater'], array_keys(array_filter($all, fn (array $look): bool => $look['phase'] === 'B')));
        $this->assertSame(['velvet_lounge', 'editorial_magazine', 'glass_lounge', 'daylight_creator'], array_keys(array_filter($all, fn (array $look): bool => $look['is_new'])));

        $settingKeys = array_keys($all['youtube_spotlight_pro']['apply_settings']);
        $this->assertCount(37, $settingKeys);

        foreach ($all as $id => $look) {
            foreach (['id', 'label', 'subtitle', 'best_for', 'icon', 'badge', 'badge_color', 'formerly', 'theme_name', 'phase', 'factory_default', 'is_new', 'theme', 'flavor', 'theme_preset', 'apply_settings'] as $key) {
                $this->assertArrayHasKey($key, $look, "$id.$key");
            }

            $settings = $look['apply_settings'];
            $this->assertSame($id, $look['id']);
            $this->assertSame($settingKeys, array_keys($settings), $id);
            $this->assertSame($id, $settings['active_master_experience']);
            $this->assertSame([$look['theme'], $look['flavor'], $look['theme']], [$settings['theme_preset'], $settings['theme_flavor'], $look['theme_preset']]);

            // The v1 Appearance tab and its apply action read these at the top level.
            foreach (['home_layout_mode', 'watch_layout_mode', 'video_card_layout_style', 'modal_presentation_style', 'motion_engine_level',
                'interactive_3d_card_tilt_enabled', 'cinema_ambient_light_diffusion_enabled', 'background_atmosphere_effect', 'typography_pairing'] as $key) {
                $this->assertSame($settings[$key], $look[$key], "$id.$key");
            }
        }

        $this->assertSame([$all['youtube_spotlight_pro']], array_values(array_filter($all, fn (array $look): bool => $look['factory_default'])));
    }

    public function test_youtube_spotlight_pro_is_the_default_with_the_values_demo1_renders(): void
    {
        $default = MasterExperienceCatalog::defaultPreset();

        $this->assertSame('youtube_spotlight_pro', $default['id']);
        $this->assertSame(['tokyo_neon', 'signature', 'YouTube Spotlight Pro', 'Midnight Spotlight'], [$default['theme'], $default['flavor'], $default['label'], $default['theme_name']]);
        $this->assertSame([
            'theme' => 'dark',
            'primary_color' => '#ff0055',
            'brand_secondary_color' => '#7928ca',
            'accent_color' => '#00dfd8',
            'dark_bg_color' => '#0b0e15',
            'dark_surface_color' => '#12151e',
            'home_layout_mode' => 'youtube_spotlight',
            'hero_layout_mode' => 'live_ticker_stream',
            'watch_layout_mode' => 'classic_two_column',
            'video_card_layout_style' => 'card_standard',
            'category_card_style' => 'gradient_mesh',
            'creator_avatar_ring_style' => 'neon_pulse',
            'home_feed_columns' => '4',
            'grid_columns' => '4',
            'interactive_3d_card_tilt_enabled' => '1',
            'cinema_ambient_light_diffusion_enabled' => '1',
            'watch_ambient_glow_intensity' => 'intense',
            'hero_slider_autoplay_enabled' => '1',
            'scrollbar_style' => 'brand',
            'surface_style' => 'glass',
        ], array_intersect_key($default['apply_settings'], array_flip([
            'theme', 'primary_color', 'brand_secondary_color', 'accent_color', 'dark_bg_color', 'dark_surface_color', 'home_layout_mode',
            'hero_layout_mode', 'watch_layout_mode', 'video_card_layout_style', 'category_card_style', 'creator_avatar_ring_style',
            'home_feed_columns', 'grid_columns', 'interactive_3d_card_tilt_enabled', 'cinema_ambient_light_diffusion_enabled',
            'watch_ambient_glow_intensity', 'hero_slider_autoplay_enabled', 'scrollbar_style', 'surface_style',
        ])));
    }

    public function test_old_look_ids_resolve(): void
    {
        foreach (['default_factory' => 'youtube_spotlight_pro', 'youtube_2026_pro' => 'youtube_spotlight_pro', 'cyberpunk_hud_2077' => 'cyberpunk_hud'] as $old => $current) {
            $this->assertSame($current, MasterExperienceCatalog::resolveId($old));
            $this->assertSame($current, MasterExperienceCatalog::find($old)['id']);
            $this->assertSame(MasterExperienceCatalog::applySettings($current), MasterExperienceCatalog::applySettings($old));
        }

        foreach (self::GALLERY as $id) {
            $this->assertSame($id, MasterExperienceCatalog::find($id)['id']);
        }

        $this->assertNull(MasterExperienceCatalog::find('no_such_look'));
        $this->assertNull(MasterExperienceCatalog::resolveId(''));
        $this->assertSame([], MasterExperienceCatalog::applySettings('no_such_look'));
    }

    public function test_the_upgrade_policy_covers_every_v1_look(): void
    {
        $policy = MasterExperienceCatalog::upgradePolicy();

        $this->assertEqualsCanonicalizing(self::V1_LOOKS, array_keys($policy));

        foreach ($policy as $id => $row) {
            $look = MasterExperienceCatalog::find($id);
            [$aliasTheme, $aliasFlavor] = explode('/', $row['alias']);

            $this->assertSame($id, $row['master']);
            $this->assertSame($look['theme'] . '/' . $look['flavor'], $row['v2_theme'], $id);
            $this->assertNotNull(ThemeCatalog::find($row['old_theme_preset']), "$id: old theme {$row['old_theme_preset']}");
            $this->assertContains($aliasFlavor, DailyDesignCatalog::flavorIds($aliasTheme), $id);
            // A look that stayed on its theme is re-applied; one that moved theme only takes the old colours.
            $this->assertSame($aliasTheme === $look['theme'] ? 'reapply' : 'colours_only', $row['mode'], $id);
            $this->assertSame($row['mode'] === 'colours_only', $row['suggest'], $id);

            if (method_exists(ThemeCatalog::class, 'resolve')) {
                $resolved = ThemeCatalog::resolve($row['old_theme_preset']);
                $this->assertSame($row['alias'], $resolved['theme'] . '/' . $resolved['flavor'], "$id: the theme catalogue's alias");
            }
        }

        $this->assertSame(['netflix_cinema', 'cyberpunk_hud'], array_keys(array_filter($policy, fn (array $row): bool => $row['mode'] === 'colours_only')));
        $this->assertSame(['fairy_floss/sakura', 'fairy_floss/signature'], [$policy['tiktok_shorts_hub']['alias'], $policy['tiktok_shorts_hub']['v2_theme']]);
    }

    public function test_every_look_uses_catalogue_themes_and_known_option_values(): void
    {
        $themes = DailyDesignCatalog::themeIds();

        if (method_exists(ThemeCatalog::class, 'themes')) {
            $listed = (array) ThemeCatalog::themes();
            $themes = array_map(fn ($theme, $key): string => is_array($theme) ? (string) ($theme['id'] ?? $key) : (string) (is_int($key) ? $theme : $key), $listed, array_keys($listed));
        }

        foreach (MasterExperienceCatalog::all() as $id => $look) {
            $this->assertContains($look['theme'], $themes, $id);
            $this->assertContains($look['flavor'], DailyDesignCatalog::flavorIds($look['theme']), $id);
            $this->assertSame([], $this->unknownValues($look['apply_settings']), $id);
        }
    }

    public function test_admin_applies_every_look_and_old_id_without_unknown_option_values(): void
    {
        $this->actingAs($this->admin());
        $presets = AppearanceSettings::themePresets();

        foreach (array_merge(self::GALLERY, ['default_factory', 'youtube_2026_pro', 'cyberpunk_hud_2077']) as $requested) {
            $look = MasterExperienceCatalog::find($requested);

            $this->postJson(route('admin.settings.apply-experience'), ['experience_key' => $requested])
                ->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('experience_key', $look['id']);

            Setting::clearCache();
            $stored = Setting::getGroup('appearance');

            $this->assertSame($look['id'], $stored['active_master_experience'] ?? null, $requested);

            if (isset($presets[$look['theme']])) {
                $this->assertSame($look['theme'], $stored['theme_preset'] ?? null, $requested);
            }

            foreach (['home_layout_mode', 'watch_layout_mode', 'video_card_layout_style', 'modal_presentation_style', 'typography_pairing'] as $key) {
                $this->assertSame($look['apply_settings'][$key], $stored[$key] ?? null, "$requested.$key");
            }

            $this->assertSame([], $this->unknownValues($stored), $requested);
        }
    }

    public function test_unknown_look_ids_are_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.settings.apply-experience'), ['experience_key' => 'no_such_look'])->assertStatus(422);
        $this->assertDatabaseMissing('settings', ['key' => 'active_master_experience']);
    }

    /**
     * Settings whose key or value the settings layer does not know.
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function unknownValues(array $settings): array
    {
        $defaults = AppearanceSettings::defaults();
        $v1 = !array_key_exists('theme_flavor', $defaults);
        $booleans = AppearanceSettings::booleanKeys();
        $unknown = [];

        foreach ($settings as $key => $value) {
            $value = (string) $value;

            if (!array_key_exists($key, $defaults) && !($v1 && in_array($key, self::V2_KEYS, true))) {
                $unknown[] = "$key (key)";
                continue;
            }

            $list = self::OPTION_LISTS[$key] ?? null;
            $options = $list !== null && method_exists(AppearanceSettings::class, $list) ? array_keys(AppearanceSettings::{$list}()) : null;

            if ($list !== null && ($v1 || $options === null)) {
                $options = array_merge($options ?? [], self::V2_OPTIONS[$key] ?? []);
            }

            $known = match (true) {
                $options !== null && $options !== [] => in_array($value, $options, true),
                in_array($key, $booleans, true) => in_array($value, ['0', '1'], true),
                str_ends_with($key, '_color') => (bool) preg_match('/^#[0-9a-f]{6}$/i', $value),
                $key === 'theme' => in_array($value, ['light', 'dark', 'auto'], true),
                $key === 'theme_flavor' => in_array($value, DailyDesignCatalog::flavorIds(DailyDesignCatalog::baseTheme((string) ($settings['theme_preset'] ?? ''))), true),
                default => true,
            };

            if (!$known) {
                $unknown[] = "$key=$value";
            }
        }

        return $unknown;
    }

    private function admin(): User
    {
        return tap(new User(), function (User $user) {
            $user->forceFill([
                'username' => 'looks-admin',
                'slug' => 'looks-admin',
                'email' => 'looks-admin@example.test',
                'password' => 'password',
                'is_admin' => true,
                'email_verified_at' => now(),
            ]);
            $user->save();
        });
    }
}
