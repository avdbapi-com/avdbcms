<?php

namespace App\Support;

class FooterSettings
{
    public static function defaults(): array
    {
        return [
            'footer_brand_enabled' => '1',
            'footer_brand_title' => '',
            'footer_tagline' => 'Share and watch amazing videos. Upload original content and connect with creators worldwide.',
            'footer_social_enabled' => '1',
            'footer_explore_enabled' => '1',
            'footer_explore_title' => 'Explore',
            'footer_explore_links' => "Home|/\nTrending|/trend\nPremium|/premium",
            'footer_categories_enabled' => '1',
            'footer_categories_title' => 'Categories',
            'footer_categories_links' => '',
            'footer_categories_limit' => '6',
            'footer_support_enabled' => '1',
            'footer_support_title' => 'Support',
            'footer_support_links' => "Contact|/contact\nTelegram Group|https://t.me/+jj6McO-iYfA0MTM1\nTelegram Support|https://t.me/avdbapi_support\nTerms of Service|/terms",
            'footer_settings_enabled' => '1',
            'footer_settings_title' => 'Settings',
            'footer_language_switcher_enabled' => '1',
            'footer_theme_switcher_enabled' => '1',
            'footer_bottom_links_enabled' => '1',
            'footer_bottom_links' => "Terms|/terms\nContact|/contact",
        ];
    }

    public static function booleanKeys(): array
    {
        return [
            'footer_brand_enabled',
            'footer_social_enabled',
            'footer_explore_enabled',
            'footer_categories_enabled',
            'footer_support_enabled',
            'footer_settings_enabled',
            'footer_language_switcher_enabled',
            'footer_theme_switcher_enabled',
            'footer_bottom_links_enabled',
        ];
    }

    public static function textKeys(): array
    {
        return [
            'footer_brand_title',
            'footer_tagline',
            'footer_explore_title',
            'footer_explore_links',
            'footer_categories_title',
            'footer_categories_links',
            'footer_categories_limit',
            'footer_support_title',
            'footer_support_links',
            'footer_settings_title',
            'footer_bottom_links',
        ];
    }

    public static function linkKeys(): array
    {
        return [
            'footer_explore_links',
            'footer_categories_links',
            'footer_support_links',
            'footer_bottom_links',
        ];
    }

    public static function parseLinks(?string $value, int $limit = 24): array
    {
        $links = [];
        $lines = preg_split('/\r\n|\r|\n/', trim((string) $value)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$label, $url] = array_pad(explode('|', $line, 2), 2, '#');
            $label = trim($label);
            $url = trim($url);

            if ($label === '') {
                continue;
            }

            // Hide links to features switched off in Settings (e.g. /trend while trending is disabled).
            if (NavigationSettings::isFeatureDisabledUrl($url)) {
                continue;
            }

            $links[] = [
                'label' => $label,
                'url' => self::normalizeUrl($url),
                'external' => self::isExternalUrl($url),
            ];

            if (count($links) >= $limit) {
                break;
            }
        }

        return $links;
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

        $path = '/' . ltrim($url, '/');

        return function_exists('locale_url') ? locale_url($path) : $path;
    }

    public static function isExternalUrl(string $url): bool
    {
        return (bool) preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', trim($url));
    }
}
