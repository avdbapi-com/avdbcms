<?php

namespace App\Support;

class NavigationSettings
{
    public static function defaults(): array
    {
        return [
            'header_brand_text' => '',
            'header_premium_chip_enabled' => '1',
            'header_premium_label' => 'Premium',
            'header_upload_label' => 'Upload',
            'header_login_button_enabled' => '1',
            'header_login_label' => 'Sign In',
            'header_register_button_enabled' => '1',
            'header_register_label' => 'Register',
            'sidebar_static_menu_enabled' => '1',
            'sidebar_primary_links' => "Home|house|/\nTrend|fire|/trend\nSubscriptions|user-check|/subscriptions",
            'sidebar_auth_links' => "History|history|/history\nYour videos|video|/profile/videos|1\nWatch later|clock|/playlist/watch-later\nLiked videos|thumbs-up|/playlist/liked-videos",
            'sidebar_playlists_title' => 'Playlists',
            'sidebar_playlists_limit' => '5',
            'sidebar_subscriptions_title' => 'Subscriptions',
            'sidebar_subscriptions_limit' => '5',
            'sidebar_discover_channels_enabled' => '1',
            'sidebar_discover_channels_label' => 'Discover channels',
            'sidebar_explore_title' => 'Explore',
            'sidebar_explore_limit' => '24',
            'sidebar_premium_label' => '{site_name} Premium',
            'sidebar_more_label' => 'Show',
            'sidebar_less_label' => 'Show less',
            'watch_related_heading_enabled' => '0',
            'watch_related_title' => 'Related videos',
            'watch_related_source' => 'balanced',
            'watch_related_show_channel_enabled' => '1',
            'watch_related_show_meta_enabled' => '1',
        ];
    }

    public static function booleanKeys(): array
    {
        return [
            'header_premium_chip_enabled',
            'header_login_button_enabled',
            'header_register_button_enabled',
            'sidebar_static_menu_enabled',
            'sidebar_discover_channels_enabled',
            'watch_related_heading_enabled',
            'watch_related_show_channel_enabled',
            'watch_related_show_meta_enabled',
        ];
    }

    public static function textKeys(): array
    {
        return [
            'header_brand_text',
            'header_premium_label',
            'header_upload_label',
            'header_login_label',
            'header_register_label',
            'sidebar_primary_links',
            'sidebar_auth_links',
            'sidebar_playlists_title',
            'sidebar_playlists_limit',
            'sidebar_subscriptions_title',
            'sidebar_subscriptions_limit',
            'sidebar_discover_channels_label',
            'sidebar_explore_title',
            'sidebar_explore_limit',
            'sidebar_premium_label',
            'sidebar_more_label',
            'sidebar_less_label',
            'watch_related_title',
            'watch_related_source',
        ];
    }

    public static function menuLinkKeys(): array
    {
        return ['sidebar_primary_links', 'sidebar_auth_links'];
    }

    public static function watchRelatedSources(): array
    {
        return [
            'balanced' => 'Balanced',
            'same_creator' => 'Same creator first',
            'same_category' => 'Same category first',
            'latest' => 'Latest videos',
            'nearby' => 'Nearby imported videos',
        ];
    }

    public static function parseMenuLinks(?string $value, int $limit = 20): array
    {
        $links = [];
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $value)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$label, $icon, $url, $newTab] = array_pad(explode('|', $line, 4), 4, '');
            $label = trim($label);
            $icon = self::cleanIcon($icon);

            if ($label === '') {
                continue;
            }

            // Pages the admin switched off (Trending / History) redirect home, so
            // do not render dead links to them in any configurable menu.
            if (self::isFeatureDisabledUrl($url)) {
                continue;
            }

            $links[] = [
                'label' => $label,
                'icon' => $icon ?: 'circle',
                'url' => self::normalizeUrl($url),
                'external' => self::isExternalUrl($url),
                'new_tab' => in_array(strtolower(trim($newTab)), ['1', 'true', 'yes', 'new', '_blank'], true),
            ];

            if (count($links) >= $limit) {
                break;
            }
        }

        return $links;
    }

    /**
     * Pages the admin switched off (Trending / History) redirect to the home page,
     * so any configurable menu entry pointing at them is a dead link and is hidden.
     */
    public static function isFeatureDisabledUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || $url === '#' || str_starts_with($url, '#') || self::isExternalUrl($url)) {
            return false;
        }

        $path = strtolower(trim((string) (parse_url($url, PHP_URL_PATH) ?: $url), '/'));
        // Tolerate a locale prefix such as "en/trend" or "pt-br/history".
        $path = (string) preg_replace('#^[a-z]{2}(?:[-_][a-z]{2,4})?/#', '', $path);

        return ($path === 'trend' && !config('site.show_trending', true))
            || ($path === 'history' && !config('site.show_history', true));
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '#';
        }

        if ($url === '#' || str_starts_with($url, '#') || self::isExternalUrl($url) || preg_match('/^(mailto|tel):/i', $url)) {
            return $url;
        }

        $path = '/'.ltrim($url, '/');

        return function_exists('locale_url') ? locale_url($path) : $path;
    }

    public static function cleanIcon(string $icon): string
    {
        $icon = trim($icon);

        return $icon === '' ? '' : FontAwesomeIconRegistry::normalize($icon);
    }

    public static function isExternalUrl(string $url): bool
    {
        return (bool) preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', trim($url));
    }

    public static function siteLabel(string $value): string
    {
        return str_replace('{site_name}', config('app.name', 'AVDBCMS'), $value);
    }
}
