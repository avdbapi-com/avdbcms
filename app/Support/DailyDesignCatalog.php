<?php

namespace App\Support;

use App\Models\Setting;
use DateTimeImmutable;
use Throwable;

/**
 * The Daily Design rule (redesign 2026-10, reference daily_rule.py): every day shows a theme and colour style
 * of the vetted catalogue, so every day passes the gallery's contrast checks. Deterministic, no cron, no
 * database writes: every node shows the same design.
 *
 * palette_only ("Colours only", the new-install default) keeps the owner's theme and rotates its colour
 * styles; a holiday takes the style nearest its colour. full ("Theme too") picks a theme from the season's
 * pool; holidays have their own theme; the homepage layout follows only with daily_design_layouts_enabled.
 * A year is computed forward from 1 January: a regular day never repeats yesterday's look nor takes
 * tomorrow's holiday look.
 */
class DailyDesignCatalog
{
    public const SCOPE_PALETTE = 'palette_only';

    public const SCOPE_FULL = 'full';

    public const DEFAULT_THEME = 'tokyo_neon';

    // The catalogue as the rule sees it (theme_spec.json): "theme homepage-layout Name", then the theme's colour
    // styles in catalogue order, "  style brand brand-2 accent Name".
    private const CATALOGUE = <<<'TEXT'
    tokyo_neon youtube_spotlight Midnight Spotlight
      signature #ff0055 #7928ca #00dfd8 Hot Pink
      coral #ff3d61 #ff8b5c #18c8d8 AVDBCMS Coral
      electric #2f6bff #7c3aed #22d3ee Electric Blue
      sunset #ff5a1f #e11d74 #ffc23d Sunset
      ultraviolet #8b5cf6 #ec4899 #22d3ee Ultraviolet
      volt #b8f400 #00c2a8 #7cf9ff Volt
      emerald #10b981 #0ea5e9 #a3e635 Emerald
    daylight_paper grid_modern Daylight Studio
      signature #1f5eff #0b3fd1 #e5252a Daylight Blue
      tube_red #e5252a #b3161b #1f5eff Tube Red
      violet #5b3df5 #4338ca #06b6d4 Indigo
      teal #0f8b8d #0b6466 #ea580c Teal
      graphite #18181b #3f3f46 #e5252a Graphite
      forest #15803d #166534 #ea580c Forest
    clean_graphite youtube_spotlight Graphite Pro
      signature #c5f82a #6366f1 #22d3ee Volt
      signal #ff6a00 #f59e0b #22d3ee Signal Orange
      ice #7dd3fc #6366f1 #c5f82a Ice
      mono #e4e4e7 #71717a #a1a1aa Mono
      cobalt #3b82f6 #6366f1 #22d3ee Cobalt
      magenta #f472b6 #a855f7 #22d3ee Magenta
    pure_oled_black cinema_carousel Noir Premiere
      signature #e50914 #b20710 #f5c518 Premiere Red
      crimson #e11d48 #9f1239 #fb7185 Crimson
      amber #f59e0b #b45309 #fde68a Amber
      violet #8b5cf6 #5b21b6 #c4b5fd Violet
      emerald #10b981 #047857 #6ee7b7 Emerald
      electric #1d6bff #1e3a8a #93c5fd Electric Blue
    silver_screen grid_modern Silver Screen
      signature #f2ede4 #bab2a5 #e9b949 Ivory
      sepia #d8b48a #a07a52 #e9b949 Sepia
      nitrate #a9c1d6 #6c8aa6 #e9b949 Nitrate Blue
      curtain #e06666 #a33a3a #e9b949 Curtain Red
      verdigris #9fd3b5 #5f9c7d #e9b949 Verdigris
    shibuya_rain cyberpunk_hud Shibuya Rain
      signature #ff2a85 #8a2be2 #00e0ff Shibuya
      plasma #f72585 #7209b7 #4cc9f0 Plasma
      acid #ccff00 #ff0055 #00e5ff Acid
      ice #00f0ff #7000ff #ff007b Ice
      akira #ff1744 #d50000 #ff9100 Akira
    electric_violet bento_mosaic Ultraviolet Club
      signature #b14bff #ff4fd8 #39ff88 Ultraviolet
      hot_pink #ff2e9a #b14bff #39ff88 Hot Pink
      laser #39ff88 #b14bff #ff4fd8 Laser Lime
      ice #4cc9f0 #7209b7 #f72585 Ice
      amber #ffb000 #ff4fd8 #b14bff Amber
    synthwave_sunset cinema_carousel Outrun
      signature #ff3d81 #ffb000 #2de2e6 Sunset
      vice #ff2a6d #05d9e8 #d1f7ff Vice
      purple_rain #b967ff #ff71ce #01cdfe Purple Rain
      tangerine #ff6c11 #ffd319 #ff2975 Tangerine
    vaporwave_mall grid_modern Vaporwave '95
      signature #00807f #d63fa0 #6b4ff0 Mall
      arizona #d6338a #1c8ad6 #2ec27e Iced Tea
      classic #000080 #008080 #c0c0c0 Classic 95
      tape #c2410c #ca8a04 #2563eb Mixtape
    obsidian_gold bento_mosaic Obsidian & Gold
      signature #d4af37 #f3e5ab #f3e5ab 24K Gold
      platinum #d9dde3 #9aa3ad #ffffff Platinum
      rose #e8a598 #b76e79 #f3d1c8 Rose Gold
      emerald #34c38f #d4af37 #f3e5ab Emerald & Gold
      sapphire #6f9cff #c0d4ff #e8eeff Sapphire
      bronze #cd8a47 #f0c48a #f0c48a Bronze
      amethyst #b38ae6 #e6d3ff #e6d3ff Amethyst
    rose_gold_velvet cinema_carousel Velvet Boudoir
      signature #e8a598 #b4446a #ffd7a8 Rose Gold
      crimson #ff5c7a #9e1b3c #ffd7a8 Crimson Velvet
      amethyst #c39bff #7a3fb0 #ffd7a8 Amethyst
      champagne #ecd2a0 #a8794a #ffe9c7 Champagne
      cherry #ff4d6d #7a0f2a #ffb3c1 Cherry Noir
    ivory_salon bento_mosaic Ivory Salon
      signature #8a6420 #241c15 #3f5f55 Bronze
      slate #4f5b66 #241c15 #8a6420 Platinum Slate
      oxblood #7a1f2b #241c15 #8a6420 Oxblood
      sapphire #1f3f7a #241c15 #8a6420 Sapphire
      sage #3f6648 #241c15 #8a6420 Sage
    swiss_monolith bento_mosaic Swiss Grid
      signature #e4002b #0a0a0a #e4002b Swiss Red
      blue #0038ff #0a0a0a #0038ff Klein Blue
      yellow #ffd400 #0a0a0a #0a0a0a Signal Yellow
      bauhaus #e4002b #0038ff #ffd400 Bauhaus
      green #008a3e #0a0a0a #008a3e Swiss Green
    broadsheet grid_modern Broadsheet
      signature #b3001b #161513 #8a5a00 Press Red
      amber #a14f00 #161513 #b3001b Amber Ink
      navy #1d3557 #161513 #b3001b Navy
      green #2d6a4f #161513 #8a5a00 Ledger Green
      plum #6a1b4d #161513 #8a5a00 Plum
    nordic_minimal grid_modern Nordic Calm
      signature #3d5a80 #98c1d9 #c2553a Fjord
      frost #2f6f8f #88c0d0 #c2553a Frost
      birch #7a5c3e #c8b49b #3d5a80 Birch
      lingon #a3283a #e7a1aa #3d5a80 Lingonberry
      moss #4a6b47 #a8c3a0 #c2553a Moss
    amazon_rainforest grid_modern Rainforest
      signature #4ade80 #166534 #fbbf24 Fern
      jade #2dd4bf #0f766e #fbbf24 Jade
      moss #a3e635 #4d7c0f #fbbf24 Lime Moss
      autumn #f59e0b #9a3412 #4ade80 Autumn Leaf
      orchid #e879f9 #86198f #4ade80 Orchid
    sahara_dune cinema_carousel Desert Dune
      signature #e2774a #e9b44c #7fb7be Terracotta
      ochre #e9b44c #e2774a #7fb7be Ochre
      canyon #e25b3c #9a3412 #e9b44c Canyon Red
      sage #a8bd8f #5f7a4a #e9b44c Desert Sage
      dusk #c09ad0 #7a4f8f #e9b44c Dusk Violet
    matcha_zen grid_modern Kyoto Zen
      signature #c8381f #5c7a3a #1f3a5f Vermilion
      matcha #4f6b2f #c8381f #1f3a5f Matcha
      indigo #1f3a5f #c8381f #5c7a3a Aizome Indigo
      sakura #b5486a #5c7a3a #1f3a5f Sakura
      kin #8a6a12 #c8381f #1f3a5f Gold Leaf
    deep_trench cinema_carousel Deep Trench
      signature #2f8cff #1e3a8a #3ef0d0 Abyss Blue
      lagoon #06b6d4 #0e7490 #a7f3d0 Lagoon
      coral #ff6f61 #be123c #3ef0d0 Coral Reef
      kelp #34d399 #047857 #7dd3fc Kelp
      storm #7aa2c8 #334155 #38bdf8 Atlantic Storm
      angler #ffcc33 #1e3a8a #3ef0d0 Anglerfish
    interstellar youtube_spotlight Interstellar
      signature #7c8cff #c084fc #ffcf6e Nebula
      orion #a78bfa #ec4899 #38bdf8 Orion
      solar #fb923c #ef4444 #fde047 Solar Flare
      pulsar #22d3ee #0284c7 #a5f3fc Pulsar
      supernova #facc15 #ca8a04 #fef08a Supernova
      event_horizon #e879f9 #701a75 #38bdf8 Event Horizon
      mars #f87171 #b91c1c #fdba74 Mars
    aurora_borealis bento_mosaic Aurora Glass
      signature #5eead4 #a78bfa #86efac Borealis
      violet #c4b5fd #5eead4 #f0abfc Polar Violet
      solar #fcd34d #fb7185 #5eead4 Solar Wind
      arctic #7dd3fc #a78bfa #5eead4 Arctic
      rose #f9a8d4 #a78bfa #5eead4 Rose Aurora
    lavender_cloud youtube_spotlight Liquid Glass
      signature #6b4eff #ff7eb6 #1677c8 Iris
      peach #e8590c #ff7eb6 #6b4eff Peach
      sky #1c7ed6 #6b4eff #e64980 Sky
      mint #0c8a6c #6b4eff #e64980 Mint
      rose #d6336c #6b4eff #1677c8 Rose
    holo_pearl bento_mosaic Holo Pearl
      signature #14151f #7c6cff #008f87 Pearl
      opal #c2187a #7c6cff #008f87 Opal Pink
      aqua #00756f #4c6ef5 #c2187a Aqua Holo
      chrome #3d4452 #8b95a7 #008f87 Chrome
    bubblegum_pop tiktok_shorts Bubblegum Pop
      signature #e0287a #ff9f5a #6d4ce6 Bubblegum
      strawberry #e23a54 #ff9f5a #6d4ce6 Strawberry
      peach #e8590c #ff7eb6 #6d4ce6 Peach
      grape #7048e8 #ff7eb6 #e0287a Grape
      mint #0b8a6f #ff9f5a #e0287a Mint
      lemon #f5c400 #ff7eb6 #6d4ce6 Lemon
    fairy_floss tiktok_shorts Fairy Floss
      signature #f2a7ff #9ef0dd #ffd29e Fairy Floss
      sakura #ffb3d1 #c4b5fd #9ef0dd Sakura
      mint #9ef0dd #f2a7ff #ffd29e Mint Soda
      lavender #c4b5fd #f2a7ff #9ef0dd Lavender
      cotton #9ad7ff #ffb3d1 #ffd29e Cotton Candy
    cyber_gladiator grid_modern Arena
      signature #ff4655 #ece8e1 #ffd23f Arena Red
      crimson #e11d48 #ece8e1 #ffd23f Crimson
      hyper #ff6600 #ece8e1 #ffd23f Hyperdrive
      cyan #00e5ff #ece8e1 #ff4655 Cyanide
      purple #9f7aea #ece8e1 #ffd23f Royal Purple
    razer_poison cyberpunk_hud Toxic
      signature #44d62c #1f6e14 #ffe14d Poison
      acid #c6ff00 #5c7a00 #ff2e88 Acid
      venom #b46bff #5b21b6 #44d62c Venom
      alien #00e5ff #0e7490 #ffe14d Alien Cyan
      radioactive #f2ff00 #7a8000 #44d62c Radioactive
    overwatch_pulse bento_mosaic Pulse
      signature #f99e1a #1f6fd1 #1f6fd1 Pulse
      portal #ff7a1a #0a84ff #0a84ff Portal
      blue_team #2b8cff #f99e1a #f99e1a Blue Team
      red_team #ff4655 #1f6fd1 #1f6fd1 Red Team
      lime #8fe000 #1f6fd1 #1f6fd1 Lime
    dracula_official grid_modern Code Editor
      signature #bd93f9 #ff79c6 #50fa7b Dracula
      nord #88c0d0 #81a1c1 #a3be8c Nord
      catppuccin #cba6f7 #f38ba8 #a6e3a1 Catppuccin Mocha
      gruvbox #fabd2f #fe8019 #b8bb26 Gruvbox
      solarized #268bd2 #2aa198 #b58900 Solarized
      monokai #ffd866 #ff6188 #a9dc76 Monokai Pro
      one_dark #61afef #c678dd #98c379 One Dark
      tokyo_night #7aa2f7 #bb9af7 #9ece6a Tokyo Night
      synthwave84 #ff7edb #36f9f6 #72f1b8 Synthwave '84
      cobalt #ffc600 #ff9d00 #3ad900 Cobalt
    cyber_matrix cyberpunk_hud Phosphor
      signature #33ff66 #00b34a #ffb000 Green Phosphor
      amber #ffb000 #b37b00 #ff5f5f Amber CRT
      ice #7df9ff #00a8b5 #ffb000 Ice Terminal
      mono #e8e8e8 #9a9a9a #ffb000 White Mono
      alert #ff4d4d #b31d1d #ffb000 Red Alert
    neo_pop grid_modern Neo Pop
      signature #ff5ea8 #ffd43b #3fd17a Pop
      lemon #ffd43b #ff5ea8 #4dabf7 Lemon
      sky #4dabf7 #ffd43b #ff5ea8 Sky
      lime #a9e34b #ff5ea8 #4dabf7 Lime
      tangerine #ff922b #4dabf7 #a9e34b Tangerine
      lilac #b197fc #ffd43b #3fd17a Lilac
    clean_concrete grid_modern Raw Concrete
      signature #111111 #ff4d00 #ffe600 Marker Yellow
      orange #111111 #ffe600 #ff7a1a Safety Orange
      green #111111 #ff4d00 #3ee07f Signal Green
      pink #111111 #ffe600 #ff5cb0 Hot Pink
      blue #111111 #ff4d00 #7ab8ff Blueprint
    TEXT;

    // The themes the full scope rotates through, per season.
    private const SEASON_POOLS = [
        'winter' => ['obsidian_gold', 'pure_oled_black', 'matcha_zen', 'nordic_minimal', 'interstellar', 'aurora_borealis', 'silver_screen', 'tokyo_neon'],
        'spring' => ['bubblegum_pop', 'fairy_floss', 'lavender_cloud', 'amazon_rainforest', 'matcha_zen', 'daylight_paper', 'holo_pearl', 'tokyo_neon'],
        'summer' => ['synthwave_sunset', 'deep_trench', 'sahara_dune', 'neo_pop', 'vaporwave_mall', 'shibuya_rain', 'overwatch_pulse', 'tokyo_neon'],
        'autumn' => ['sahara_dune', 'rose_gold_velvet', 'silver_screen', 'broadsheet', 'amazon_rainforest', 'cyber_gladiator', 'ivory_salon', 'tokyo_neon'],
    ];

    // [label, date, theme, colour style, hue]; the date is 'mm-dd', '<1st|2nd|3rd|4th|last>-<weekday>-mm' or
    // 'day-after-<date>'. The first holiday of a date wins.
    private const HOLIDAYS = [
        ["New Year's Day", '01-01', 'obsidian_gold', 'signature', 85],
        ['Martin Luther King Jr. Day', '3rd-mon-01', 'broadsheet', 'navy', 255],
        ["Valentine's Day", '02-14', 'rose_gold_velvet', 'crimson', 10],
        ['Leap Day Special', '02-29', 'holo_pearl', 'signature', 300],
        ["International Women's Day", '03-08', 'lavender_cloud', 'signature', 295],
        ["St. Patrick's Day", '03-17', 'amazon_rainforest', 'signature', 145],
        ['Spring Equinox', '03-20', 'matcha_zen', 'sakura', 350],
        ["April Fools' Day", '04-01', 'neo_pop', 'signature', 350],
        ['Earth Day', '04-22', 'amazon_rainforest', 'jade', 175],
        ['Star Wars Day', '05-04', 'interstellar', 'signature', 275],
        ['Memorial Day', 'last-mon-05', 'broadsheet', 'navy', 255],
        ['Summer Solstice', '06-21', 'synthwave_sunset', 'signature', 50],
        ['Independence Day', '07-04', 'swiss_monolith', 'bauhaus', 25],
        ['Perseid Meteor Shower', '08-12', 'interstellar', 'event_horizon', 320],
        ['Autumn Preview', '09-01', 'sahara_dune', 'signature', 45],
        ['Autumn Equinox', '09-22', 'matcha_zen', 'kin', 80],
        ['Halloween', '10-31', 'synthwave_sunset', 'tangerine', 55],
        ['Veterans Day', '11-11', 'silver_screen', 'signature', 85],
        ['Thanksgiving', '4th-thu-11', 'sahara_dune', 'ochre', 75],
        ['Black Friday', 'day-after-4th-thu-11', 'pure_oled_black', 'signature', 25],
        ['Christmas Eve', '12-24', 'amazon_rainforest', 'signature', 150],
        ['Christmas Day', '12-25', 'pure_oled_black', 'signature', 25],
        ["New Year's Eve", '12-31', 'obsidian_gold', 'signature', 85],
    ];

    // The owner's layout keys every entry carries for the v1 callers (the rule never changes them).
    private const OWNER_KEYS = ['home_layout_mode', 'surface_style', 'radius_scale', 'font_family', 'header_style', 'category_card_style',
        'creator_avatar_ring_style', 'video_card_duration_style', 'watch_ambient_glow_intensity', 'scrollbar_style'];

    // The parsed catalogue, memoised years ('year|scope|theme' => picks) and brand hues.
    protected static array $themes = [];

    protected static array $schedules = [];

    protected static array $chromaHues = [];

    /**
     * Day N (1-366) of a year, this year by default (366 in a common year = 1 January of the next). $settings are
     * the appearance settings, or only the owner's theme id; the stored settings fill in the rest. A day's pick
     * is cached for two days.
     */
    public static function forDay(int $day, ?string $scope = null, array|string|null $settings = null, ?int $year = null): array
    {
        $day = max(1, min(366, $day));
        $date = static::firstOfYear($year ?? now()->year)->modify('+' . ($day - 1) . ' days');
        $context = static::context($scope, $settings);
        $key = 'daily_design.v2.' . $date->format('Y-m-d') . '.' . $context['scope'] . '.' . $context['theme'];
        $compute = fn (): array => static::schedule((int) $date->format('Y'), $context['scope'], $context['theme'])[(int) $date->format('z')];
        $pick = SafeCache::remember($key, 172800, $compute);

        // A cached pick the catalogue no longer knows is computed again.
        if (!is_array($pick) || !isset(static::themes()[$pick['theme'] ?? ''][2][$pick['flavor'] ?? ''])) {
            $pick = $compute();
        }

        return static::entry($date, $pick, $context, $day);
    }

    public static function today(?string $scope = null, array|string|null $settings = null): array
    {
        $now = now();

        return static::forDay($now->dayOfYear, $scope, $settings, $now->year);
    }

    /**
     * The day after an entry (today() by default): the next day of its year, or 1 January of the next year after its
     * last day (31 December is day 365, or 366 in a leap year). Use it instead of ($day % 366) + 1.
     */
    public static function tomorrow(?array $entry = null, ?string $scope = null, array|string|null $settings = null): array
    {
        $entry ??= static::today($scope, $settings);
        $next = (new DateTimeImmutable((string) ($entry['date'] ?? now()->format('Y-m-d'))))->modify('+1 day');

        return static::forDay((int) $next->format('z') + 1, $scope, $settings, (int) $next->format('Y'));
    }

    /** 365, or 366 in a leap year (the number of daily designs of that year). */
    public static function daysInYear(?int $year = null): int
    {
        return checkdate(2, 29, $year ?? now()->year) ? 366 : 365;
    }

    /**
     * Every day of one month (the admin calendar), keyed by day of the year.
     */
    public static function forMonth(int $month, ?string $scope = null, array|string|null $settings = null, ?int $year = null): array
    {
        $month = max(1, min(12, $month));

        return array_filter(static::all($scope, $settings, $year), fn (array $day): bool => $day['month'] === $month);
    }

    /**
     * Every day of a year, keyed by day of the year.
     */
    public static function all(?string $scope = null, array|string|null $settings = null, ?int $year = null): array
    {
        $first = static::firstOfYear($year ?? now()->year);
        $context = static::context($scope, $settings);
        $days = [];

        foreach (static::schedule((int) $first->format('Y'), $context['scope'], $context['theme']) as $i => $pick) {
            $days[$i + 1] = static::entry($first->modify('+' . $i . ' days'), $pick, $context, $i + 1);
        }

        return $days;
    }

    public static function months(?int $year = null): array
    {
        $year ??= now()->year;
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $months[$month] = ['number' => $month, 'name' => $first->format('F'), 'short' => $first->format('M'), 'days_count' => (int) $first->format('t')];
        }

        return $months;
    }

    /**
     * The bare rule: one [theme, flavor, holiday, reason] per day of the year.
     */
    public static function schedule(int $year, string $scope = self::SCOPE_PALETTE, string $baseTheme = self::DEFAULT_THEME): array
    {
        $scope = $scope === self::SCOPE_FULL ? self::SCOPE_FULL : self::SCOPE_PALETTE;
        $baseTheme = isset(static::themes()[$baseTheme]) ? $baseTheme : self::DEFAULT_THEME;
        $key = $year . '|' . $scope . '|' . ($scope === self::SCOPE_FULL ? '' : $baseTheme);

        if (isset(static::$schedules[$key])) {
            return static::$schedules[$key];
        }

        $holidays = [];

        foreach (self::HOLIDAYS as $holiday) {
            $date = static::holidayDate($holiday[1], $year);

            if ($date !== null) {
                $holidays[(int) $date->format('z')] ??= $holiday;
            }
        }

        $months = [];

        foreach ([31, 28 + (int) static::firstOfYear($year)->format('L'), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31] as $i => $length) {
            array_push($months, ...array_fill(0, $length, $i + 1));
        }

        $count = count($months);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $pick = static::natural($year, $i, $months[$i], $scope, $baseTheme, $holidays);

            if ($pick['holiday'] === null) {
                $avoid = $i > 0 ? [static::lookKey($out[$i - 1], $scope)] : [];
                $next = $i + 1 < $count ? static::natural($year, $i + 1, $months[$i + 1], $scope, $baseTheme, $holidays) : null;

                if ($next !== null && $next['holiday'] !== null) {
                    $avoid[] = static::lookKey($next, $scope);
                }

                $pick = static::avoid($pick, $avoid, $year, $i, $months[$i], $scope);
            }

            $out[] = $pick;
        }

        return static::$schedules[$key] = $out;
    }

    /**
     * The theme palette_only keeps: the owner's theme_preset (an old id through ThemeCatalog's aliases).
     */
    public static function baseTheme(?string $themePreset): string
    {
        $themePreset = (string) $themePreset;

        if (isset(static::themes()[$themePreset])) {
            return $themePreset;
        }

        if ($themePreset !== '' && method_exists(ThemeCatalog::class, 'resolve')) {
            try {
                $resolved = ThemeCatalog::resolve($themePreset);
                $theme = is_array($resolved) ? ($resolved['theme'] ?? null) : null;
                $theme = is_array($theme) ? ($theme['id'] ?? null) : $theme;

                if (is_string($theme) && isset(static::themes()[$theme])) {
                    return $theme;
                }
            } catch (Throwable) {
                // An unknown id keeps the default theme.
            }
        }

        return self::DEFAULT_THEME;
    }

    public static function themeIds(): array
    {
        return array_keys(static::themes());
    }

    /**
     * The colour styles the rule rotates for a theme, in catalogue order.
     */
    public static function flavorIds(string $themeId): array
    {
        return array_keys(static::themes()[$themeId][2] ?? []);
    }

    public static function flush(): void
    {
        static::$schedules = [];
    }

    /**
     * A day as its callers read it: the rule's keys, then the v1 ones (the colour style's seeds, the owner's layout).
     */
    private static function entry(DateTimeImmutable $date, array $pick, array $context, int $day): array
    {
        [$themeName, $homeLayout, $flavors] = static::themes()[$pick['theme']];
        [$primary, $secondary, $accent, $flavorName] = $flavors[$pick['flavor']];
        $layout = $context['scope'] === self::SCOPE_FULL && $context['layouts'] ? $homeLayout : null;
        $label = $themeName . ' · ' . $flavorName;

        return [
            'theme' => $pick['theme'],
            'flavor' => $pick['flavor'],
            'layout' => $layout,
            'label' => $label,
            'holiday' => $pick['holiday'],
            'date' => $date->format('Y-m-d'),
            'year' => (int) $date->format('Y'),
            'scope' => $context['scope'],
            'reason' => $pick['reason'],
            'theme_name' => $themeName,
            'flavor_name' => $flavorName,
            'day' => $day,
            'month' => (int) $date->format('n'),
            'day_of_month' => (int) $date->format('j'),
            'date_label' => $date->format('M d'),
            'name' => $label,
            'season_mood' => ucfirst(static::season((int) $date->format('n'))),
            'primary' => $primary,
            'secondary' => $secondary,
            'accent' => $accent,
            'home_layout_mode' => $layout ?? $context['owner']['home_layout_mode'],
        ] + $context['owner'];
    }

    /**
     * What a day depends on: scope, base theme, daily layouts, the owner's layout keys.
     */
    private static function context(?string $scope, array|string|null $settings): array
    {
        $theme = is_string($settings) ? $settings : null;
        $settings = is_array($settings) ? $settings : Setting::getGroup('appearance');
        // v2 keeps the v1 defaults for sites that have not finished the update.
        $defaults = method_exists(AppearanceSettings::class, 'fallbacks') ? AppearanceSettings::fallbacks($settings) : AppearanceSettings::defaults();
        $scope ??= (string) ($settings['daily_design_scope'] ?? $defaults['daily_design_scope'] ?? self::SCOPE_PALETTE);
        $owner = [];

        foreach (self::OWNER_KEYS as $key) {
            $owner[$key] = (string) ($settings[$key] ?? $defaults[$key] ?? '');
        }

        return [
            'scope' => $scope === self::SCOPE_FULL ? self::SCOPE_FULL : self::SCOPE_PALETTE,
            'theme' => static::baseTheme($theme ?? $settings['theme_preset'] ?? $defaults['theme_preset'] ?? null),
            'layouts' => (string) ($settings['daily_design_layouts_enabled'] ?? $defaults['daily_design_layouts_enabled'] ?? '0') === '1',
            'owner' => $owner,
        ];
    }

    /**
     * A day's pick before it is checked against yesterday and tomorrow's holiday.
     */
    private static function natural(int $year, int $index, int $month, string $scope, string $baseTheme, array $holidays): array
    {
        $holiday = $holidays[$index] ?? null;

        if ($scope === self::SCOPE_PALETTE && $holiday !== null) {
            return ['theme' => $baseTheme, 'flavor' => static::nearestFlavor($baseTheme, $holiday[4]), 'holiday' => $holiday[0], 'reason' => "holiday colour, owner's theme kept"];
        }

        if ($holiday !== null) {
            return ['theme' => $holiday[2], 'flavor' => $holiday[3], 'holiday' => $holiday[0], 'reason' => 'holiday edition'];
        }

        $theme = $baseTheme;
        $reason = 'flavor rotation';

        if ($scope === self::SCOPE_FULL) {
            $pool = self::SEASON_POOLS[static::season($month)];
            $theme = $pool[(($index + 1) * 5 + $year) % count($pool)];
            $reason = static::season($month) . ' pool';
        }

        $flavors = array_keys(static::themes()[$theme][2]);

        return ['theme' => $theme, 'flavor' => $flavors[($index + 1 + $year) % count($flavors)], 'holiday' => null, 'reason' => $reason];
    }

    /**
     * Move a regular day off the looks it must not repeat: the next colour style (palette_only) or the next
     * theme of the season's pool (full).
     */
    private static function avoid(array $pick, array $avoid, int $year, int $index, int $month, string $scope): array
    {
        $palette = $scope === self::SCOPE_PALETTE;
        $ring = $palette ? array_keys(static::themes()[$pick['theme']][2]) : self::SEASON_POOLS[static::season($month)];
        $k = (int) array_search($palette ? $pick['flavor'] : $pick['theme'], $ring, true);

        for ($tries = 0; $tries < count($ring) && in_array($palette ? $pick['theme'] . '/' . $ring[$k] : $ring[$k], $avoid, true); $tries++) {
            $k = ($k + 1) % count($ring);
        }

        if ($palette) {
            $pick['flavor'] = $ring[$k];
        } elseif ($ring[$k] !== $pick['theme']) {
            $flavors = array_keys(static::themes()[$ring[$k]][2]);
            $pick['theme'] = $ring[$k];
            $pick['flavor'] = $flavors[($index + 1 + $year) % count($flavors)];
        }

        return $pick;
    }

    /**
     * CATALOGUE parsed once per request: id => [name, homepage layout, [style id => [brand, brand 2, accent, name]]].
     */
    private static function themes(): array
    {
        if (static::$themes === []) {
            foreach (explode("
", self::CATALOGUE) as $line) {
                if ($line[0] !== ' ') {
                    [$theme, $layout, $name] = explode(' ', $line, 3);
                    static::$themes[$theme] = [$name, $layout, []];
                } else {
                    [$flavor, $brand, $brand2, $accent, $name] = explode(' ', ltrim($line), 5);
                    static::$themes[$theme][2][$flavor] = [$brand, $brand2, $accent, $name];
                }
            }
        }

        return static::$themes;
    }

    private static function lookKey(array $pick, string $scope): string
    {
        return $scope === self::SCOPE_PALETTE ? $pick['theme'] . '/' . $pick['flavor'] : $pick['theme'];
    }

    /**
     * The colour style whose brand hue is nearest a holiday's hue; greys are a poor match.
     */
    private static function nearestFlavor(string $theme, int $hue): string
    {
        $best = '';
        $bestDistance = INF;

        foreach (static::themes()[$theme][2] as $id => $flavor) {
            [$chroma, $brandHue] = static::chromaHue($flavor[0]);
            $distance = min(abs($brandHue - $hue), 360 - abs($brandHue - $hue)) + ($chroma < 0.04 ? 40 : 0);

            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$id, $distance];
            }
        }

        return $best;
    }

    /**
     * OKLCH chroma and hue of a #rrggbb colour (as the redesign's colorlib.to_oklch).
     */
    private static function chromaHue(string $hex): array
    {
        if (isset(static::$chromaHues[$hex])) {
            return static::$chromaHues[$hex];
        }

        $rgb = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));
        $cbrt = fn (float $x): float => $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
        $l = $cbrt(0.4122214708 * $rgb[0] + 0.5363325363 * $rgb[1] + 0.0514459929 * $rgb[2]);
        $m = $cbrt(0.2119034982 * $rgb[0] + 0.6806995451 * $rgb[1] + 0.1073969566 * $rgb[2]);
        $s = $cbrt(0.0883024619 * $rgb[0] + 0.2817188376 * $rgb[1] + 0.6299787005 * $rgb[2]);
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $b = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
        $hue = atan2($b, $a) * (180 / M_PI);

        return static::$chromaHues[$hex] = [hypot($a, $b), $hue < 0 ? $hue + 360 : $hue];
    }

    private static function holidayDate(string $rule, int $year): ?DateTimeImmutable
    {
        if (preg_match('/^(\d\d)-(\d\d)$/', $rule, $m)) {
            return checkdate((int) $m[1], (int) $m[2], $year) ? new DateTimeImmutable("$year-$rule") : null;
        }

        if (str_starts_with($rule, 'day-after-')) {
            return static::holidayDate(substr($rule, 10), $year)?->modify('+1 day');
        }

        [$nth, $weekday, $month] = explode('-', $rule);
        $want = array_search($weekday, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true) + 1;
        $first = new DateTimeImmutable("$year-$month-01");

        if ($nth === 'last') {
            $last = $first->modify('last day of this month');

            return $last->modify('-' . (((int) $last->format('N') - $want + 7) % 7) . ' days');
        }

        return $first->modify('+' . (($want - (int) $first->format('N') + 7) % 7 + 7 * ((int) $nth - 1)) . ' days');
    }

    private static function season(int $month): string
    {
        return match ($month) {
            12, 1, 2 => 'winter',
            3, 4, 5 => 'spring',
            6, 7, 8 => 'summer',
            default => 'autumn',
        };
    }

    private static function firstOfYear(int $year): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-01-01', $year));
    }
}
