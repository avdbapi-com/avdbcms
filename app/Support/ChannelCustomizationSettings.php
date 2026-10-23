<?php

namespace App\Support;

use Illuminate\Support\Arr;

class ChannelCustomizationSettings
{
    public static function defaults(): array
    {
        return [
            'channel_customization_enabled' => '1',
            'channel_customization_layout_enabled' => '1',
            'channel_customization_theme_enabled' => '1',
            'channel_customization_accent_enabled' => '1',
            'channel_customization_custom_accent_enabled' => '1',
            'channel_customization_surface_enabled' => '1',
            'channel_customization_banner_enabled' => '1',
            'channel_customization_avatar_enabled' => '1',
            'channel_customization_motion_enabled' => '1',
            'channel_customization_effects_enabled' => '1',
            'channel_customization_pattern_enabled' => '1',
            'channel_customization_shape_enabled' => '1',
            'channel_customization_video_layout_enabled' => '1',
            'channel_customization_featured_video_enabled' => '1',
            'channel_customization_featured_layout_enabled' => '1',
            'channel_customization_featured_sizing_enabled' => '1',
            'channel_customization_latest_uploads_enabled' => '1',
            'channel_customization_latest_layout_enabled' => '1',
            'channel_customization_latest_sizing_enabled' => '1',
            'channel_customization_playlists_enabled' => '1',
            'channel_customization_playlists_layout_enabled' => '1',
            'channel_customization_playlists_sizing_enabled' => '1',
            'channel_customization_sections_enabled' => '1',
            'channel_customization_cta_enabled' => '1',
            'channel_customization_stats_enabled' => '1',
            'channel_customization_socials_enabled' => '1',
            'channel_customization_upload_rhythm_enabled' => '1',
            'channel_default_layout' => 'spotlight',
            'channel_default_theme' => 'midnight',
            'channel_default_accent' => 'coral',
            'channel_default_custom_accent' => '#18c8d8',
            'channel_default_surface' => 'glass',
            'channel_default_banner_treatment' => 'cinematic',
            'channel_default_avatar_frame' => 'ring',
            'channel_default_motion' => 'gentle',
            'channel_default_pattern' => 'signal',
            'channel_default_header_shape' => 'soft-arc',
            'channel_default_stats_style' => 'pills',
            'channel_default_video_layout' => 'grid',
            'channel_default_featured_mode' => 'pinned',
            'channel_default_featured_layout' => 'grid',
            'channel_default_featured_columns' => '3',
            'channel_default_featured_rows' => '1',
            'channel_default_latest_uploads_layout' => 'grid',
            'channel_default_latest_uploads_columns' => '3',
            'channel_default_latest_uploads_rows' => '1',
            'channel_default_playlists_layout' => 'stacked',
            'channel_default_playlists_columns' => '1',
            'channel_default_playlists_rows' => '3',
            'channel_default_playlist_video_columns' => '3',
            'channel_default_playlist_video_rows' => '1',
            'channel_default_density' => 'comfortable',
            'channel_tagline_max_length' => '120',
            'channel_cta_label_max_length' => '42',
        ];
    }

    public static function userDefaults(): array
    {
        return [
            'layout' => config('site.channel_default_layout', 'spotlight'),
            'theme' => config('site.channel_default_theme', 'midnight'),
            'accent' => config('site.channel_default_accent', 'coral'),
            'custom_accent' => config('site.channel_default_custom_accent', '#18c8d8'),
            'surface' => config('site.channel_default_surface', 'glass'),
            'banner_treatment' => config('site.channel_default_banner_treatment', 'cinematic'),
            'avatar_frame' => config('site.channel_default_avatar_frame', 'ring'),
            'motion' => config('site.channel_default_motion', 'gentle'),
            'pattern' => config('site.channel_default_pattern', 'signal'),
            'header_shape' => config('site.channel_default_header_shape', 'soft-arc'),
            'stats_style' => config('site.channel_default_stats_style', 'pills'),
            'video_layout' => config('site.channel_default_video_layout', 'grid'),
            'featured_mode' => config('site.channel_default_featured_mode', 'pinned'),
            'featured_layout' => config('site.channel_default_featured_layout', 'grid'),
            'featured_columns' => config('site.channel_default_featured_columns', '3'),
            'featured_rows' => config('site.channel_default_featured_rows', '1'),
            'latest_uploads_layout' => config('site.channel_default_latest_uploads_layout', 'grid'),
            'latest_uploads_columns' => config('site.channel_default_latest_uploads_columns', '3'),
            'latest_uploads_rows' => config('site.channel_default_latest_uploads_rows', '1'),
            'playlists_layout' => config('site.channel_default_playlists_layout', 'stacked'),
            'playlists_columns' => config('site.channel_default_playlists_columns', '1'),
            'playlists_rows' => config('site.channel_default_playlists_rows', '3'),
            'playlist_video_columns' => config('site.channel_default_playlist_video_columns', '3'),
            'playlist_video_rows' => config('site.channel_default_playlist_video_rows', '1'),
            'density' => config('site.channel_default_density', 'comfortable'),
            'tagline' => '',
            'cta_label' => '',
            'cta_url' => '',
            'show_stats' => '1',
            'show_featured_video' => '1',
            'show_latest_uploads' => '1',
            'show_playlists' => '1',
            'show_about_panel' => '1',
            'show_social_links' => '1',
            'show_upload_rhythm' => '1',
            'show_subscribe_cta' => '1',
            'dedupe_home_sections' => '1',
        ];
    }

    public static function booleanKeys(): array
    {
        return [
            'channel_customization_enabled',
            'channel_customization_layout_enabled',
            'channel_customization_theme_enabled',
            'channel_customization_accent_enabled',
            'channel_customization_custom_accent_enabled',
            'channel_customization_surface_enabled',
            'channel_customization_banner_enabled',
            'channel_customization_avatar_enabled',
            'channel_customization_motion_enabled',
            'channel_customization_effects_enabled',
            'channel_customization_pattern_enabled',
            'channel_customization_shape_enabled',
            'channel_customization_video_layout_enabled',
            'channel_customization_featured_video_enabled',
            'channel_customization_featured_layout_enabled',
            'channel_customization_featured_sizing_enabled',
            'channel_customization_latest_uploads_enabled',
            'channel_customization_latest_layout_enabled',
            'channel_customization_latest_sizing_enabled',
            'channel_customization_playlists_enabled',
            'channel_customization_playlists_layout_enabled',
            'channel_customization_playlists_sizing_enabled',
            'channel_customization_sections_enabled',
            'channel_customization_cta_enabled',
            'channel_customization_stats_enabled',
            'channel_customization_socials_enabled',
            'channel_customization_upload_rhythm_enabled',
        ];
    }

    public static function textKeys(): array
    {
        return array_values(array_diff(array_keys(self::defaults()), self::booleanKeys()));
    }

    public static function options(): array
    {
        return [
            'layout' => [
                'spotlight' => 'Spotlight',
                'studio' => 'Studio',
                'poster' => 'Poster',
                'split' => 'Split',
                'compact' => 'Compact',
            ],
            'theme' => [
                'midnight' => 'Midnight',
                'sunrise' => 'Sunrise',
                'ocean' => 'Ocean',
                'forest' => 'Forest',
                'candy' => 'Candy',
                'graphite' => 'Graphite',
            ],
            'accent' => [
                'coral' => 'Coral',
                'cyan' => 'Cyan',
                'violet' => 'Violet',
                'amber' => 'Amber',
                'emerald' => 'Emerald',
                'rose' => 'Rose',
                'custom' => 'Custom color',
            ],
            'surface' => [
                'glass' => 'Glass',
                'solid' => 'Solid',
                'outlined' => 'Outlined',
                'soft' => 'Soft',
            ],
            'banner_treatment' => [
                'cinematic' => 'Cinematic',
                'poster' => 'Poster crop',
                'split' => 'Split focus',
                'mosaic' => 'Mosaic',
                'minimal' => 'Minimal',
            ],
            'avatar_frame' => [
                'ring' => 'Accent ring',
                'glow' => 'Soft glow',
                'square' => 'Rounded square',
                'badge' => 'Creator badge',
                'none' => 'No frame',
            ],
            'motion' => [
                'none' => 'None',
                'gentle' => 'Gentle',
                'float' => 'Float',
                'pulse' => 'Pulse',
                'sweep' => 'Light sweep',
            ],
            'pattern' => [
                'clean' => 'Clean',
                'signal' => 'Signal',
                'grid' => 'Grid',
                'diagonal' => 'Diagonal',
                'waves' => 'Waves',
            ],
            'header_shape' => [
                'flat' => 'Flat',
                'soft-arc' => 'Soft arc',
                'angled' => 'Angled',
                'framed' => 'Framed',
            ],
            'stats_style' => [
                'pills' => 'Pills',
                'bar' => 'Bar',
                'minimal' => 'Minimal',
            ],
            'video_layout' => [
                'grid' => 'Grid',
                'compact' => 'Compact grid',
                'list' => 'List',
                'showcase' => 'Showcase',
            ],
            'featured_mode' => [
                'pinned' => 'Pinned video',
                'latest' => 'Latest video',
                'popular' => 'Most viewed video',
            ],
            'featured_layout' => [
                'grid' => 'Video cards',
                'hero' => 'Lead story',
                'rail' => 'Horizontal rail',
                'compact' => 'Compact cards',
            ],
            'latest_uploads_layout' => [
                'grid' => 'Video cards',
                'showcase' => 'Lead card',
                'rail' => 'Horizontal rail',
                'compact' => 'Compact cards',
            ],
            'playlists_layout' => [
                'stacked' => 'Stacked rows',
                'grid' => 'Playlist grid',
                'rail' => 'Horizontal rail',
                'compact' => 'Compact panels',
            ],
            'density' => [
                'compact' => 'Compact',
                'comfortable' => 'Comfortable',
                'spacious' => 'Spacious',
            ],
        ];
    }

    public static function merge(?array $customization): array
    {
        $customization = is_array($customization) ? $customization : [];
        $merged = array_merge(self::userDefaults(), Arr::only($customization, array_keys(self::userDefaults())));

        foreach (self::options() as $key => $options) {
            $default = self::userDefaults()[$key] ?? array_key_first($options);
            if (! array_key_exists((string) ($merged[$key] ?? ''), $options)) {
                $merged[$key] = $default;
            }
        }

        foreach (['show_stats', 'show_featured_video', 'show_latest_uploads', 'show_playlists', 'show_about_panel', 'show_social_links', 'show_upload_rhythm', 'show_subscribe_cta', 'dedupe_home_sections'] as $key) {
            $merged[$key] = in_array((string) ($merged[$key] ?? '0'), ['1', 'true', 'on'], true) ? '1' : '0';
        }

        $merged['custom_accent'] = self::sanitizeColor((string) ($merged['custom_accent'] ?? '#18c8d8'));
        $merged['tagline'] = self::trimToLimit((string) ($merged['tagline'] ?? ''), (int) config('site.channel_tagline_max_length', 120));
        $merged['cta_label'] = self::trimToLimit((string) ($merged['cta_label'] ?? ''), (int) config('site.channel_cta_label_max_length', 42));
        $merged['cta_url'] = trim((string) ($merged['cta_url'] ?? ''));
        $merged['featured_columns'] = (string) self::clampInt($merged['featured_columns'] ?? 3, 1, 6);
        $merged['featured_rows'] = (string) self::clampInt($merged['featured_rows'] ?? 1, 1, 4);
        $merged['latest_uploads_columns'] = (string) self::clampInt($merged['latest_uploads_columns'] ?? 3, 1, 6);
        $merged['latest_uploads_rows'] = (string) self::clampInt($merged['latest_uploads_rows'] ?? 1, 1, 6);
        $merged['playlists_columns'] = (string) self::clampInt($merged['playlists_columns'] ?? 1, 1, 4);
        $merged['playlists_rows'] = (string) self::clampInt($merged['playlists_rows'] ?? 3, 1, 6);
        $merged['playlist_video_columns'] = (string) self::clampInt($merged['playlist_video_columns'] ?? 3, 1, 6);
        $merged['playlist_video_rows'] = (string) self::clampInt($merged['playlist_video_rows'] ?? 1, 1, 4);

        return $merged;
    }

    public static function normalizeForStorage(array $input, ?array $current = null): array
    {
        $merged = self::merge(array_merge($current ?? [], $input));

        if (! config('site.channel_customization_layout_enabled', true)) {
            $merged['layout'] = self::userDefaults()['layout'];
        }
        if (! config('site.channel_customization_theme_enabled', true)) {
            $merged['theme'] = self::userDefaults()['theme'];
        }
        if (! config('site.channel_customization_accent_enabled', true)) {
            $merged['accent'] = self::userDefaults()['accent'];
        }
        if (! config('site.channel_customization_custom_accent_enabled', true) || $merged['accent'] !== 'custom') {
            $merged['custom_accent'] = self::userDefaults()['custom_accent'];
        }
        if (! config('site.channel_customization_surface_enabled', true)) {
            $merged['surface'] = self::userDefaults()['surface'];
        }
        if (! config('site.channel_customization_banner_enabled', true)) {
            $merged['banner_treatment'] = self::userDefaults()['banner_treatment'];
        }
        if (! config('site.channel_customization_avatar_enabled', true)) {
            $merged['avatar_frame'] = self::userDefaults()['avatar_frame'];
        }
        if (! config('site.channel_customization_motion_enabled', true)) {
            $merged['motion'] = 'none';
        }
        if (! config('site.channel_customization_pattern_enabled', true)) {
            $merged['pattern'] = self::userDefaults()['pattern'];
        }
        if (! config('site.channel_customization_shape_enabled', true)) {
            $merged['header_shape'] = self::userDefaults()['header_shape'];
        }
        if (! config('site.channel_customization_video_layout_enabled', true)) {
            $merged['video_layout'] = self::userDefaults()['video_layout'];
        }
        if (! config('site.channel_customization_featured_video_enabled', true)) {
            $merged['show_featured_video'] = '0';
        }
        if (! config('site.channel_customization_featured_layout_enabled', true)) {
            $merged['featured_layout'] = self::userDefaults()['featured_layout'];
        }
        if (! config('site.channel_customization_featured_sizing_enabled', true)) {
            $merged['featured_columns'] = self::userDefaults()['featured_columns'];
            $merged['featured_rows'] = self::userDefaults()['featured_rows'];
        }
        if (! config('site.channel_customization_latest_uploads_enabled', true)) {
            $merged['show_latest_uploads'] = '0';
        }
        if (! config('site.channel_customization_latest_layout_enabled', true)) {
            $merged['latest_uploads_layout'] = self::userDefaults()['latest_uploads_layout'];
        }
        if (! config('site.channel_customization_latest_sizing_enabled', true)) {
            $merged['latest_uploads_columns'] = self::userDefaults()['latest_uploads_columns'];
            $merged['latest_uploads_rows'] = self::userDefaults()['latest_uploads_rows'];
        }
        if (! config('site.channel_customization_playlists_enabled', true)) {
            $merged['show_playlists'] = '0';
        }
        if (! config('site.channel_customization_playlists_layout_enabled', true)) {
            $merged['playlists_layout'] = self::userDefaults()['playlists_layout'];
        }
        if (! config('site.channel_customization_playlists_sizing_enabled', true)) {
            $merged['playlists_columns'] = self::userDefaults()['playlists_columns'];
            $merged['playlists_rows'] = self::userDefaults()['playlists_rows'];
            $merged['playlist_video_columns'] = self::userDefaults()['playlist_video_columns'];
            $merged['playlist_video_rows'] = self::userDefaults()['playlist_video_rows'];
        }
        if (! config('site.channel_customization_sections_enabled', true)) {
            $merged['show_about_panel'] = '0';
        }
        if (! config('site.channel_customization_cta_enabled', true)) {
            $merged['cta_label'] = '';
            $merged['cta_url'] = '';
            $merged['show_subscribe_cta'] = '0';
        }
        if (! config('site.channel_customization_stats_enabled', true)) {
            $merged['show_stats'] = '0';
        }
        if (! config('site.channel_customization_socials_enabled', true)) {
            $merged['show_social_links'] = '0';
        }
        if (! config('site.channel_customization_upload_rhythm_enabled', true)) {
            $merged['show_upload_rhythm'] = '0';
        }

        return $merged;
    }

    public static function bool(array $settings, string $key): bool
    {
        $defaults = self::defaults();

        return (string) ($settings[$key] ?? $defaults[$key] ?? '0') === '1';
    }

    public static function text(array $settings, string $key): string
    {
        $defaults = self::defaults();

        return (string) ($settings[$key] ?? $defaults[$key] ?? '');
    }

    public static function sanitizeColor(string $value): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtolower($value) : '#18c8d8';
    }

    private static function trimToLimit(string $value, int $limit): string
    {
        $limit = max(1, $limit);
        $value = trim($value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => $min]]);

        return min($max, max($min, (int) $value));
    }
}
