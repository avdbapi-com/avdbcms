<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;

class AppearanceSettings
{
    /** Stored rows carry this once the theme catalogue v2 upgrade ran (fresh installs ship with it). */
    public const string SCHEMA_VERSION = '2';

    public const int HOME_FEED_MIN_COLUMNS = 1;
    public const int HOME_FEED_MAX_COLUMNS = 6;
    public const int HOME_FEED_MIN_ROWS = 1;
    public const int HOME_FEED_MAX_ROWS = 12;
    public const int HOME_FEED_MAX_ITEMS = 96;
    public const int BASE_FONT_MIN_PX = 14;
    public const int BASE_FONT_MAX_PX = 20;
    public const int UI_FONT_SCALE_MIN = 90;
    public const int UI_FONT_SCALE_MAX = 115;
    public const int HEADING_FONT_SCALE_MIN = 90;
    public const int HEADING_FONT_SCALE_MAX = 130;
    public const int CARD_TITLE_FONT_SCALE_MIN = 90;
    public const int CARD_TITLE_FONT_SCALE_MAX = 125;

    /**
     * v1 factory values of the keys whose default changed in v2. An install that has not run the v2 upgrade yet
     * (no appearance_schema_version row) keeps rendering with these, so "Finish update" is what changes the look and
     * restoring the pre-upgrade snapshot brings the old look back.
     */
    private const LEGACY_DEFAULT_OVERRIDES = [
        'theme_preset' => 'cyberpunk_neon',
        'active_master_experience' => 'default_factory',
        'primary_color' => '#ff3d61',
        'brand_secondary_color' => '#ff8b5c',
        'accent_color' => '#18c8d8',
        'dark_bg_color' => '#070913',
        'dark_surface_color' => '#0e1322',
        'light_bg_color' => '#f8fafc',
        'light_surface_color' => '#ffffff',
        'daily_design_scope' => 'full',
        'scrollbar_style' => 'subtle',
        'watch_ambient_glow_intensity' => 'ambient',
        'home_layout_mode' => 'grid_modern',
        'hero_layout_mode' => 'snap_slider_carousel',
        'modal_presentation_style' => 'modal_glassmorphic_center',
    ];

    /** Settings config('site.*') reads as '' while they were never stored, whatever defaults() holds (footer_text). */
    private const EMPTY_WHEN_ABSENT = ['logo_url', 'favicon_url', 'footer_text', 'custom_css', 'custom_js', 'custom_head_html', 'announcement_banner'];

    /**
     * The factory footer line of the community test packages (example.sql and the 2026_05_05 rebrand migration), still
     * stored on sites installed before the rename to AVDBCMS: the footer and the Copyright line field show the AVDBCMS
     * line instead. Nothing is written; a save of the field stores what it shows.
     */
    private const RENAMED_FOOTER_TEXT = ['(c) 2026 AVDBClip. All rights reserved.' => '(c) 2026 AVDBCMS. All rights reserved.'];

    private static ?array $defaults = null;

    private static ?array $legacyDefaults = null;

    private static ?array $pairings = null;

    /** The 7 v1 pairings exactly as before (saved custom CSS may rely on their fallback families). */
    private const V1_TYPOGRAPHY_PAIRINGS = [
        'modern_tech' => [
            'label' => 'Modern Tech Sans: Outfit (Headings) + Plus Jakarta Sans (Body)',
            'heading_font' => "'Outfit', 'Plus Jakarta Sans', sans-serif",
            'body_font' => "'Plus Jakarta Sans', 'Outfit', sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=Outfit:wght@400..800&family=Plus+Jakarta+Sans:wght@400..800&display=swap',
        ],
        'editorial_luxury' => [
            'label' => 'Editorial Luxury Serif: Playfair Display (Headings) + Inter (Body)',
            'heading_font' => "'Playfair Display', Georgia, serif",
            'body_font' => "'Inter', -apple-system, sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Playfair+Display:ital,wght@0,500..900;1,500..900&display=swap',
        ],
        'cyberpunk_future' => [
            'label' => 'Cyber Sci-Fi: Orbitron (Headings) + Chakra Petch (Body)',
            'heading_font' => "'Orbitron', 'Rajdhani', sans-serif",
            'body_font' => "'Chakra Petch', 'Rajdhani', sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&family=Orbitron:wght@600..900&family=Rajdhani:wght@500;600;700&display=swap',
        ],
        'swiss_minimal' => [
            'label' => 'Swiss Minimal: Inter (Headings) + Roboto (Body)',
            'heading_font' => "'Inter', -apple-system, sans-serif",
            'body_font' => "'Roboto', 'Inter', sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400..800&family=Roboto:wght@400;500;700&display=swap',
        ],
        'playful_pop' => [
            'label' => 'Playful Anime: Poppins (Headings) + Quicksand (Body)',
            'heading_font' => "'Poppins', sans-serif",
            'body_font' => "'Quicksand', 'Poppins', sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;700;800&family=Quicksand:wght@500;600;700&display=swap',
        ],
        'hacker_mono' => [
            'label' => 'Hacker Dev: JetBrains Mono (Headings) + Space Grotesk (Body)',
            'heading_font' => "'JetBrains Mono', monospace",
            'body_font' => "'Space Grotesk', sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700;800&family=Space+Grotesk:wght@400;600;700&display=swap',
        ],
        'regal_gold' => [
            'label' => 'Regal Imperial: Cinzel (Headings) + Montserrat (Body)',
            'heading_font' => "'Cinzel', serif",
            'body_font' => "'Montserrat', sans-serif",
            'css_import' => 'https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;900&family=Montserrat:wght@400..700&display=swap',
        ],
    ];

    /**
     * v2 defaults: the youtube_spotlight_pro look (Midnight Spotlight, signature colour style, the look of a new site).
     */
    public static function defaults(): array
    {
        return self::$defaults ??= [
            'theme' => 'dark',
            'theme_preset' => 'tokyo_neon',
            'theme_flavor' => 'signature',
            'active_master_experience' => 'youtube_spotlight_pro',
            'primary_color' => '#ff0055',
            'brand_secondary_color' => '#7928ca',
            'accent_color' => '#00dfd8',
            'dark_bg_color' => '#0b0e15',
            'dark_surface_color' => '#12151e',
            'light_bg_color' => '#f5f6fa',
            'light_surface_color' => '#ffffff',
            'daily_design_auto_enabled' => '0',
            'daily_design_scope' => 'palette_only',
            'daily_design_show_badge' => '1',
            'daily_design_atmosphere_particles' => '1',
            'daily_design_override_day' => '0',
            'grid_columns' => '4',
            'surface_style' => 'glass',
            'radius_scale' => 'balanced',
            'motion_level' => 'full',
            'content_density' => 'comfortable',
            'mobile_video_columns' => '2',
            'mobile_video_card_density' => 'compact',
            'studio_video_preview_mode' => 'button',
            'enhanced_selects_enabled' => '1',
            'horizontal_rails_hide_scrollbars_enabled' => '1',
            'scroll_panel_hover_guard_enabled' => '1',
            'scrollbar_style' => 'brand',
            'upload_modal_style' => 'glass',
            'typography_controls_enabled' => '1',
            'font_family' => 'system',
            'base_font_size' => '16',
            'ui_font_scale' => '100',
            'heading_font_scale' => '100',
            'card_title_font_scale' => '100',
            'text_contrast' => 'normal',
            'logo_url' => '',
            'favicon_url' => '',
            'footer_text' => '(c) ' . date('Y') . ' AVDBCMS. All rights reserved.',
            'announcement_banner' => '',
            ...FooterSettings::defaults(),
            'custom_css' => '',
            'custom_js' => '',
            'custom_head_html' => '',
            'effects_enabled' => '1',
            'css_animations_enabled' => '1',
            'css_transitions_enabled' => '1',
            'css_hover_effects_enabled' => '1',
            'css_gradient_effects_enabled' => '1',
            'css_blur_effects_enabled' => '1',
            'css_shadow_effects_enabled' => '1',
            'css_sticky_header_enabled' => '1',
            'css_shimmer_effect_enabled' => '1',
            'headline_accent_enabled' => '1',
            'headline_accent_animation_enabled' => '1',
            'js_anime_effects_enabled' => '1',
            'js_theme_memory_enabled' => '1',
            'js_theater_memory_enabled' => '1',
            'js_smooth_scroll_enabled' => '1',
            'js_copy_toast_enabled' => '1',
            'js_lazy_comments_enabled' => '1',
            'header_search_enabled' => '1',
            'header_upload_button_enabled' => '1',
            'header_notifications_enabled' => '1',
            ...NavigationSettings::defaults(),
            'front_sidebar_pinned_enabled' => '1',
            'sidebar_playlists_enabled' => '1',
            'sidebar_subscriptions_enabled' => '1',
            'sidebar_explore_enabled' => '1',
            'sidebar_premium_link_enabled' => '1',
            'sidebar_locale_switcher_enabled' => '1',
            'sidebar_theme_switcher_enabled' => '1',
            'home_intro_enabled' => '1',
            'home_intro_kicker' => 'Fresh picks',
            'home_intro_title' => 'Watch what is moving now',
            'home_intro_subtitle' => 'A fast, clean feed for new videos, creators, playlists, and trending moments.',
            'home_category_tabs_enabled' => '1',
            'home_rail_links_enabled' => '1',
            'home_fallback_rails_enabled' => '1',
            'home_main_feed_enabled' => '1',
            'home_feed_source' => 'newest',
            'home_feed_category_id' => '0',
            'home_feed_columns' => '4',
            'home_feed_load_mode' => 'infinite',
            'home_widget_motion_enabled' => '1',
            'home_widget_motion_autorun_enabled' => '1',
            'home_widget_motion_card_cascade_enabled' => '1',
            'home_widget_motion_pause_on_hover_enabled' => '1',
            'home_widget_motion_style' => 'slide',
            'home_widget_motion_duration_ms' => '720',
            'home_widget_motion_distance_px' => '34',
            'home_widget_motion_stagger_ms' => '70',
            'home_widget_motion_speed_px_per_second' => '48',
            'home_widget_fresh_picks_motion_direction' => 'right',
            'home_widget_smart_discovery_motion_direction' => 'left',
            'home_widget_creator_spotlight_motion_direction' => 'right',
            'home_widget_trending_collections_motion_direction' => 'left',
            'watch_theater_button_enabled' => '1',
            'watch_keyboard_shortcuts_enabled' => '1',
            'watch_next_video_enabled' => '1',
            'watch_playlist_panel_enabled' => '1',
            'watch_related_videos_enabled' => '1',
            'watch_sidebar_ads_enabled' => '1',
            'watch_reactions_enabled' => '1',
            'watch_share_enabled' => '1',
            'watch_save_enabled' => '1',
            'watch_report_enabled' => '1',
            'watch_channel_panel_enabled' => '1',
            'watch_description_enabled' => '1',
            'watch_metadata_chips_enabled' => '1',
            'watch_comments_preview_enabled' => '1',
            'watch_sidebar_sticky_enabled' => '1',
            'watch_player_ambient_glow_enabled' => '1',
            'watch_action_panel_enabled' => '1',
            'watch_up_next_card_enabled' => '1',
            'watch_description_default_open' => '0',
            'watch_player_frame_style' => 'cinematic',
            'watch_sidebar_density' => 'comfortable',
            'secondary_page_design_enabled' => '1',
            'secondary_page_hero_enabled' => '1',
            'secondary_page_surfaces_enabled' => '1',
            'secondary_page_form_polish_enabled' => '1',
            'secondary_page_table_polish_enabled' => '1',
            'secondary_page_density' => 'comfortable',
            'secondary_page_max_width' => 'wide',
            'contact_illustration_enabled' => '1',
            'premium_comparison_enabled' => '1',
            'video_card_hover_enabled' => '1',
            'video_card_progress_enabled' => '1',
            'announcement_banner_enabled' => '1',
            'custom_code_enabled' => '1',
            'back_to_top_enabled' => '1',
            'theme_oled_black_enabled' => '0',
            'video_card_scrubbing_enabled' => '1',
            'video_card_duration_style' => 'pill',
            'video_card_hd_badge_enabled' => '1',
            'video_card_new_badge_days' => '3',
            'video_card_hover_glow_enabled' => '1',
            'video_card_title_lines' => '2',
            'watch_ambient_glow_intensity' => 'intense',
            'watch_theater_mode_default' => '0',
            'watch_autoplay_countdown_seconds' => '5',
            'watch_floating_mini_player_enabled' => '1',
            'watch_speed_controls_enabled' => '1',
            'header_style' => 'glass_blur',
            'sidebar_style' => 'full',
            'search_focus_glow_enabled' => '1',
            'header_category_chips_enabled' => '1',
            'header_random_button_enabled' => '1',
            'creator_avatar_ring_style' => 'neon_pulse',
            'creator_verified_badge_enabled' => '1',
            'category_card_style' => 'gradient_mesh',
            'category_video_count_pill_enabled' => '1',
            'mobile_bottom_nav_enabled' => '1',
            'mobile_tap_preview_enabled' => '1',
            'ui_click_ripple_enabled' => '1',
            'back_to_top_style' => 'floating_pill',
            'home_layout_mode' => 'youtube_spotlight',
            'hero_layout_mode' => 'live_ticker_stream',
            'hero_slider_autoplay_enabled' => '1',
            'hero_slider_autoplay_seconds' => '5',
            'mobile_bottom_dock_enabled' => '1',
            'watch_layout_mode' => 'classic_two_column',
            'video_card_layout_style' => 'card_standard',
            'modal_presentation_style' => 'modal_slide_sheet',
            'motion_engine_level' => 'smooth_fluid',
            'performance_tier_mode' => 'auto',
            'interactive_3d_card_tilt_enabled' => '1',
            'cinema_ambient_light_diffusion_enabled' => '1',
            'background_atmosphere_effect' => 'clean_none',
            'typography_pairing' => 'modern_tech',
            'video_player_cinema_filter' => 'none',
            'card_spotlight_glow_enabled' => '1',
            'border_beam_glow_enabled' => '1',
            'border_beam_speed' => 'normal',
            'surface_noise_grain_enabled' => '1',
            'ui_audio_feedback_enabled' => '0',
            'video_player_ambilight_glow_enabled' => '1',
            'surface_glassmorphism_enabled' => '1',
            'button_liquid_shimmer_enabled' => '1',
            'cursor_glow_trail_enabled' => '0',
            'daily_design_layouts_enabled' => '0',
            'brand_contrast_autofix' => '1',
            'theme_preset_legacy' => '',
            'suggested_master_upgrade' => '',
            'appearance_schema_version' => self::SCHEMA_VERSION,
        ];
    }

    public static function booleanKeys(): array
    {
        return [
            'effects_enabled',
            'css_animations_enabled',
            'css_transitions_enabled',
            'css_hover_effects_enabled',
            'css_gradient_effects_enabled',
            'css_blur_effects_enabled',
            'css_shadow_effects_enabled',
            'css_sticky_header_enabled',
            'css_shimmer_effect_enabled',
            'typography_controls_enabled',
            'enhanced_selects_enabled',
            'horizontal_rails_hide_scrollbars_enabled',
            'scroll_panel_hover_guard_enabled',
            'headline_accent_enabled',
            'headline_accent_animation_enabled',
            'js_anime_effects_enabled',
            'js_theme_memory_enabled',
            'js_theater_memory_enabled',
            'js_smooth_scroll_enabled',
            'js_copy_toast_enabled',
            'js_lazy_comments_enabled',
            'header_search_enabled',
            'header_upload_button_enabled',
            'header_notifications_enabled',
            ...NavigationSettings::booleanKeys(),
            'front_sidebar_pinned_enabled',
            'sidebar_playlists_enabled',
            'sidebar_subscriptions_enabled',
            'sidebar_explore_enabled',
            'sidebar_premium_link_enabled',
            'sidebar_locale_switcher_enabled',
            'sidebar_theme_switcher_enabled',
            'home_intro_enabled',
            'home_category_tabs_enabled',
            'home_rail_links_enabled',
            'home_fallback_rails_enabled',
            'home_main_feed_enabled',
            'home_widget_motion_enabled',
            'home_widget_motion_autorun_enabled',
            'home_widget_motion_card_cascade_enabled',
            'home_widget_motion_pause_on_hover_enabled',
            'watch_theater_button_enabled',
            'watch_keyboard_shortcuts_enabled',
            'watch_next_video_enabled',
            'watch_playlist_panel_enabled',
            'watch_related_videos_enabled',
            'watch_sidebar_ads_enabled',
            'watch_reactions_enabled',
            'watch_share_enabled',
            'watch_save_enabled',
            'watch_report_enabled',
            'watch_channel_panel_enabled',
            'watch_description_enabled',
            'watch_metadata_chips_enabled',
            'watch_comments_preview_enabled',
            'watch_sidebar_sticky_enabled',
            'watch_player_ambient_glow_enabled',
            'watch_action_panel_enabled',
            'watch_up_next_card_enabled',
            'watch_description_default_open',
            'secondary_page_design_enabled',
            'secondary_page_hero_enabled',
            'secondary_page_surfaces_enabled',
            'secondary_page_form_polish_enabled',
            'secondary_page_table_polish_enabled',
            'contact_illustration_enabled',
            'premium_comparison_enabled',
            'video_card_hover_enabled',
            'video_card_progress_enabled',
            'announcement_banner_enabled',
            'custom_code_enabled',
            'back_to_top_enabled',
            'theme_oled_black_enabled',
            'video_card_scrubbing_enabled',
            'video_card_hd_badge_enabled',
            'video_card_hover_glow_enabled',
            'watch_theater_mode_default',
            'watch_floating_mini_player_enabled',
            'watch_speed_controls_enabled',
            'search_focus_glow_enabled',
            'header_category_chips_enabled',
            'header_random_button_enabled',
            'creator_verified_badge_enabled',
            'category_video_count_pill_enabled',
            'mobile_bottom_nav_enabled',
            'mobile_tap_preview_enabled',
            'ui_click_ripple_enabled',
            'hero_slider_autoplay_enabled',
            'mobile_bottom_dock_enabled',
            'interactive_3d_card_tilt_enabled',
            'cinema_ambient_light_diffusion_enabled',
            'card_spotlight_glow_enabled',
            'border_beam_glow_enabled',
            'surface_noise_grain_enabled',
            'ui_audio_feedback_enabled',
            'video_player_ambilight_glow_enabled',
            'surface_glassmorphism_enabled',
            'button_liquid_shimmer_enabled',
            'cursor_glow_trail_enabled',
            'daily_design_auto_enabled',
            'daily_design_show_badge',
            'daily_design_atmosphere_particles',
            ...FooterSettings::booleanKeys(),
        ];
    }

    public static function textKeys(): array
    {
        return [
            'theme_preset',
            'active_master_experience',
            'home_intro_kicker',
            ...NavigationSettings::textKeys(),
            'home_intro_title',
            'home_intro_subtitle',
            'home_feed_source',
            'home_feed_category_id',
            'home_feed_columns',
            'home_feed_load_mode',
            'home_widget_motion_style',
            'home_widget_motion_duration_ms',
            'home_widget_motion_distance_px',
            'home_widget_motion_stagger_ms',
            'home_widget_motion_speed_px_per_second',
            'home_widget_fresh_picks_motion_direction',
            'home_widget_smart_discovery_motion_direction',
            'home_widget_creator_spotlight_motion_direction',
            'home_widget_trending_collections_motion_direction',
            'font_family',
            'base_font_size',
            'ui_font_scale',
            'heading_font_scale',
            'card_title_font_scale',
            'text_contrast',
            'mobile_video_columns',
            'mobile_video_card_density',
            'studio_video_preview_mode',
            'scrollbar_style',
            'upload_modal_style',
            'watch_player_frame_style',
            'watch_sidebar_density',
            'secondary_page_density',
            'secondary_page_max_width',
            'theme_preset',
            'video_card_duration_style',
            'video_card_new_badge_days',
            'video_card_title_lines',
            'watch_ambient_glow_intensity',
            'watch_autoplay_countdown_seconds',
            'header_style',
            'creator_avatar_ring_style',
            'category_card_style',
            'back_to_top_style',
            'home_layout_mode',
            'hero_layout_mode',
            'hero_slider_autoplay_seconds',
            'watch_layout_mode',
            'video_card_layout_style',
            'modal_presentation_style',
            'motion_engine_level',
            'performance_tier_mode',
            'background_atmosphere_effect',
            'typography_pairing',
            'video_player_cinema_filter',
            'border_beam_speed',
            'daily_design_scope',
            'daily_design_override_day',
            'dark_bg_color',
            'dark_surface_color',
            'light_bg_color',
            'light_surface_color',
            ...FooterSettings::textKeys(),
            'theme_flavor',
            'sidebar_style',
            'theme_preset_legacy',
            'suggested_master_upgrade',
            'appearance_schema_version',
        ];
    }

    /**
     * v2 switches. Kept out of booleanKeys() on purpose: the legacy PUT /admin/settings/update/appearance schema spreads
     * booleanKeys() and saves every listed switch that a request leaves out as '0'.
     */
    public static function v2BooleanKeys(): array
    {
        return [
            'daily_design_layouts_enabled',
            'brand_contrast_autofix',
        ];
    }

    /**
     * Keys added by the theme catalogue v2 (also listed in textKeys() / v2BooleanKeys()).
     */
    public static function v2TextKeys(): array
    {
        return [
            'theme_flavor',
            'sidebar_style',
            'theme_preset_legacy',
            'suggested_master_upgrade',
            'appearance_schema_version',
        ];
    }

    /**
     * The colour settings a theme's colour style supplies; a stored value that differs is the owner's custom colour.
     */
    public static function colourKeys(): array
    {
        return ThemeCatalog::COLOUR_KEYS;
    }

    /**
     * Every key a theme, a Complete look or a factory reset may write (AppearanceApplier writes nothing else, except
     * appearance_schema_version on a factory reset). Logo, texts, menus, ads, custom code and the effect switches are
     * never look keys.
     */
    public static function lookKeys(): array
    {
        return [
            'theme',
            'theme_preset',
            'theme_flavor',
            'active_master_experience',
            'suggested_master_upgrade',
            'daily_design_auto_enabled',
            ...ThemeCatalog::COLOUR_KEYS,
            'home_layout_mode',
            'hero_layout_mode',
            'hero_slider_autoplay_enabled',
            'hero_slider_autoplay_seconds',
            'watch_layout_mode',
            'video_card_layout_style',
            'header_style',
            'sidebar_style',
            'category_card_style',
            'creator_avatar_ring_style',
            'video_card_duration_style',
            'video_card_title_lines',
            'modal_presentation_style',
            'motion_engine_level',
            'typography_pairing',
            'font_family',
            'background_atmosphere_effect',
            'content_density',
            'grid_columns',
            'home_feed_columns',
            'mobile_video_columns',
            'mobile_video_card_density',
            'mobile_bottom_nav_enabled',
            'mobile_bottom_dock_enabled',
            'mobile_tap_preview_enabled',
            'interactive_3d_card_tilt_enabled',
            'cinema_ambient_light_diffusion_enabled',
            'watch_ambient_glow_intensity',
            'scrollbar_style',
            'surface_style',
            'radius_scale',
            'theme_oled_black_enabled',
            'back_to_top_style',
        ];
    }

    /**
     * The v1 factory values (frozen) plus the v2-only keys: what a settings array without appearance_schema_version
     * falls back to.
     */
    public static function legacyDefaults(): array
    {
        return self::$legacyDefaults ??= array_replace(self::defaults(), self::LEGACY_DEFAULT_OVERRIDES, ['appearance_schema_version' => '']);
    }

    /**
     * Defaults for absent keys of these stored settings: v2 once the upgrade ran (or on a fresh install), v1 before.
     */
    public static function fallbacks(array $settings): array
    {
        return static::isUpgraded($settings) ? static::defaults() : static::legacyDefaults();
    }

    public static function isUpgraded(array $settings): bool
    {
        return (int) ($settings['appearance_schema_version'] ?? 0) >= (int) self::SCHEMA_VERSION;
    }

    public static function homeFeedSources(): array
    {
        return [
            'newest' => 'Newest videos',
            'most_viewed' => 'Most viewed',
            'most_commented' => 'Most commented',
            'trending' => 'Trending',
            'category' => 'Selected category',
        ];
    }

    public static function homeFeedLoadModes(): array
    {
        return [
            'infinite' => 'Infinite scroll',
            'load_more' => 'Load more button',
            'pagination' => 'Pagination controls',
        ];
    }

    public static function fontFamilies(): array
    {
        return [
            'system' => [
                'label' => 'System Sans',
                'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            ],
            'interface' => [
                'label' => 'Interface Sans',
                'stack' => '"Inter", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            ],
            'modern' => [
                'label' => 'Modern Tech Sans',
                'stack' => '"Outfit", "Plus Jakarta Sans", "Segoe UI", sans-serif',
            ],
            'display' => [
                'label' => 'Bold Display',
                'stack' => '"Montserrat", "Poppins", "Segoe UI", sans-serif',
            ],
            'futuristic' => [
                'label' => 'Cyber Futuristic',
                'stack' => '"Rajdhani", "Orbitron", "Segoe UI", sans-serif',
            ],
            'rounded' => [
                'label' => 'Rounded UI',
                'stack' => '"Aptos Rounded", "Nunito", "Segoe UI", system-ui, sans-serif',
            ],
            'serif' => [
                'label' => 'Editorial Serif',
                'stack' => 'Georgia, "Times New Roman", Times, serif',
            ],
            'mono' => [
                'label' => 'Mono Tech',
                'stack' => '"Cascadia Code", "SFMono-Regular", Consolas, "Liberation Mono", monospace',
            ],
        ];
    }

    public static function textContrastLevels(): array
    {
        return [
            'soft' => 'Soft',
            'normal' => 'Normal',
            'strong' => 'Strong',
        ];
    }

    public static function mobileVideoCardDensities(): array
    {
        return [
            'comfortable' => 'Comfortable',
            'compact' => 'Compact',
        ];
    }

    public static function studioVideoPreviewModes(): array
    {
        return [
            'button' => 'Preview button',
            'inline' => 'Inline player',
        ];
    }

    public static function scrollbarStyles(): array
    {
        return [
            'subtle' => 'Subtle Glass',
            'glass' => 'Quiet Glass',
            'brand' => 'Brand Accent',
        ];
    }

    public static function uploadModalStyles(): array
    {
        return [
            'glass' => 'Quiet Glass',
            'subtle' => 'Subtle',
            'brand' => 'Brand Accent',
        ];
    }

    public static function themePresets(): array
    {
        return ThemeCatalog::all();
    }

    public static function videoCardDurationStyles(): array
    {
        return [
            'pill' => 'Frosted Glass Pill',
            'badge_solid' => 'Solid Dark Badge',
            'subtle' => 'Minimalist Subtle',
        ];
    }

    public static function categoryCardStyles(): array
    {
        return [
            'gradient_mesh' => 'Modern Gradient Mesh',
            'glass_card' => 'Frosted Glass Surface',
            'minimal_flat' => 'Minimalist Outline',
            'photo_overlay' => 'Dark Photo Overlay',
        ];
    }

    public static function creatorAvatarRingStyles(): array
    {
        return [
            'neon_pulse' => 'Animated Neon Pulse',
            'gradient_glow' => 'Luminescent Glow Ring',
            'static_ring' => 'Solid Accent Ring',
            'off' => 'Disabled (Clean Circle)',
        ];
    }

    /**
     * sidebar_rail is a retired value: it still validates (stored sites post it back) and renders as
     * glass_blur + sidebar_style rail.
     */
    public static function headerStyles(): array
    {
        return [
            'glass_blur' => 'Translucent bar',
            'glass_island' => 'Floating island',
            'minimal_slim' => 'Slim bar',
            'full_megabar' => 'Two-row bar',
            'sidebar_rail' => 'App rail (old option: now Translucent bar + side menu "Icon rail")',
            'solid_dark' => 'Solid bar',
            'transparent_overlay' => 'Over the banner (falls back to Solid bar on pages without a banner)',
        ];
    }

    public static function sidebarStyles(): array
    {
        return [
            'full' => 'Full side menu',
            'rail' => 'Icon rail',
            'floating' => 'Floating panel',
            'hidden' => 'No side menu (links in the header)',
        ];
    }

    public static function heroLayoutModes(): array
    {
        return [
            'snap_slider_carousel' => 'Interactive Snap Slider Carousel (Banner Slides with Blur Controls)',
            'bento_spotlight' => 'Bento Grid Spotlight (1 Featured Hero + 3 Dynamic Tiles)',
            'live_ticker_stream' => 'Live Multi-Column Ticker Feed (Trending & Hot Wins)',
            'classic_banner' => 'Classic Compact Headline & Category Pills',
            'editorial_lead' => 'Lead story + "Most watched" column',
            'off' => 'None (Direct Video Feed)',
        ];
    }

    public static function backToTopStyles(): array
    {
        return [
            'floating_pill' => 'Floating Pill with Label',
            'round_icon' => 'Circular Icon Button',
            'minimal_corner' => 'Subtle Minimalist Corner',
        ];
    }

    public static function ambientGlowIntensities(): array
    {
        return [
            'off' => 'Off (Clean Dark)',
            'soft' => 'Soft Ambient Glow',
            'ambient' => 'Cinema Glow (Balanced)',
            'intense' => 'Vibrant Neon Glow',
        ];
    }

    public static function homeLayoutModes(): array
    {
        return [
            'grid_modern' => 'Modern Responsive Grid (Standard)',
            'cinema_carousel' => 'Netflix Cinematic Streaming Hub (Billboard Hero + Shelf Rows)',
            'youtube_spotlight' => 'YouTube Spotlight Feed (Large Hero + Quick Info)',
            'bento_mosaic' => 'Apple / Swiss Bento Box (Asymmetric Dynamic Tiles)',
            'tiktok_shorts' => 'TikTok / Shorts Reels Immersion (Vertical Video Cards)',
            'cyberpunk_hud' => 'Cyberpunk Tactical Gamer HUD (Tech Telemetry & Brackets)',
        ];
    }

    public static function watchLayoutModes(): array
    {
        return [
            'classic_two_column' => 'YouTube Classic 70/30 (Player Left, Sticky Up Next Right)',
            'theater_cinema' => 'Full-Width Theater Cinema (Massive Player Top, Ambient Glow)',
            'floating_focus' => 'Distraction-Free Immersion (Centered Canvas, Glass Action Dock)',
            'studio_dashboard' => 'Creator / Streamer Studio Dashboard (Telemetry Box & Tabbed Sidebar)',
        ];
    }

    public static function videoCardLayoutStyles(): array
    {
        return [
            'card_standard' => 'Standard 16:9 Thumbnail with Metadata Below',
            'card_hover_expand' => 'Netflix Zoom Expand (Smooth Scale 1.08x with Quick Action)',
            'card_glass_overlay' => 'Modern Glassmorphic Overlay (Frosted Glass Base inside Thumb)',
            'card_horizontal_strip' => 'Horizontal Row Strip (List View with Metadata Right)',
            'card_bento_modern' => 'Bento Card (Rounded Tile with Gradient Rim Glow)',
        ];
    }

    public static function modalPresentationStyles(): array
    {
        return [
            'modal_glassmorphic_center' => 'Frosted Glassmorphic Center Modal',
            'modal_slide_sheet' => 'Modern Slide-in Drawer Sheet (Right on Desktop, Bottom on Mobile)',
            'modal_fullscreen_cinema' => 'Fullscreen Cinematic Lightbox',
            'modal_brutalist_sharp' => 'Brutalist High-Contrast Sharp Popover',
        ];
    }

    public static function motionEngineLevels(): array
    {
        return [
            'off' => 'Motion Off (Instant transitions)',
            'subtle_elegant' => 'Subtle & Elegant (Restrained micro-animations)',
            'smooth_fluid' => 'Smooth & Fluid 60fps (Balanced springs & fades)',
            'hyper_dynamic' => 'Hyper Dynamic Gamer (High-energy neon pulse & scale)',
        ];
    }

    /**
     * Device capability tier (html[data-avd-tier]). Auto lets the <head> tier script pick per
     * device before the first paint; any other value pins every visitor to that tier.
     */
    public static function performanceTierModes(): array
    {
        return [
            'auto' => 'Auto (detect each device)',
            'rich' => 'Rich (every effect on every device)',
            'balanced' => 'Balanced (same look, lighter motion and blur)',
            'lite' => 'Lite (static, lowest resource use)',
        ];
    }

    public static function backgroundAtmosphereEffects(): array
    {
        return [
            'clean_none' => 'Clean Solid / Minimal',
            'cosmic_particles' => 'Cosmic Floating Stardust Particles',
            'aurora_glow' => 'Drifting Aurora Ambient Mesh',
            'cyber_grid' => 'Cyberpunk Digital Matrix Grid',
            'mesh_nebula' => 'Mesh Nebula Ambient Gradient Diffusion',
            'solar_flare' => 'Solar Flare Amber Shimmer Dust',
            'quantum_matrix' => 'Quantum Matrix Digital Phosphor Rain',
        ];
    }

    public static function videoPlayerCinemaFilters(): array
    {
        return [
            'none' => 'Natural / Clean (Default)',
            'hdr_vibrant' => 'HDR Dynamic Contrast & Saturation Boost',
            'warm_35mm' => '35mm Analog Film Warmth',
            'cyber_glow' => 'Cyberpunk Specular Edge & Neon Contrast',
            'cool_scifi' => 'Deep Sci-Fi Cyan-Blue Grading',
            'monochrome_noir' => 'Classic High-Contrast Noir Black & White',
        ];
    }

    public static function borderBeamSpeeds(): array
    {
        return [
            'slow' => 'Slow & Elegant (8s orbit)',
            'normal' => 'Balanced & Fluid (5s orbit)',
            'fast' => 'Dynamic & Fast (3s orbit)',
        ];
    }

    /**
     * The 34 typography pairings: the 7 v1 pairings (unchanged label, stacks and css_import) and the 27 v2 pairings
     * of the theme catalogue. Every entry also names its display, body and label families (families = what a page
     * loads), the Vietnamese display swap (vi_display) and whether the pairing is legacy (kept valid, not offered).
     */
    public static function typographyPairings(): array
    {
        if (self::$pairings !== null) {
            return self::$pairings;
        }

        $pairings = [];

        foreach (ThemeCatalog::pairings() as $id => $pairing) {
            $families = array_values(array_unique(array_filter([$pairing['display'], $pairing['body'], $pairing['label_font'] ?? null])));
            $pairings[$id] = (self::V1_TYPOGRAPHY_PAIRINGS[$id] ?? [
                'label' => $pairing['label'] . ': ' . implode(' + ', array_unique([$pairing['display'], $pairing['body']])),
                'heading_font' => $pairing['heading_font'],
                'body_font' => $pairing['body_font'],
                'css_import' => (string) static::googleFontsUrl($families),
            ]) + [
                'display' => $pairing['display'],
                'body' => $pairing['body'],
                'label_font' => $pairing['label_font'] ?? $pairing['body'],
                'label_font_stack' => $pairing['label_font_stack'],
                'vi_display' => $pairing['vi_display'] ?? null,
                'legacy' => (bool) $pairing['legacy'],
                'families' => $families,
            ];
        }

        return self::$pairings = $pairings;
    }

    /**
     * Google Fonts families the v1 layout requests on back-office pages, with the axis ranges of the original
     * all-family stylesheet. The theme pairings' families and their trimmed specs are fontSpecs().
     */
    public static function googleFontFamilySpecs(): array
    {
        return [
            'Chakra Petch' => 'Chakra+Petch:wght@400;600;700',
            'Cinzel' => 'Cinzel:wght@600;700;900',
            'Inter' => 'Inter:wght@400..800',
            'JetBrains Mono' => 'JetBrains+Mono:wght@500;700;800',
            'Montserrat' => 'Montserrat:ital,wght@0,400..800;1,400..800',
            'Orbitron' => 'Orbitron:wght@600..900',
            'Outfit' => 'Outfit:wght@400..800',
            'Playfair Display' => 'Playfair+Display:ital,wght@0,500..900;1,500..900',
            'Plus Jakarta Sans' => 'Plus+Jakarta+Sans:wght@400..800',
            'Poppins' => 'Poppins:wght@400..700',
            'Quicksand' => 'Quicksand:wght@500;600;700',
            'Rajdhani' => 'Rajdhani:wght@500;600;700',
            'Roboto' => 'Roboto:wght@400;500;700',
            'Space Grotesk' => 'Space+Grotesk:wght@500;600;700',
            'Syne' => 'Syne:wght@600;700;800',
        ];
    }

    /**
     * css2 specs for every family a page may request: the theme catalogue's trimmed specs (43 families, only the
     * weights the themes use; fixes the static Poppins spec) plus the v1 families they do not cover.
     */
    public static function fontSpecs(): array
    {
        return ThemeCatalog::fontSpecs() + static::googleFontFamilySpecs();
    }

    /**
     * The Google Fonts families a public page renders with (perf plan step 22.3): the quoted families of the typography
     * pairing's heading and body stacks (the --avd-font-heading / --avd-font-body values), the quoted families of the
     * font_family preset while typography controls are on (--avd-font-family-base), and any family the owner's custom
     * code names.
     */
    public static function googleFontFamiliesFor(string $pairing, string $fontFamily, bool $typographyEnabled, string $customCode = ''): array
    {
        $specs = static::fontSpecs();
        $pairings = self::typographyPairings();
        $pairingConfig = $pairings[$pairing] ?? $pairings['modern_tech'];
        $families = [];

        foreach ([(string) ($pairingConfig['heading_font'] ?? ''), (string) ($pairingConfig['body_font'] ?? '')] as $stack) {
            foreach (self::quotedFamilies($stack) as $name) {
                if (isset($specs[$name])) {
                    $families[$name] = true;
                }
            }
        }

        if ($typographyEnabled) {
            // The font_family presets keep the v1 request: only the families of the original stylesheet.
            $v1Specs = static::googleFontFamilySpecs();

            foreach (self::quotedFamilies((string) (self::fontFamilies()[$fontFamily]['stack'] ?? '')) as $name) {
                if (isset($v1Specs[$name])) {
                    $families[$name] = true;
                }
            }
        }

        foreach (static::customCodeFamilies($customCode) as $name) {
            $families[$name] = true;
        }

        return array_keys($families);
    }

    /**
     * One css2 request for the given families (canonical order), or null when none is needed.
     */
    public static function googleFontsUrl(array $families): ?string
    {
        $parts = [];

        foreach (static::fontSpecs() as $name => $spec) {
            if (in_array($name, $families, true)) {
                $parts[] = 'family=' . $spec;
            }
        }

        return $parts === [] ? null : 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
    }

    /**
     * The v2 font request of a page: family => styles ('italic' / 'normal'). The pairing's display and body families
     * (+ its label font), only in the styles the active theme uses (e.g. Playfair italic only where the theme sets
     * italic titles), the Vietnamese display swap for vi pages, plus any family the owner's custom code names.
     * Every catalogue theme stays within 2 families / 4 files.
     *
     * @return array<string, array<int, string>>
     */
    public static function googleFontStylesFor(string $pairing, ?string $themeId = null, ?string $locale = null, string $customCode = ''): array
    {
        $pairings = ThemeCatalog::pairings();
        $type = ThemeCatalog::theme($themeId)['typography'] ?? [];
        $config = $pairings[$pairing] ?? $pairings[$type['pairing'] ?? ''] ?? $pairings['modern_tech'];
        $display = $config['display'];

        if ($locale !== null && str_starts_with(strtolower($locale), 'vi') && ! empty($config['vi_display'])) {
            $display = $config['vi_display'];
        }

        $titleStyle = (string) ($type['title_style'] ?? 'normal');
        $displayStyles = [(string) ($type['display_style'] ?? 'normal')];
        $bodyStyles = ['normal'];

        if (($type['title_font'] ?? 'body') === 'display') {
            $displayStyles[] = $titleStyle;
        } else {
            $bodyStyles[] = $titleStyle;
        }

        if (($type['kicker_font'] ?? 'body') === 'display') {
            $displayStyles[] = 'normal';
        }

        $families = [];
        $add = function (string $family, array $styles) use (&$families): void {
            $merged = array_values(array_unique(array_merge($families[$family] ?? [], $styles)));
            sort($merged);
            $families[$family] = $merged;
        };
        $add($display, $displayStyles);
        $add($config['body'], $bodyStyles);

        if (! empty($config['label_font'])) {
            $add($config['label_font'], ['normal']);
        }

        foreach (static::customCodeFamilies($customCode) as $name) {
            $add($name, ['italic', 'normal']);
        }

        return $families;
    }

    /**
     * One css2 request for googleFontStylesFor() output (each family trimmed to its styles), or null.
     */
    public static function googleFontsRequestUrl(array $familyStyles): ?string
    {
        $specs = static::fontSpecs();
        $parts = [];

        foreach ($familyStyles as $family => $styles) {
            if (isset($specs[$family])) {
                $parts[] = 'family=' . self::specForStyles($specs[$family], (array) $styles);
            }
        }

        return $parts === [] ? null : 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
    }

    /**
     * Families of fontSpecs() that the owner's custom CSS/JS/head/banner names (they must keep loading).
     *
     * @return array<int, string>
     */
    private static function customCodeFamilies(string $customCode): array
    {
        if (trim($customCode) === '') {
            return [];
        }

        $families = [];

        foreach (array_keys(static::fontSpecs()) as $name) {
            if (preg_match('/(?<![a-z0-9_-])' . preg_quote($name, '/') . '(?![a-z0-9_-])/i', $customCode)) {
                $families[] = $name;
            }
        }

        return $families;
    }

    /** @return array<int, string> */
    private static function quotedFamilies(string $stack): array
    {
        return preg_match_all('/[\'"]([^\'"]+)[\'"]/', $stack, $matches) ? $matches[1] : [];
    }

    /**
     * A css2 family spec reduced to the given styles: 'Playfair+Display:ital,wght@0,500..900;1,500..900' with
     * ['normal'] becomes 'Playfair+Display:wght@500..900'. Specs without an ital axis are returned unchanged.
     */
    private static function specForStyles(string $spec, array $styles): string
    {
        if (! str_contains($spec, ':') || ! str_contains($spec, '@')) {
            return $spec;
        }

        [$name, $rest] = explode(':', $spec, 2);
        [$axes, $tuples] = explode('@', $rest, 2);
        $axes = explode(',', $axes);
        $ital = array_search('ital', $axes, true);

        if ($ital === false) {
            return $spec;
        }

        $kept = [];

        foreach (explode(';', $tuples) as $tuple) {
            $parts = explode(',', $tuple);

            if (in_array(($parts[$ital] ?? '0') === '1' ? 'italic' : 'normal', $styles, true)) {
                $kept[] = $parts;
            }
        }

        if ($kept === [] || count($kept) === count(explode(';', $tuples))) {
            return $spec;
        }

        if (count($kept) === 1 && ($kept[0][$ital] ?? '0') === '0') {
            unset($axes[$ital], $kept[0][$ital]);

            return $name . ':' . implode(',', $axes) . '@' . implode(',', $kept[0]);
        }

        return $name . ':' . implode(',', $axes) . '@' . implode(';', array_map(fn (array $parts): string => implode(',', $parts), $kept));
    }

    public static function dailyDesignScopes(): array
    {
        return [
            'full' => 'Full Ecosystem Transformation (Layout + Colors + Atmosphere)',
            'palette_only' => 'Color Palette & Atmosphere Only (Preserve Home Layout)',
        ];
    }

    public static function isDailyDesignActive(array $settings): bool
    {
        if (!empty($settings['_theme_preview'])) {
            return false;
        }

        $reqDay = request()->query('preview_day') ?? request()->query('daily_design');
        if ($reqDay !== null && is_numeric($reqDay) && (int) $reqDay >= 1 && (int) $reqDay <= 366) {
            return true;
        }

        return static::bool($settings, 'daily_design_auto_enabled');
    }

    /**
     * The design this request renders: the stored settings, or for one request only an explicit preview
     * (?theme= / ?theme_preset= with a v2 or old id, ?theme_flavor=, &theme_mode= / &mode=) or the daily design.
     * Colour settings the owner did not customise come from the active colour style.
     */
    public static function resolveActiveDesign(array $settings): array
    {
        $previewKey = request()->query('theme', request()->query('theme_preset'));
        $preview = is_string($previewKey) ? static::presetValues($previewKey) : [];
        $flavorKey = request()->query('theme_flavor');
        $flavorKey = is_string($flavorKey) && $flavorKey !== '' ? $flavorKey : null;

        if ($preview !== []) {
            // Explicit preview changes the complete experience for this request
            // without persisting it or reapplying a saved preset over custom edits.
            $settings = array_replace($settings, $preview);
            $settings['_theme_preview'] = true;
            unset($settings['_active_daily_design'], $settings['_tomorrow_daily_design']);
        } elseif ($flavorKey === null || ThemeCatalog::flavor(static::resolveThemeFlavor($settings)['theme'], $flavorKey) === null) {
            $settings = static::resolveDailyDesign($settings);
        }

        if ($flavorKey !== null) {
            $active = static::resolveThemeFlavor($settings);

            if (ThemeCatalog::flavor($active['theme'], $flavorKey) !== null) {
                $settings = static::withFlavor($settings, $active['theme'], $flavorKey);
                $settings['_theme_preview'] = true;
                unset($settings['_active_daily_design'], $settings['_tomorrow_daily_design']);
            }
        }

        $mode = request()->query('theme_mode', request()->query('mode'));
        if (in_array($mode, ['light', 'dark'], true)) {
            $settings['theme'] = $mode;
        }

        return static::withThemeColours($settings);
    }

    /**
     * The active theme + colour style of these settings (no request input): theme_preset (absent -> the fallback
     * default) resolved alias-aware. A stored theme_flavor counts only when theme_preset is a v2 id: an old id fixes
     * its own colour style. Unknown ids fall back to the default theme.
     *
     * @return array{theme: string, flavor: string, legacy: bool, id: string}
     */
    public static function resolveThemeFlavor(array $settings): array
    {
        $fallback = static::fallbacks($settings)['theme_preset'];
        $resolved = ThemeCatalog::resolve((string) ($settings['theme_preset'] ?? $fallback))
            ?? ThemeCatalog::resolveOrDefault($fallback);
        $stored = $settings['theme_flavor'] ?? null;

        if (! $resolved['legacy'] && is_string($stored) && ThemeCatalog::flavor($resolved['theme'], $stored) !== null) {
            $resolved['flavor'] = $stored;
        }

        return $resolved;
    }

    /**
     * Completes resolved settings: the effective theme_flavor, colour settings the owner did not customise (absent or
     * invalid) from the colour style, the retired header_style sidebar_rail as glass_blur + sidebar_style rail, and
     * _theme_id / _theme_flavor / _theme_family / _custom_colours for the page head.
     *
     * _custom_colours is a map [setting key => the owner's colour], e.g. ['primary_color' => '#2563eb'] (the shape of
     * customColours()): one entry per colour setting whose valid '#rrggbb' value differs from the colour style's own
     * colour; [] when nothing is customised. It is not a list of keys.
     */
    public static function withThemeColours(array $settings): array
    {
        $active = static::resolveThemeFlavor($settings);
        $custom = [];

        foreach (ThemeCatalog::flavorColours($active['theme'], $active['flavor']) as $key => $colour) {
            $value = $settings[$key] ?? null;

            if (! is_string($value) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $settings[$key] = $colour;
            } elseif (strtolower($value) !== $colour) {
                $custom[$key] = $value;
            }
        }

        if (($settings['header_style'] ?? null) === 'sidebar_rail') {
            $settings['header_style'] = 'glass_blur';

            if (($settings['sidebar_style'] ?? 'full') === 'full') {
                $settings['sidebar_style'] = 'rail';
            }
        }

        $settings['theme_preset'] ??= static::fallbacks($settings)['theme_preset'];
        $settings['theme_flavor'] = $active['flavor'];
        $settings['_theme_id'] = $active['theme'];
        $settings['_theme_flavor'] = $active['flavor'];
        $settings['_theme_family'] = ThemeCatalog::theme($active['theme'])['family'] ?? '';
        $settings['_custom_colours'] = $custom;

        return $settings;
    }

    /**
     * What an edit form shows for these stored settings: the stored values, the fallback default of every absent key
     * (v1 before "Finish update") and, for colour settings the owner never set, the active colour style's colours. No
     * preview, daily design or draft is applied and retired values stay as stored, so a plain save keeps the look.
     */
    public static function formValues(array $stored): array
    {
        $values = array_replace(static::fallbacks($stored), array_fill_keys(self::EMPTY_WHEN_ABSENT, ''), $stored);
        $values['footer_text'] = static::footerText($values['footer_text']);
        $active = static::resolveThemeFlavor($stored);

        foreach (ThemeCatalog::flavorColours($active['theme'], $active['flavor']) as $key => $colour) {
            $value = $stored[$key] ?? null;

            if (! is_string($value) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $values[$key] = $colour;
            }
        }

        $values['theme_flavor'] = $active['flavor'];

        return $values;
    }

    /**
     * Stored colour settings that differ (case-insensitively) from the active colour style: the owner's custom
     * colours (ThemeTokens fits them for readability unless brand_contrast_autofix is off).
     *
     * @return array<string, string>
     */
    public static function customColours(array $settings): array
    {
        $active = static::resolveThemeFlavor($settings);
        $custom = [];

        foreach (ThemeCatalog::flavorColours($active['theme'], $active['flavor']) as $key => $colour) {
            $value = $settings[$key] ?? null;

            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) && strtolower($value) !== $colour) {
                $custom[$key] = $value;
            }
        }

        return $custom;
    }

    /**
     * Side menu style these settings render: sidebar_style, or 'rail' for the retired header_style sidebar_rail.
     */
    public static function effectiveSidebarStyle(array $settings): string
    {
        $style = (string) ($settings['sidebar_style'] ?? static::fallbacks($settings)['sidebar_style']);

        if (($settings['header_style'] ?? null) === 'sidebar_rail' && $style === 'full') {
            return 'rail';
        }

        return array_key_exists($style, static::sidebarStyles()) ? $style : 'full';
    }

    /** The same complete preset contract is used by preview and admin apply. */
    public static function presetValues(string $presetKey): array
    {
        $preset = ThemeCatalog::find($presetKey);
        if (!is_array($preset)) {
            return [];
        }

        $aliases = [
            'primary' => 'primary_color',
            'secondary' => 'brand_secondary_color',
            'accent' => 'accent_color',
        ];
        $known = static::defaults();
        $values = ['theme_preset' => $presetKey];
        foreach ($preset as $key => $value) {
            $target = $aliases[$key] ?? $key;
            if (array_key_exists($target, $known) && is_scalar($value)) {
                $values[$target] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * presetValues() of a v2 theme in one of its colour styles (theme_preset = the v2 id).
     */
    public static function themeValues(string $themeId, ?string $flavorId = null): array
    {
        $resolved = ThemeCatalog::resolve($themeId, $flavorId);

        if ($resolved === null) {
            return [];
        }

        return static::withFlavor(static::presetValues($resolved['theme']), $resolved['theme'], $resolved['flavor']);
    }

    /**
     * Draft overrides on top of stored settings: only known appearance keys; scalars become strings, null removes the
     * stored value (e.g. a colour back to the colour style's).
     */
    public static function applyOverrides(array $settings, array $overrides): array
    {
        $known = static::defaults();

        foreach ($overrides as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $known)) {
                continue;
            }

            if ($value === null) {
                unset($settings[$key]);
            } elseif (is_bool($value)) {
                $settings[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $settings[$key] = (string) $value;
            }
        }

        return $settings;
    }

    /**
     * Wire appearance settings into config('site.*') (extracted from AppServiceProvider::boot; the draft preview
     * middleware calls it again with the draft applied). Resolves previews and the daily design first unless
     * $resolve is false. Returns the resolved settings, which current() hands to the views of this request.
     */
    public static function applyToConfig(array $appearance, bool $resolve = true): array
    {
        $appearance = $resolve ? static::resolveActiveDesign($appearance) : static::withThemeColours($appearance);
        $fallback = static::fallbacks($appearance);

        Config::set('site.theme',                  $appearance['theme']                  ?? $fallback['theme']);
        Config::set('site.primary_color',          $appearance['primary_color']          ?? $fallback['primary_color']);
        Config::set('site.brand_secondary_color',  $appearance['brand_secondary_color']  ?? $fallback['brand_secondary_color']);
        Config::set('site.accent_color',           $appearance['accent_color']           ?? $fallback['accent_color']);
        Config::set('site.dark_bg_color',          $appearance['dark_bg_color']          ?? $fallback['dark_bg_color']);
        Config::set('site.dark_surface_color',     $appearance['dark_surface_color']     ?? $fallback['dark_surface_color']);
        Config::set('site.light_bg_color',         $appearance['light_bg_color']         ?? $fallback['light_bg_color']);
        Config::set('site.light_surface_color',    $appearance['light_surface_color']    ?? $fallback['light_surface_color']);
        Config::set('site.logo_url',               $appearance['logo_url']               ?? '');
        Config::set('site.favicon_url',            $appearance['favicon_url']            ?? '');
        Config::set('site.footer_text',            static::footerText($appearance['footer_text'] ?? ''));
        Config::set('site.grid_columns',           (int)($appearance['grid_columns']     ?? $fallback['grid_columns']));
        Config::set('site.surface_style',          $appearance['surface_style']          ?? $fallback['surface_style']);
        Config::set('site.radius_scale',           $appearance['radius_scale']           ?? $fallback['radius_scale']);
        Config::set('site.motion_level',           $appearance['motion_level']           ?? $fallback['motion_level']);
        Config::set('site.content_density',        $appearance['content_density']        ?? $fallback['content_density']);
        Config::set('site.custom_css',           $appearance['custom_css']           ?? '');
        Config::set('site.custom_js',            $appearance['custom_js']            ?? '');
        Config::set('site.custom_head_html',     $appearance['custom_head_html']     ?? '');
        Config::set('site.announcement_banner',  $appearance['announcement_banner']  ?? '');
        foreach (static::booleanKeys() as $key) {
            Config::set('site.' . $key, static::bool($appearance, $key));
        }
        foreach (static::textKeys() as $key) {
            Config::set('site.' . $key, static::text($appearance, $key));
        }
        foreach (NavigationSettings::defaults() as $key => $default) {
            Config::set('site.' . $key, $appearance[$key] ?? $default);
        }
        foreach (static::v2BooleanKeys() as $key) {
            Config::set('site.' . $key, static::bool($appearance, $key));
        }
        Config::set('site.sidebar_style',  static::effectiveSidebarStyle($appearance));
        Config::set('site.theme_id',       $appearance['_theme_id']);
        Config::set('site.theme_family',   $appearance['_theme_family']);
        Config::set('site.custom_colours', $appearance['_custom_colours']);

        app()->instance(self::class . '.current', ['request' => request(), 'appearance' => $appearance]);

        return $appearance;
    }

    /**
     * The resolved appearance of this request (what applyToConfig() applied, draft included), else the stored
     * settings resolved now. Bound to the request it was resolved for, so a later request in the same process (tests,
     * long-running workers) never sees another request's preview or stale settings.
     */
    public static function current(): array
    {
        $key = self::class . '.current';
        $request = request();
        $bound = app()->bound($key) ? app($key) : null;

        if (is_array($bound) && ($bound['request'] ?? null) === $request) {
            return $bound['appearance'];
        }

        $appearance = static::resolveActiveDesign(Setting::getGroup('appearance'));
        app()->instance($key, ['request' => $request, 'appearance' => $appearance]);

        return $appearance;
    }

    /**
     * Allowed values of every option setting (unknown stored values fall back to the theme's recommended value).
     *
     * @return array<string, array<int, string>>
     */
    public static function optionLists(): array
    {
        return [
            'theme' => ['light', 'dark', 'auto'],
            'surface_style' => ['glass', 'solid', 'contrast'],
            'radius_scale' => ['compact', 'balanced', 'rounded'],
            'motion_level' => ['full', 'reduced', 'off'],
            'content_density' => ['compact', 'comfortable', 'relaxed'],
            'font_family' => array_keys(static::fontFamilies()),
            'text_contrast' => array_keys(static::textContrastLevels()),
            'mobile_video_card_density' => array_keys(static::mobileVideoCardDensities()),
            'studio_video_preview_mode' => array_keys(static::studioVideoPreviewModes()),
            'scrollbar_style' => array_keys(static::scrollbarStyles()),
            'upload_modal_style' => array_keys(static::uploadModalStyles()),
            'video_card_duration_style' => array_keys(static::videoCardDurationStyles()),
            'category_card_style' => array_keys(static::categoryCardStyles()),
            'creator_avatar_ring_style' => array_keys(static::creatorAvatarRingStyles()),
            'header_style' => array_keys(static::headerStyles()),
            'sidebar_style' => array_keys(static::sidebarStyles()),
            'hero_layout_mode' => array_keys(static::heroLayoutModes()),
            'back_to_top_style' => array_keys(static::backToTopStyles()),
            'watch_ambient_glow_intensity' => array_keys(static::ambientGlowIntensities()),
            'home_layout_mode' => array_keys(static::homeLayoutModes()),
            'watch_layout_mode' => array_keys(static::watchLayoutModes()),
            'video_card_layout_style' => array_keys(static::videoCardLayoutStyles()),
            'modal_presentation_style' => array_keys(static::modalPresentationStyles()),
            'motion_engine_level' => array_keys(static::motionEngineLevels()),
            'performance_tier_mode' => array_keys(static::performanceTierModes()),
            'background_atmosphere_effect' => array_keys(static::backgroundAtmosphereEffects()),
            'typography_pairing' => array_keys(static::typographyPairings()),
            'video_player_cinema_filter' => array_keys(static::videoPlayerCinemaFilters()),
            'border_beam_speed' => array_keys(static::borderBeamSpeeds()),
            'daily_design_scope' => array_keys(static::dailyDesignScopes()),
        ];
    }

    /**
     * Settings switched to a colour style: theme_preset becomes the v2 id (an old id would fix its own style),
     * theme_flavor and the 7 colour settings follow the style.
     */
    private static function withFlavor(array $settings, string $themeId, string $flavorId): array
    {
        $settings['theme_preset'] = $themeId;
        $settings['theme_flavor'] = $flavorId;

        return array_replace($settings, ThemeCatalog::flavorColours($themeId, $flavorId));
    }

    private static function resolveDailyDesign(array $settings): array
    {
        if (!static::isDailyDesignActive($settings)) {
            return $settings;
        }

        $reqDay = request()->query('preview_day') ?? request()->query('daily_design');
        $overrideDay = (int) ($settings['daily_design_override_day'] ?? 0);

        if ($reqDay !== null && is_numeric($reqDay) && (int) $reqDay >= 1 && (int) $reqDay <= 366) {
            $day = (int) $reqDay;
        } elseif ($overrideDay > 0) {
            $day = $overrideDay;
        } else {
            $day = now()->dayOfYear;
        }

        $scope = static::text($settings, 'daily_design_scope');
        $ownTheme = static::resolveThemeFlavor($settings)['theme'];
        // The catalogue gets the in-request settings (draft or preview included), not only the stored row.
        $daily = DailyDesignCatalog::forDay($day, $scope, $settings);

        if (isset($daily['theme'])) {
            // v2 rule: palette_only keeps the owner's theme and only rotates its colour style; full may switch the
            // theme too, and the home layout only with daily_design_layouts_enabled.
            $theme = $scope === 'palette_only' ? $ownTheme : (string) $daily['theme'];
            $resolved = ThemeCatalog::resolve($theme, (string) ($daily['flavor'] ?? ''));

            if ($resolved !== null) {
                $settings = static::withFlavor($settings, $resolved['theme'], $resolved['flavor']);
            }

            if ($scope === 'full' && static::bool($settings, 'daily_design_layouts_enabled') && ! empty($daily['layout'])) {
                $layout = is_array($daily['layout']) ? $daily['layout'] : ['home_layout_mode' => $daily['layout']];

                foreach ($layout as $key => $value) {
                    if (in_array($key, static::lookKeys(), true) && is_scalar($value)) {
                        $settings[$key] = (string) $value;
                    }
                }
            }
        } else {
            $settings['primary_color'] = $daily['primary'];
            $settings['brand_secondary_color'] = $daily['secondary'];
            $settings['accent_color'] = $daily['accent'];
            $settings['surface_style'] = $daily['surface_style'];
            $settings['radius_scale'] = $daily['radius_scale'];
            $settings['font_family'] = $daily['font_family'];
            $settings['header_style'] = $daily['header_style'];
            $settings['category_card_style'] = $daily['category_card_style'];
            $settings['creator_avatar_ring_style'] = $daily['creator_avatar_ring_style'];
            $settings['video_card_duration_style'] = $daily['video_card_duration_style'];
            $settings['watch_ambient_glow_intensity'] = $daily['watch_ambient_glow_intensity'];
            $settings['scrollbar_style'] = $daily['scrollbar_style'];

            if ($scope === 'full') {
                $settings['home_layout_mode'] = $daily['home_layout_mode'];
            }
        }

        $settings['_active_daily_design'] = $daily;
        // The calendar day after it: 1 January of the next year after 31 December (day 365, or 366 in a leap year).
        $settings['_tomorrow_daily_design'] = DailyDesignCatalog::tomorrow($daily, $scope, $settings);

        return $settings;
    }

    public static function bool(array $settings, string $key): bool
    {
        return (string) ($settings[$key] ?? static::fallbacks($settings)[$key] ?? '0') === '1';
    }

    public static function text(array $settings, string $key): string
    {
        return (string) ($settings[$key] ?? static::fallbacks($settings)[$key] ?? '');
    }

    /**
     * The footer line shown for a stored footer_text: the old factory line becomes the AVDBCMS line
     * (RENAMED_FOOTER_TEXT), every other value stays exactly as stored.
     */
    public static function footerText(mixed $value): mixed
    {
        return is_string($value) ? (self::RENAMED_FOOTER_TEXT[trim($value)] ?? $value) : $value;
    }
}
