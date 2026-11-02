<?php

namespace App\Support;

/**
 * Which ad code and shape the list ad of each video list uses (Money > Ads > "Different ads per list"), in one place.
 *
 * A list names its place with an ad context: <x-videos-area ad-context="...">, <x-feed-ad-slot context="...">, the
 * channel videos tab (resources/js/components/Videos/UserVideos.tsx). Each context is a list of SETTINGS with two
 * settings of its own, ad_code_grid_<context> (the code) and ad_grid_layout_<context> (the shape: inherit, grid_card or
 * wide_row). The main list of the homepage layouts with a top banner and one list (Spotlight, Cinema rows, Mosaic,
 * Shorts and Control room: $homeFeedLayouts in resources/views/pages/home.blade.php) is home_feed. Up to 1.2.2 those
 * layouts gave it five contexts that no map knew (yt_spotlight_feed, cinema_feed, bento_feed, shorts_feed, cyber_feed),
 * so the main list of the default look never showed a list ad. One context per list also keeps window.AD_CONFIG
 * small: it carries each list's code once.
 *
 * Read by window.AD_CONFIG (components/layout.blade.php), the server skeleton (components/videos-area.blade.php),
 * <x-feed-ad-slot>, AppServiceProvider (the settings into config('site.*')) and the save rules
 * (SettingsController::settingsSchema). tests/Unit/ListAdContextsTest.php checks that every context the views and
 * scripts use is a list here and that every list has its fields, save rules and config.
 */
final class ListAdContexts
{
    /**
     * The lists with a list ad, by ad context, in the order of Money > Ads.
     *
     * @var list<string>
     */
    public const SETTINGS = [
        'smart_discovery',
        'creator_spotlight',
        'trending_actors',
        'new_videos',
        'home_feed',
        'trend_page',
        'category_page',
        'subscriptions',
        'channel_videos',
        'search_results',
        'playlist_videos',
        'watch_related',
        'channel_home_featured',
        'channel_home_latest',
    ];

    /**
     * The shapes of a list ad: a card the size of a video, or a full-width row between rows. A list's own shape may
     * also be 'inherit': the main shape of Money > Ads (ad_grid_layout).
     *
     * @var list<string>
     */
    public const SHAPES = ['grid_card', 'wide_row'];

    /**
     * Every list context.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::SETTINGS;
    }

    /** The list whose settings a context uses (the context itself), or null for a context no list has. */
    public static function settingsOf(mixed $context): ?string
    {
        return is_string($context) && in_array($context, self::SETTINGS, true) ? $context : null;
    }

    /** The ad code of a list as the site has it (config site.ad_code_grid_<list>), '' for an unknown context. */
    public static function code(mixed $context): string
    {
        $list = self::settingsOf($context);

        if ($list === null) {
            return '';
        }

        $code = config('site.ad_code_grid_'.$list, '');

        return is_string($code) ? $code : '';
    }

    /** A list's own shape: 'inherit' (the main shape), 'grid_card' or 'wide_row'; 'inherit' for an unknown context. */
    public static function ownShape(mixed $context): string
    {
        $list = self::settingsOf($context);
        $shape = $list === null ? 'inherit' : config('site.ad_grid_layout_'.$list, 'inherit');

        return in_array($shape, self::SHAPES, true) ? $shape : 'inherit';
    }

    /** The shape a list's ad takes: the list's own, or the main shape of Money > Ads when the list has none. */
    public static function shape(mixed $context): string
    {
        $shape = self::ownShape($context);

        if ($shape !== 'inherit') {
            return $shape;
        }

        return config('site.ad_grid_layout', 'grid_card') === 'wide_row' ? 'wide_row' : 'grid_card';
    }

    /**
     * Context => ad code of every list, for window.AD_CONFIG.gridAdCodeByContext (VideosList.tsx reads the code of its
     * own context there).
     *
     * @return array<string, string>
     */
    public static function codes(): array
    {
        $codes = [];

        foreach (self::all() as $context) {
            $codes[$context] = self::code($context);
        }

        return $codes;
    }

    /**
     * Context => own shape of every list, for window.AD_CONFIG.gridLayoutByContext ('inherit' = the main shape,
     * window.AD_CONFIG.gridLayout).
     *
     * @return array<string, string>
     */
    public static function shapes(): array
    {
        $shapes = [];

        foreach (self::all() as $context) {
            $shapes[$context] = self::ownShape($context);
        }

        return $shapes;
    }
}
