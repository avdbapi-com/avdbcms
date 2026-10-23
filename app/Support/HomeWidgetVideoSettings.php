<?php

namespace App\Support;

class HomeWidgetVideoSettings
{
    public static function widgets(): array
    {
        return [
            'smart_discovery_rail' => [
                'label' => 'Smart Discovery Tags',
                'source' => 'trending',
            ],
            'creator_spotlight' => [
                'label' => 'Creator Spotlight',
                'source' => 'newest',
            ],
            'trending_collections' => [
                'label' => 'Trending Actors',
                'source' => 'most_viewed',
            ],
        ];
    }

    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::widgets() as $prefix => $widget) {
            $defaults[$prefix . '_videos_enabled'] = '0';
            $defaults[$prefix . '_video_source'] = $widget['source'];
            $defaults[$prefix . '_video_category_id'] = '0';
            $defaults[$prefix . '_video_columns'] = '5';
            $defaults[$prefix . '_video_rows'] = '2';
        }

        return array_merge($defaults, self::contentDefaults());
    }

    public static function contentDefaults(): array
    {
        return [
            'smart_discovery_rail_kicker' => 'Smart discovery',
            'smart_discovery_rail_title' => 'Tags with the most videos',
            'smart_discovery_rail_fallback_kicker' => 'Browse',
            'smart_discovery_rail_fallback_title' => 'Explore categories',
            'smart_discovery_rail_link_label' => 'View trending',
            'creator_spotlight_kicker' => 'Creator spotlight',
            'creator_spotlight_title' => 'Creators posting now',
            'creator_spotlight_fallback_kicker' => 'Creator spotlight',
            'creator_spotlight_fallback_title' => 'Find creators by category',
            'creator_spotlight_link_label' => 'Discover channels',
            'trending_collections_kicker' => 'Trending actors',
            'trending_collections_title' => 'Actors viewers are watching now',
            'trending_collections_fallback_kicker' => 'Browse collections',
            'trending_collections_fallback_title' => 'Collections to start with',
            'trending_collections_link_label' => 'View trending',
        ];
    }

    public static function contentTextKeys(): array
    {
        return array_keys(self::contentDefaults());
    }

    public static function booleanKeys(): array
    {
        return array_map(
            static fn (string $prefix): string => $prefix . '_videos_enabled',
            array_keys(self::widgets())
        );
    }

    public static function textKeys(): array
    {
        $keys = [];

        foreach (array_keys(self::widgets()) as $prefix) {
            $keys[] = $prefix . '_video_source';
            $keys[] = $prefix . '_video_category_id';
            $keys[] = $prefix . '_video_columns';
            $keys[] = $prefix . '_video_rows';
        }

        return array_merge($keys, self::contentTextKeys());
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
}
