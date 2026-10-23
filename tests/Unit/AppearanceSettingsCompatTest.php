<?php

namespace Tests\Unit;

use App\Support\AppearanceSettings;
use App\Support\DailyDesignCatalog;
use App\Support\NavigationSettings;
use App\Support\ThemeCatalog;
use Illuminate\Http\Request;
use ReflectionClassConstant;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AppearanceSettings v2 keeps every v1 API the settings page, the public layout and AppServiceProvider use: the key
 * lists, the option lists (only ever growing), the font helpers, the config('site.*') keys and the v1 fallbacks of an
 * install that has not run the upgrade yet.
 */
class AppearanceSettingsCompatTest extends TestCase
{
    /** booleanKeys() as of v1: the legacy PUT /admin/settings/update/appearance schema spreads it, so it must not change. */
    private const V1_BOOLEAN_KEYS = [
        'effects_enabled', 'css_animations_enabled', 'css_transitions_enabled', 'css_hover_effects_enabled',
        'css_gradient_effects_enabled', 'css_blur_effects_enabled', 'css_shadow_effects_enabled',
        'css_sticky_header_enabled', 'css_shimmer_effect_enabled', 'typography_controls_enabled',
        'enhanced_selects_enabled', 'horizontal_rails_hide_scrollbars_enabled', 'scroll_panel_hover_guard_enabled',
        'headline_accent_enabled', 'headline_accent_animation_enabled', 'js_anime_effects_enabled',
        'js_theme_memory_enabled', 'js_theater_memory_enabled', 'js_smooth_scroll_enabled', 'js_copy_toast_enabled',
        'js_lazy_comments_enabled', 'header_search_enabled', 'header_upload_button_enabled',
        'header_notifications_enabled', 'header_premium_chip_enabled', 'header_login_button_enabled',
        'header_register_button_enabled', 'sidebar_static_menu_enabled', 'sidebar_discover_channels_enabled',
        'watch_related_heading_enabled', 'watch_related_show_channel_enabled', 'watch_related_show_meta_enabled',
        'front_sidebar_pinned_enabled', 'sidebar_playlists_enabled', 'sidebar_subscriptions_enabled',
        'sidebar_explore_enabled', 'sidebar_premium_link_enabled', 'sidebar_locale_switcher_enabled',
        'sidebar_theme_switcher_enabled', 'home_intro_enabled', 'home_category_tabs_enabled',
        'home_rail_links_enabled', 'home_fallback_rails_enabled', 'home_main_feed_enabled',
        'home_widget_motion_enabled', 'home_widget_motion_autorun_enabled',
        'home_widget_motion_card_cascade_enabled', 'home_widget_motion_pause_on_hover_enabled',
        'watch_theater_button_enabled', 'watch_keyboard_shortcuts_enabled', 'watch_next_video_enabled',
        'watch_playlist_panel_enabled', 'watch_related_videos_enabled', 'watch_sidebar_ads_enabled',
        'watch_reactions_enabled', 'watch_share_enabled', 'watch_save_enabled', 'watch_report_enabled',
        'watch_channel_panel_enabled', 'watch_description_enabled', 'watch_metadata_chips_enabled',
        'watch_comments_preview_enabled', 'watch_sidebar_sticky_enabled', 'watch_player_ambient_glow_enabled',
        'watch_action_panel_enabled', 'watch_up_next_card_enabled', 'watch_description_default_open',
        'secondary_page_design_enabled', 'secondary_page_hero_enabled', 'secondary_page_surfaces_enabled',
        'secondary_page_form_polish_enabled', 'secondary_page_table_polish_enabled', 'contact_illustration_enabled',
        'premium_comparison_enabled', 'video_card_hover_enabled', 'video_card_progress_enabled',
        'announcement_banner_enabled', 'custom_code_enabled', 'back_to_top_enabled', 'theme_oled_black_enabled',
        'video_card_scrubbing_enabled', 'video_card_hd_badge_enabled', 'video_card_hover_glow_enabled',
        'watch_theater_mode_default', 'watch_floating_mini_player_enabled', 'watch_speed_controls_enabled',
        'search_focus_glow_enabled', 'header_category_chips_enabled', 'header_random_button_enabled',
        'creator_verified_badge_enabled', 'category_video_count_pill_enabled', 'mobile_bottom_nav_enabled',
        'mobile_tap_preview_enabled', 'ui_click_ripple_enabled', 'hero_slider_autoplay_enabled',
        'mobile_bottom_dock_enabled', 'interactive_3d_card_tilt_enabled', 'cinema_ambient_light_diffusion_enabled',
        'card_spotlight_glow_enabled', 'border_beam_glow_enabled', 'surface_noise_grain_enabled',
        'ui_audio_feedback_enabled', 'video_player_ambilight_glow_enabled', 'surface_glassmorphism_enabled',
        'button_liquid_shimmer_enabled', 'cursor_glow_trail_enabled', 'daily_design_auto_enabled',
        'daily_design_show_badge', 'daily_design_atmosphere_particles', 'footer_brand_enabled',
        'footer_social_enabled', 'footer_explore_enabled', 'footer_categories_enabled', 'footer_support_enabled',
        'footer_settings_enabled', 'footer_language_switcher_enabled', 'footer_theme_switcher_enabled',
        'footer_bottom_links_enabled',
    ];

    private const V1_TEXT_KEYS = [
        'theme_preset', 'active_master_experience', 'home_intro_kicker', 'header_brand_text', 'header_premium_label',
        'header_upload_label', 'header_login_label', 'header_register_label', 'sidebar_primary_links',
        'sidebar_auth_links', 'sidebar_playlists_title', 'sidebar_playlists_limit', 'sidebar_subscriptions_title',
        'sidebar_subscriptions_limit', 'sidebar_discover_channels_label', 'sidebar_explore_title',
        'sidebar_explore_limit', 'sidebar_premium_label', 'sidebar_more_label', 'sidebar_less_label',
        'watch_related_title', 'watch_related_source', 'home_intro_title', 'home_intro_subtitle', 'home_feed_source',
        'home_feed_category_id', 'home_feed_columns', 'home_feed_load_mode', 'home_widget_motion_style',
        'home_widget_motion_duration_ms', 'home_widget_motion_distance_px', 'home_widget_motion_stagger_ms',
        'home_widget_motion_speed_px_per_second', 'home_widget_fresh_picks_motion_direction',
        'home_widget_smart_discovery_motion_direction', 'home_widget_creator_spotlight_motion_direction',
        'home_widget_trending_collections_motion_direction', 'font_family', 'base_font_size', 'ui_font_scale',
        'heading_font_scale', 'card_title_font_scale', 'text_contrast', 'mobile_video_columns',
        'mobile_video_card_density', 'studio_video_preview_mode', 'scrollbar_style', 'upload_modal_style',
        'watch_player_frame_style', 'watch_sidebar_density', 'secondary_page_density', 'secondary_page_max_width',
        'video_card_duration_style', 'video_card_new_badge_days', 'video_card_title_lines',
        'watch_ambient_glow_intensity', 'watch_autoplay_countdown_seconds', 'header_style',
        'creator_avatar_ring_style', 'category_card_style', 'back_to_top_style', 'home_layout_mode',
        'hero_layout_mode', 'hero_slider_autoplay_seconds', 'watch_layout_mode', 'video_card_layout_style',
        'modal_presentation_style', 'motion_engine_level', 'performance_tier_mode', 'background_atmosphere_effect',
        'typography_pairing', 'video_player_cinema_filter', 'border_beam_speed', 'daily_design_scope',
        'daily_design_override_day', 'dark_bg_color', 'dark_surface_color', 'light_bg_color', 'light_surface_color',
        'footer_brand_title', 'footer_tagline', 'footer_explore_title', 'footer_explore_links',
        'footer_categories_title', 'footer_categories_links', 'footer_categories_limit', 'footer_support_title',
        'footer_support_links', 'footer_settings_title', 'footer_bottom_links',
    ];

    /** Option lists the v1 page and the layout read, with their v1 keys (v2 may only add). */
    private const V1_OPTION_KEYS = [
        'homeFeedSources' => ['newest', 'most_viewed', 'most_commented', 'trending', 'category'],
        'homeFeedLoadModes' => ['infinite', 'load_more', 'pagination'],
        'fontFamilies' => ['system', 'interface', 'modern', 'display', 'futuristic', 'rounded', 'serif', 'mono'],
        'textContrastLevels' => ['soft', 'normal', 'strong'],
        'mobileVideoCardDensities' => ['comfortable', 'compact'],
        'studioVideoPreviewModes' => ['button', 'inline'],
        'scrollbarStyles' => ['subtle', 'glass', 'brand'],
        'uploadModalStyles' => ['glass', 'subtle', 'brand'],
        'videoCardDurationStyles' => ['pill', 'badge_solid', 'subtle'],
        'categoryCardStyles' => ['gradient_mesh', 'glass_card', 'minimal_flat', 'photo_overlay'],
        'creatorAvatarRingStyles' => ['neon_pulse', 'gradient_glow', 'static_ring', 'off'],
        'headerStyles' => ['glass_blur', 'glass_island', 'minimal_slim', 'full_megabar', 'sidebar_rail', 'solid_dark'],
        'heroLayoutModes' => ['snap_slider_carousel', 'bento_spotlight', 'live_ticker_stream', 'classic_banner', 'off'],
        'backToTopStyles' => ['floating_pill', 'round_icon', 'minimal_corner'],
        'ambientGlowIntensities' => ['off', 'soft', 'ambient', 'intense'],
        'homeLayoutModes' => ['grid_modern', 'cinema_carousel', 'youtube_spotlight', 'bento_mosaic', 'tiktok_shorts', 'cyberpunk_hud'],
        'watchLayoutModes' => ['classic_two_column', 'theater_cinema', 'floating_focus', 'studio_dashboard'],
        'videoCardLayoutStyles' => ['card_standard', 'card_hover_expand', 'card_glass_overlay', 'card_horizontal_strip', 'card_bento_modern'],
        'modalPresentationStyles' => ['modal_glassmorphic_center', 'modal_slide_sheet', 'modal_fullscreen_cinema', 'modal_brutalist_sharp'],
        'motionEngineLevels' => ['off', 'subtle_elegant', 'smooth_fluid', 'hyper_dynamic'],
        'performanceTierModes' => ['auto', 'rich', 'balanced', 'lite'],
        'backgroundAtmosphereEffects' => ['clean_none', 'cosmic_particles', 'aurora_glow', 'cyber_grid', 'mesh_nebula', 'solar_flare', 'quantum_matrix'],
        'videoPlayerCinemaFilters' => ['none', 'hdr_vibrant', 'warm_35mm', 'cyber_glow', 'cool_scifi', 'monochrome_noir'],
        'borderBeamSpeeds' => ['slow', 'normal', 'fast'],
        'typographyPairings' => ['modern_tech', 'editorial_luxury', 'cyberpunk_future', 'swiss_minimal', 'playful_pop', 'hacker_mono', 'regal_gold'],
        'dailyDesignScopes' => ['full', 'palette_only'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('request', Request::create('/'));
    }

    public function test_key_lists_keep_their_v1_content(): void
    {
        $this->assertSame(self::V1_BOOLEAN_KEYS, AppearanceSettings::booleanKeys(), 'booleanKeys() must not change (legacy endpoint)');
        $this->assertSame([], array_values(array_diff(self::V1_TEXT_KEYS, AppearanceSettings::textKeys())));
        $this->assertSame(AppearanceSettings::v2TextKeys(), array_values(array_diff(array_unique(AppearanceSettings::textKeys()), self::V1_TEXT_KEYS)));
        $this->assertSame([], array_intersect(AppearanceSettings::v2BooleanKeys(), AppearanceSettings::booleanKeys()));
        $this->assertSame(ThemeCatalog::COLOUR_KEYS, AppearanceSettings::colourKeys());

        $defaults = AppearanceSettings::defaults();

        foreach ([...AppearanceSettings::booleanKeys(), ...AppearanceSettings::v2BooleanKeys()] as $key) {
            $this->assertContains($defaults[$key] ?? null, ['0', '1'], $key);
        }

        foreach ([...AppearanceSettings::textKeys(), ...AppearanceSettings::lookKeys(), ...ThemeCatalog::COLOUR_KEYS] as $key) {
            $this->assertArrayHasKey($key, $defaults, $key);
        }
    }

    public function test_v2_defaults_are_the_demo1_look_and_theme_preset_appears_once(): void
    {
        $defaults = AppearanceSettings::defaults();
        $recommended = ThemeCatalog::recommendedSettings('tokyo_neon', 'signature');

        foreach ($recommended as $key => $value) {
            $this->assertSame($value, $defaults[$key], "default {$key}");
        }

        $this->assertSame('youtube_spotlight_pro', $defaults['active_master_experience']);
        $this->assertSame('palette_only', $defaults['daily_design_scope']);
        $this->assertSame(['signature', 'full', '0', '1', '', '2', ''], [
            $defaults['theme_flavor'], $defaults['sidebar_style'], $defaults['daily_design_layouts_enabled'],
            $defaults['brand_contrast_autofix'], $defaults['theme_preset_legacy'], $defaults['appearance_schema_version'],
            $defaults['suggested_master_upgrade'],
        ]);

        $method = new ReflectionMethod(AppearanceSettings::class, 'defaults');
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $this->assertSame(1, substr_count($source, "'theme_preset' =>"), 'G19: theme_preset appears once in defaults()');
    }

    public function test_installs_without_the_upgrade_fall_back_to_the_frozen_v1_defaults(): void
    {
        $migration = require database_path('migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php');
        $old = json_decode((new ReflectionClassConstant($migration, 'FACTORY_SOURCES'))->getValue(), true)['old_defaults'];
        $legacy = AppearanceSettings::legacyDefaults();

        foreach ($old as $key => $value) {
            $expected = $key === 'footer_text' ? '(c) ' . date('Y') . ' AVDBCMS. All rights reserved.' : (string) $value;
            $this->assertSame($expected, $legacy[$key] ?? null, "v1 default {$key}");
        }

        $this->assertSame($legacy, AppearanceSettings::fallbacks([]));
        $this->assertSame($legacy, AppearanceSettings::fallbacks(['appearance_schema_version' => '1']));
        $this->assertSame(AppearanceSettings::defaults(), AppearanceSettings::fallbacks(['appearance_schema_version' => '2']));
        $this->assertSame('grid_modern', AppearanceSettings::text([], 'home_layout_mode'));
        $this->assertSame('youtube_spotlight', AppearanceSettings::text(['appearance_schema_version' => '2'], 'home_layout_mode'));
        $this->assertTrue(AppearanceSettings::bool([], 'brand_contrast_autofix'));
        $this->assertFalse(AppearanceSettings::bool([], 'daily_design_layouts_enabled'));
    }

    public function test_every_v1_option_list_still_offers_its_v1_values(): void
    {
        foreach (self::V1_OPTION_KEYS as $method => $keys) {
            $options = AppearanceSettings::{$method}();
            $this->assertSame([], array_values(array_diff($keys, array_keys($options))), "{$method} lost a v1 value");

            foreach ($options as $key => $option) {
                $this->assertIsString($key);
                $this->assertTrue(is_string($option) || is_array($option), "{$method}.{$key}");
            }
        }

        $this->assertArrayHasKey('transparent_overlay', AppearanceSettings::headerStyles());
        $this->assertArrayHasKey('editorial_lead', AppearanceSettings::heroLayoutModes());
        $this->assertSame(['full', 'rail', 'floating', 'hidden'], array_keys(AppearanceSettings::sidebarStyles()));
        $this->assertCount(34, AppearanceSettings::typographyPairings());
        $this->assertSame(self::V1_OPTION_KEYS['typographyPairings'], array_slice(array_keys(AppearanceSettings::typographyPairings()), 0, 7));

        foreach (AppearanceSettings::typographyPairings() as $id => $pairing) {
            foreach (['label', 'heading_font', 'body_font', 'css_import'] as $key) {
                $this->assertNotSame('', (string) ($pairing[$key] ?? ''), "{$id}.{$key}");
            }
        }

        $this->assertSame("'Orbitron', 'Rajdhani', sans-serif", AppearanceSettings::typographyPairings()['cyberpunk_future']['heading_font']);
        $this->assertTrue(AppearanceSettings::typographyPairings()['cyberpunk_future']['legacy']);
        $this->assertSame("'Geist', system-ui, 'Segoe UI', Roboto, sans-serif", AppearanceSettings::typographyPairings()['geist_clean']['heading_font']);
        $this->assertCount(15, AppearanceSettings::googleFontFamilySpecs());
        $this->assertSame([1, 6, 1, 12, 96, 14, 20, 90, 115, 90, 130, 90, 125], [
            AppearanceSettings::HOME_FEED_MIN_COLUMNS, AppearanceSettings::HOME_FEED_MAX_COLUMNS, AppearanceSettings::HOME_FEED_MIN_ROWS,
            AppearanceSettings::HOME_FEED_MAX_ROWS, AppearanceSettings::HOME_FEED_MAX_ITEMS,
            AppearanceSettings::BASE_FONT_MIN_PX, AppearanceSettings::BASE_FONT_MAX_PX, AppearanceSettings::UI_FONT_SCALE_MIN,
            AppearanceSettings::UI_FONT_SCALE_MAX, AppearanceSettings::HEADING_FONT_SCALE_MIN, AppearanceSettings::HEADING_FONT_SCALE_MAX,
            AppearanceSettings::CARD_TITLE_FONT_SCALE_MIN, AppearanceSettings::CARD_TITLE_FONT_SCALE_MAX,
        ]);

        foreach (AppearanceSettings::optionLists() as $key => $allowed) {
            $this->assertNotEmpty($allowed, $key);
            $this->assertContains((string) AppearanceSettings::defaults()[$key], $allowed, "default {$key}");
        }
    }

    public function test_font_requests_stay_within_the_budget_and_v1_helpers_still_work(): void
    {
        $this->assertSame(['Outfit', 'Plus Jakarta Sans'], AppearanceSettings::googleFontFamiliesFor('modern_tech', 'system', true, ''));
        $this->assertSame(['Orbitron', 'Rajdhani', 'Chakra Petch'], AppearanceSettings::googleFontFamiliesFor('cyberpunk_future', 'system', false, ''));
        $this->assertSame(['Geist', 'Lexend'], AppearanceSettings::googleFontFamiliesFor('geist_clean', 'system', false, 'h1 { font-family: "Lexend"; }'));
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Outfit:wght@400..800&family=Plus+Jakarta+Sans:wght@400..800&display=swap',
            AppearanceSettings::googleFontsUrl(['Plus Jakarta Sans', 'Outfit']),
        );
        $this->assertNull(AppearanceSettings::googleFontsUrl([]));

        foreach (ThemeCatalog::themes() as $id => $theme) {
            $families = AppearanceSettings::googleFontStylesFor($theme['typography']['pairing'], $id, 'en');
            $this->assertLessThanOrEqual(2, count($families), "{$id}: at most 2 font families");
            $this->assertStringStartsWith('https://fonts.googleapis.com/css2?family=', (string) AppearanceSettings::googleFontsRequestUrl($families), $id);
        }

        // Playfair italic only where the theme sets italic display type
        $this->assertSame(['Playfair Display' => ['italic'], 'Inter' => ['normal']], AppearanceSettings::googleFontStylesFor('editorial_luxury', 'silver_screen', 'en'));
        $this->assertStringContainsString('family=Playfair+Display:ital,wght@1,500..900&', AppearanceSettings::googleFontsRequestUrl(AppearanceSettings::googleFontStylesFor('editorial_luxury', 'silver_screen')));
        $this->assertStringContainsString('family=Playfair+Display:wght@500..900&', AppearanceSettings::googleFontsRequestUrl(['Playfair Display' => ['normal']]) . '&');
        // Vietnamese pages swap display faces without the Vietnamese subset
        $this->assertSame(['Plus Jakarta Sans' => ['normal']], AppearanceSettings::googleFontStylesFor('modern_tech', 'tokyo_neon', 'vi'));
        $this->assertArrayHasKey('Outfit', AppearanceSettings::googleFontStylesFor('modern_tech', 'tokyo_neon', 'en'));
    }

    public function test_apply_to_config_sets_every_v1_key_with_version_aware_fallbacks(): void
    {
        $resolved = AppearanceSettings::applyToConfig([]);

        $this->assertSame($resolved, AppearanceSettings::current());
        $this->assertSame(['dark', '#ff3d61', '#ff8b5c', '#18c8d8', 4, 'glass', 'balanced', 'full', 'comfortable', '', ''], [
            config('site.theme'), config('site.primary_color'), config('site.brand_secondary_color'), config('site.accent_color'),
            config('site.grid_columns'), config('site.surface_style'), config('site.radius_scale'), config('site.motion_level'),
            config('site.content_density'), config('site.footer_text'), config('site.custom_css'),
        ]);
        $this->assertSame(['cyberpunk_neon', 'coral', 'tokyo_neon', 'studio', 'grid_modern', 'full'], [
            config('site.theme_preset'), config('site.theme_flavor'), config('site.theme_id'), config('site.theme_family'),
            config('site.home_layout_mode'), config('site.sidebar_style'),
        ]);

        // as in v1, the navigation keys are set last with their raw stored value
        foreach (array_diff(AppearanceSettings::booleanKeys(), array_keys(NavigationSettings::defaults())) as $key) {
            $this->assertIsBool(config('site.' . $key), $key);
        }

        foreach (AppearanceSettings::textKeys() as $key) {
            $this->assertIsString(config('site.' . $key), $key);
        }

        foreach (array_keys(NavigationSettings::defaults()) as $key) {
            $this->assertNotNull(config('site.' . $key), $key);
        }

        AppearanceSettings::applyToConfig(['appearance_schema_version' => '2', 'header_style' => 'sidebar_rail', 'accent_color' => '#ABCDEF']);
        $this->assertSame(['tokyo_neon', 'signature', '#ff0055', '#ABCDEF', 'youtube_spotlight', 'glass_blur', 'rail'], [
            config('site.theme_preset'), config('site.theme_flavor'), config('site.primary_color'), config('site.accent_color'),
            config('site.home_layout_mode'), config('site.header_style'), config('site.sidebar_style'),
        ]);
        $this->assertSame(['accent_color' => '#ABCDEF'], config('site.custom_colours'));
        $this->assertTrue(config('site.brand_contrast_autofix'));
        $this->assertFalse(config('site.daily_design_layouts_enabled'));
    }

    public function test_overrides_and_previews_change_one_request_only(): void
    {
        $stored = ['appearance_schema_version' => '2', 'theme_preset' => 'broadsheet', 'logo_url' => '/logo.svg'];
        $merged = AppearanceSettings::applyOverrides($stored, [
            'theme_flavor' => 'navy', 'not_a_setting' => 'x', 'logo_url' => null, 'brand_contrast_autofix' => false, 'grid_columns' => 5,
        ]);

        $this->assertSame(['appearance_schema_version' => '2', 'theme_preset' => 'broadsheet', 'theme_flavor' => 'navy', 'brand_contrast_autofix' => '0', 'grid_columns' => '5'], $merged);
        $this->assertSame('navy', AppearanceSettings::resolveThemeFlavor($merged)['flavor']);
        $this->assertSame('signature', AppearanceSettings::resolveThemeFlavor(['theme_preset' => 'broadsheet', 'theme_flavor' => 'not_a_style'])['flavor']);
        // an old id fixes its own colour style; a stored theme_flavor only counts for v2 ids
        $this->assertSame('coral', AppearanceSettings::resolveThemeFlavor(['theme_preset' => 'cyberpunk_neon', 'theme_flavor' => 'volt'])['flavor']);
        $this->assertSame(['tokyo_neon', 'signature'], array_values(array_slice(AppearanceSettings::resolveThemeFlavor(['appearance_schema_version' => '2', 'theme_preset' => 'gone']), 0, 2)));

        $this->app->instance('request', Request::create('/?theme=matcha_zen&mode=dark'));
        $preview = AppearanceSettings::resolveActiveDesign($stored);
        $this->assertSame(['matcha_zen', 'dark', '/logo.svg'], [$preview['_theme_id'], $preview['theme'], $preview['logo_url']]);
        $this->assertSame(AppearanceSettings::themeValues('matcha_zen')['home_layout_mode'], $preview['home_layout_mode']);

        $this->assertSame('rail', AppearanceSettings::effectiveSidebarStyle(['header_style' => 'sidebar_rail']));
        $this->assertSame('floating', AppearanceSettings::effectiveSidebarStyle(['header_style' => 'sidebar_rail', 'sidebar_style' => 'floating']));
        $this->assertSame('full', AppearanceSettings::effectiveSidebarStyle(['sidebar_style' => 'sideways']));
    }

    public function test_form_values_show_what_the_site_renders(): void
    {
        // Before the upgrade: v1 fallbacks, and the old id's colour style for colours never stored (or invalid).
        $legacy = AppearanceSettings::formValues(['primary_color' => '#ABCDEF', 'accent_color' => 'not a colour', 'header_style' => 'sidebar_rail']);
        $alias = ThemeCatalog::resolve('cyberpunk_neon');
        $style = ThemeCatalog::flavorColours($alias['theme'], $alias['flavor']);

        $this->assertSame(['cyberpunk_neon', $alias['flavor'], 'grid_modern', 'modal_glassmorphic_center', 'full'], [
            $legacy['theme_preset'], $legacy['theme_flavor'], $legacy['home_layout_mode'], $legacy['modal_presentation_style'], $legacy['daily_design_scope'],
        ]);
        $this->assertSame('#ABCDEF', $legacy['primary_color'], 'a stored colour shows as stored');
        $this->assertSame([$style['accent_color'], $style['dark_bg_color']], [$legacy['accent_color'], $legacy['dark_bg_color']]);
        $this->assertSame('sidebar_rail', $legacy['header_style'], 'a retired value stays as stored, so a save keeps the rail');
        $this->assertArrayNotHasKey('_theme_id', $legacy, 'no preview or daily design data');
        $this->assertSame(['', ''], [$legacy['footer_text'], $legacy['logo_url']], 'never stored renders the footer\'s own line: the form stays empty');
        $this->assertSame('Mine', AppearanceSettings::formValues(['footer_text' => 'Mine'])['footer_text']);

        // After the upgrade: v2 defaults and the stored theme + colour style.
        $upgraded = AppearanceSettings::formValues(['appearance_schema_version' => '2', 'theme_preset' => 'broadsheet', 'theme_flavor' => 'navy']);
        $this->assertSame(ThemeCatalog::flavorColours('broadsheet', 'navy'), array_map(fn (string $key): string => $upgraded[$key], array_combine(ThemeCatalog::COLOUR_KEYS, ThemeCatalog::COLOUR_KEYS)));
        $this->assertSame(['broadsheet', 'navy', 'palette_only', 'youtube_spotlight'], [$upgraded['theme_preset'], $upgraded['theme_flavor'], $upgraded['daily_design_scope'], $upgraded['home_layout_mode']]);
        $this->assertSame($upgraded, AppearanceSettings::formValues($upgraded), 'form values of form values are the same');

        // With a ?theme= preview in the request the form still shows the stored settings.
        $this->app->instance('request', Request::create('/?theme=matcha_zen&daily_design=40'));
        $this->assertSame($upgraded, AppearanceSettings::formValues(['appearance_schema_version' => '2', 'theme_preset' => 'broadsheet', 'theme_flavor' => 'navy']));
    }

    public function test_current_belongs_to_the_request_it_was_resolved_for(): void
    {
        $this->app->instance('request', Request::create('/?theme=matcha_zen'));
        $applied = AppearanceSettings::applyToConfig(['appearance_schema_version' => '2', 'theme_preset' => 'broadsheet']);

        $this->assertSame('matcha_zen', $applied['_theme_id']);
        $this->assertSame($applied, AppearanceSettings::current());

        // The next request in the same process (tests, long-running workers) resolves its own design.
        $this->app->instance('request', Request::create('/?theme=daylight_paper'));
        $this->assertSame('daylight_paper', AppearanceSettings::current()['_theme_id']);
        $this->assertSame(AppearanceSettings::current(), AppearanceSettings::current());
    }

    public function test_daily_designs_apply_for_the_day_and_never_over_a_preview(): void
    {
        $this->app->instance('request', Request::create('/?daily_design=120'));
        $settings = ['appearance_schema_version' => '2', 'theme_preset' => 'tokyo_neon', 'daily_design_scope' => 'palette_only'];
        $resolved = AppearanceSettings::resolveActiveDesign($settings);
        $daily = DailyDesignCatalog::forDay(120, 'palette_only', $settings);

        $this->assertTrue(AppearanceSettings::isDailyDesignActive($resolved));
        $this->assertSame($daily, $resolved['_active_daily_design']);
        $this->assertArrayHasKey('_tomorrow_daily_design', $resolved);

        if (isset($daily['theme'])) {
            $this->assertSame('tokyo_neon', $resolved['_theme_id'], 'palette_only keeps the owner theme');
            $this->assertSame([], $resolved['_custom_colours']);
        } else {
            $this->assertSame($daily['primary'], $resolved['primary_color']);
        }

        $this->app->instance('request', Request::create('/?daily_design=120&theme=broadsheet'));
        $resolved = AppearanceSettings::resolveActiveDesign($settings);
        $this->assertSame('broadsheet', $resolved['_theme_id']);
        $this->assertArrayNotHasKey('_active_daily_design', $resolved);
    }
}
