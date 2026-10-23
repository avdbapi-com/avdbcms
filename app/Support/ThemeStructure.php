<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\View;

/**
 * The structural design of the public site for the current request: which header,
 * side menu, hero and card frame the views render, plus the theme's section voice.
 *
 * Settings decide the structure (header_style, sidebar_style, hero_layout_mode, ...);
 * the theme decides its decorations (masthead, crest, card frame, hero variant ...), so
 * an owner cannot build a broken combination. Admin previews pass the same keys as
 * query parameters (?header_style=, ?sidebar_style=, ?theme=).
 *
 * The <html> data attributes (htmlAttributes()) and the Blade partials read the same
 * values, so CSS keyed on html[data-avd-*] always matches the markup.
 */
class ThemeStructure
{
    public const HEADER_STYLES = ['glass_blur', 'glass_island', 'minimal_slim', 'full_megabar', 'solid_dark', 'transparent_overlay'];

    public const SIDEBAR_STYLES = ['full', 'rail', 'floating', 'hidden'];

    /** Pages that render a full-bleed hero declare it with @section(ThemeStructure::OVERLAY_SECTION, '1'). */
    public const OVERLAY_SECTION = 'avd_header_overlay';

    /** A page that renders a hero zone (pages.partials.hero) declares it with @section(ThemeStructure::HERO_ZONE_SECTION, '1'). */
    public const HERO_ZONE_SECTION = 'avd_hero_zone';

    /** Routes that never pin the side menu (the watch page and the premium page use the drawer only). */
    private const UNPINNED_ROUTES = ['video.show', 'video.seo-show', 'pages.premium'];

    private const RECIPE_DEFAULTS = [
        'header_deco' => 'none',
        'hero_deco' => 'none',
        'card_frame' => 'none',
        'chip_style' => 'none',
        'section_style' => 'none',
        'avatar_shape' => 'circle',
        'backdrop' => 'none',
        'sidebar_deco' => 'none',
        'rank_shelf' => false,
    ];

    /**
     * Section voice per theme (English keys, translated with __() where rendered; labels the site already had keep
     * their existing key and case, e.g. 'Recommended Videos', 'Trending Now', 'LIVE PULSE', so every language has them):
     * feed = the main feed heading, kicker = the hero badge / kicker, shorts = the shorts shelf heading,
     * ticker = [badge, heading, first list, second list].
     */
    private const VOICES = [
        'tokyo_neon' => ['feed' => 'Recommended Videos', 'ticker' => ['LIVE PULSE', 'Real-time trending', 'Viral Velocity', 'Fastest Rising']],
        'daylight_paper' => ['feed' => 'Recommended', 'kicker' => 'Fresh picks'],
        'clean_graphite' => ['feed' => 'Trending Now', 'kicker' => 'Spotlight'],
        'pure_oled_black' => ['feed' => 'Trending Now', 'kicker' => 'Featured film'],
        'silver_screen' => ['feed' => 'Now showing', 'kicker' => 'Feature presentation'],
        'shibuya_rain' => ['feed' => 'Trending Now', 'kicker' => 'Now showing'],
        'electric_violet' => ['feed' => 'Fresh drops', 'kicker' => "Tonight's mix"],
        'synthwave_sunset' => ['feed' => 'Hot this week', 'kicker' => 'Now playing'],
        'vaporwave_mall' => ['feed' => 'New arrivals', 'kicker' => 'Welcome'],
        'obsidian_gold' => ['feed' => 'Curated collections', 'kicker' => 'The collection'],
        'rose_gold_velvet' => ['feed' => 'Velvet selection', 'kicker' => 'Tonight in the lounge', 'feed_kicker' => 'Hand-picked tonight'],
        'ivory_salon' => ['feed' => 'From the collection', 'kicker' => 'Now showing'],
        'swiss_monolith' => ['feed' => 'Latest', 'kicker' => 'Featured'],
        'broadsheet' => ['feed' => "Today's picks", 'feed_kicker' => "Editor's selection", 'side' => 'Most watched'],
        'nordic_minimal' => ['feed' => 'For a quiet evening', 'kicker' => 'Good evening'],
        'amazon_rainforest' => ['feed' => 'Wild and green', 'kicker' => 'Field notes'],
        'sahara_dune' => ['feed' => 'Golden hour', 'kicker' => 'Travel'],
        'matcha_zen' => ['feed' => 'Selected films', 'kicker' => "Today's selection"],
        'deep_trench' => ['feed' => 'Into the deep', 'kicker' => 'Documentary'],
        'interstellar' => ['feed' => 'Mission log', 'kicker' => 'Spotlight'],
        'aurora_borealis' => ['feed' => 'Featured', 'kicker' => 'Tonight'],
        'lavender_cloud' => ['feed' => 'Picked for you', 'kicker' => 'Spotlight'],
        'holo_pearl' => ['feed' => 'Trending looks', 'kicker' => 'Feature'],
        'bubblegum_pop' => ['feed' => 'Fresh for you', 'shorts' => 'Quick clips'],
        'fairy_floss' => ['feed' => 'For you', 'shorts' => 'Dreamy shorts'],
        'cyber_gladiator' => ['feed' => 'Highlights', 'ticker' => ['Top plays', 'Tournament day', 'Most watched', 'Fastest Rising']],
        'razer_poison' => ['feed' => 'Hot clips', 'kicker' => 'Now streaming'],
        'overwatch_pulse' => ['feed' => 'Latest', 'kicker' => 'Match day'],
        'dracula_official' => ['feed' => 'Trending', 'ticker' => ['Trending', 'Trending this week', 'Most viewed', 'Fastest Rising']],
        'cyber_matrix' => ['feed' => 'Trending', 'kicker' => 'Now playing'],
        'neo_pop' => ['feed' => 'Fresh & fun', 'kicker' => 'Featured'],
        'clean_concrete' => ['feed' => 'Index', 'kicker' => 'Featured'],
    ];

    private const DEFAULT_VOICE = [
        'feed' => 'Recommended Videos',
        'kicker' => 'Featured',
        'feed_kicker' => '',
        'shorts' => 'Shorts',
        'side' => 'Most watched',
        'ticker' => ['Trending', 'Trending Now', 'Most viewed', 'Fastest Rising'],
    ];

    /** Hero variants each hero_layout_mode can show; the first one is the default for themes without a match. */
    private const HERO_KINDS = [
        'live_ticker_stream' => ['ticker' => ['ticker', 'ticker_scoreboard', 'ticker_code']],
        'bento_spotlight' => ['bento' => ['bento', 'bento_glass', 'bento_holo', 'bento_pulse', 'bento_swiss', 'bento_gallery']],
        'classic_banner' => ['banner' => ['banner', 'banner_soft', 'banner_zen', 'banner_pop', 'banner_window']],
        'editorial_lead' => ['lead' => ['lead_news', 'lead_brutal']],
        'snap_slider_carousel' => [
            'billboard' => ['billboard_inset', 'billboard_full', 'billboard_fade', 'billboard_leaf', 'billboard_horizon'],
            'split' => ['split', 'split_glass', 'split_orbit'],
            'shorts' => ['shorts', 'stories_shorts'],
            'hud' => ['hud', 'hud_toxic', 'hud_terminal'],
            'letterbox' => ['letterbox'],
            'arch' => ['billboard_arch'],
        ],
    ];

    /** The slider kind a home layout shows when the theme brings no slider variant of its own. */
    private const LAYOUT_SLIDERS = [
        'youtube_spotlight' => 'split',
        'tiktok_shorts' => 'shorts',
        'cyberpunk_hud' => 'hud',
    ];

    /**
     * Theme slider variants that give way to the billboard on the cinema-rows home (cinema_carousel = billboard +
     * shelves): Interstellar's split spotlight becomes its starfield billboard there, as in the spec mockups of the
     * Deep Space Theater look. The decoration keeps its name, so its file styles both heroes.
     */
    private const CINEMA_BILLBOARD_DECOS = ['split_orbit'];

    /** The structure values of the current request (memoised on the request). */
    public static function resolve(): array
    {
        $request = request();
        $cached = $request->attributes->get('avd.theme_structure');

        if (is_array($cached)) {
            return $cached;
        }

        $theme = self::themeId();
        $recipe = array_replace(self::RECIPE_DEFAULTS, array_intersect_key(self::recipe($theme), self::RECIPE_DEFAULTS));
        $headerStyle = self::option('header_style', array_merge(self::HEADER_STYLES, ['sidebar_rail']), 'glass_blur');

        $structure = [
            'theme' => $theme,
            'header_style' => $headerStyle === 'sidebar_rail' ? 'glass_blur' : $headerStyle,
            'sidebar_style' => self::resolveSidebarStyle($headerStyle),
        ] + $recipe;

        $structure['rank_shelf'] = (bool) $structure['rank_shelf'];
        $request->attributes->set('avd.theme_structure', $structure);

        return $structure;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::resolve()[$key] ?? $default;
    }

    /**
     * The header style the page really shows: transparent_overlay needs a full-bleed hero
     * under it, so pages without one get the solid bar.
     */
    public static function headerStyle(): string
    {
        $style = self::get('header_style');

        if ($style === 'transparent_overlay' && ! self::pageHasOverlayHero()) {
            return 'solid_dark';
        }

        return $style;
    }

    public static function pageHasOverlayHero(): bool
    {
        return View::hasSection(self::OVERLAY_SECTION);
    }

    /**
     * True when the page renders a hero zone. The layout marks its body (body.has-hero-zone) for the theme CSS,
     * which must not test the page with :has() (a page-wide :has() restyled the page on every feed page).
     */
    public static function pageHasHeroZone(): bool
    {
        return View::hasSection(self::HERO_ZONE_SECTION);
    }

    public static function sidebarStyle(): string
    {
        return self::get('sidebar_style');
    }

    /**
     * True when the side menu is a pinned column on wide screens: a visible style, "Side menu open on computers"
     * (front_sidebar_pinned_enabled, ?front_sidebar_pinned_enabled= previews it) on, and not the watch/premium page.
     * The layout's body.has-sidebar, the header and the side menu all read this one answer.
     */
    public static function sidebarPinned(?string $routeName = null): bool
    {
        $routeName ??= request()->route()?->getName();

        return self::sidebarStyle() !== 'hidden'
            && self::bool(self::queryOrConfig('front_sidebar_pinned_enabled', true))
            && ! in_array($routeName, self::UNPINNED_ROUTES, true);
    }

    /** A menu link's href: site paths keep the visitor's language (locale_url); absolute and '#' links stay as they are. */
    public static function menuHref(string $url): string
    {
        if ($url === '' || str_starts_with($url, '#') || NavigationSettings::isExternalUrl($url)) {
            return $url === '' ? '#' : $url;
        }

        return function_exists('locale_url') ? locale_url($url) : url($url);
    }

    /** True when a menu href is the current page: the same path, and the same host for absolute links ('#' never). */
    public static function menuLinkIsCurrent(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return false;
        }

        $host = parse_url($href, PHP_URL_HOST);

        if (is_string($host) && strcasecmp($host, request()->getHost()) !== 0) {
            return false;
        }

        return rtrim((string) parse_url($href, PHP_URL_PATH), '/') === rtrim((string) parse_url(url()->current(), PHP_URL_PATH), '/');
    }

    /** Header links replace the side menu on wide screens when it is hidden, and sit in the island and crest bars. */
    public static function headerLinksVisible(): bool
    {
        return self::sidebarStyle() === 'hidden'
            || self::headerStyle() === 'glass_island'
            || self::get('header_deco') === 'crest';
    }

    /**
     * The hero the home page shows for a hero_layout_mode: ['kind' => ticker|bento|banner|lead|billboard|split|shorts|hud|letterbox|arch,
     * 'deco' => the theme's variant of that kind]. Null when the hero is off or unknown. When the theme has no
     * variant of that kind, the slider follows the home layout (shorts shelf, HUD, split spotlight, billboard); a
     * CINEMA_BILLBOARD_DECOS spotlight is a billboard on the cinema-rows home.
     */
    public static function hero(string $heroLayoutMode, string $homeLayoutMode = ''): ?array
    {
        $kinds = self::HERO_KINDS[$heroLayoutMode] ?? null;

        if ($kinds === null) {
            return null;
        }

        $deco = (string) self::get('hero_deco');

        if ($heroLayoutMode === 'snap_slider_carousel' && $homeLayoutMode === 'cinema_carousel' && in_array($deco, self::CINEMA_BILLBOARD_DECOS, true)) {
            return ['kind' => 'billboard', 'deco' => $deco];
        }

        foreach ($kinds as $kind => $decos) {
            if (in_array($deco, $decos, true)) {
                return ['kind' => $kind, 'deco' => $deco];
            }
        }

        $kind = $heroLayoutMode === 'snap_slider_carousel'
            ? (self::LAYOUT_SLIDERS[$homeLayoutMode] ?? 'billboard')
            : array_key_first($kinds);

        return ['kind' => $kind, 'deco' => $kinds[$kind][0]];
    }

    /** One line of the theme's section voice (English key; translate where rendered). */
    public static function voice(string $key): mixed
    {
        $voice = self::VOICES[self::get('theme')] ?? [];

        return $voice[$key] ?? self::DEFAULT_VOICE[$key] ?? '';
    }

    /** The data-avd-* structure attributes for <html> (the layout prints them; CSS keys on them). */
    public static function htmlAttributes(): array
    {
        $structure = self::resolve();

        return [
            'data-avd-header-style' => self::headerStyle(),
            'data-avd-header-deco' => $structure['header_deco'],
            'data-avd-sidebar-style' => $structure['sidebar_style'],
            'data-avd-hero-deco' => $structure['hero_deco'],
            'data-avd-card-frame' => $structure['card_frame'],
            'data-avd-chip-style' => $structure['chip_style'],
            'data-avd-section-style' => $structure['section_style'],
            'data-avd-avatar-shape' => $structure['avatar_shape'],
            'data-avd-backdrop' => $structure['backdrop'],
        ];
    }

    /**
     * ?sidebar_style= preview > the configured style (config('site.sidebar_style'), which AppearanceSettings v2 sets
     * to the effective value; the stored setting before that) > full. The retired header style sidebar_rail still
     * means a rail unless another side menu was picked (the rule of AppearanceSettings::effectiveSidebarStyle()).
     * "Side menu open on computers" does not change the style, only whether it is pinned (sidebarPinned()).
     */
    private static function resolveSidebarStyle(string $headerStyle): string
    {
        $preview = request()->query('sidebar_style');

        if (is_string($preview) && in_array($preview, self::SIDEBAR_STYLES, true)) {
            return $preview;
        }

        $configured = config('site.sidebar_style') ?? (Setting::getGroup('appearance')['sidebar_style'] ?? null);
        $style = is_string($configured) && in_array($configured, self::SIDEBAR_STYLES, true) ? $configured : 'full';

        return $headerStyle === 'sidebar_rail' && $style === 'full' ? 'rail' : $style;
    }

    /**
     * The v2 theme of this request. AppearanceSettings v2 resolves previews, daily designs and old ids into
     * site.theme_id; before it exists the ?theme= preview or the stored preset is used (old ids resolve when the
     * catalogue can).
     */
    private static function themeId(): string
    {
        $resolved = config('site.theme_id');

        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        $preview = request()->query('theme', request()->query('theme_preset'));
        $theme = is_string($preview) && self::knownTheme($preview)
            ? $preview
            : (string) config('site.theme_preset', '');

        $resolvedTheme = ThemeCatalog::resolve($theme);

        return is_string($resolvedTheme['theme'] ?? null) ? $resolvedTheme['theme'] : $theme;
    }

    private static function knownTheme(string $id): bool
    {
        return is_array(ThemeCatalog::resolve($id));
    }

    private static function recipe(string $themeId): array
    {
        return ThemeCatalog::recipe($themeId);
    }

    private static function option(string $key, array $allowed, string $fallback): string
    {
        $value = self::queryOrConfig($key, $fallback);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** A ?key= preview (scalars only) over config('site.key'), as the layout's own preview helpers read it. */
    private static function queryOrConfig(string $key, mixed $default): mixed
    {
        $preview = request()->query($key);

        return is_scalar($preview) ? $preview : config('site.'.$key, $default);
    }

    private static function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
