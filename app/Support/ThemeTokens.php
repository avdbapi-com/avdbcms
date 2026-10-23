<?php

namespace App\Support;

use App\Support\Color\Contrast;
use App\Support\Color\Oklch;
use Throwable;

/**
 * Theme collection v2: derives every theme's design tokens (palette, brand roles, status colours,
 * typography, shape, header, sidebar, chips, buttons, cards, hero, section and backdrop decor,
 * Bootstrap bridge) from the theme definition with the ONE colour engine (App\Support\Color\Contrast),
 * and writes the per-theme token CSS that App\Support\ThemeCss serves.
 *
 * `php artisan avd:themes:build` writes resources/css/themes/<id>.css: one block per mode keyed on
 * html[data-bs-theme] and mirrored clip-paths and corner radii under [dir="rtl"]; and per other colour
 * style (flavor) resources/css/themes/<id>/<flavor>.css with the tokens that differ, keyed on
 * [data-avd-flavor]. derive() is a port of the redesign's build_spec.derive (reference token files:
 * redesign spec/theme/tokens, whose blocks the files of a theme hold between them), so the build
 * reproduces them to within one sRGB step per channel.
 *
 * The owner's custom colours run through the same derive() at render time (ownerOverrides()).
 */
final class ThemeTokens
{
    public const DEFAULT_THEME = 'tokyo_neon';

    public const DEFAULT_FLAVOR = 'signature';

    /** Bump when derive() changes, so cached owner-colour blocks are rebuilt. */
    public const ENGINE_VERSION = '2.2';

    /** Hero decoration sources under resources/css/themes (a hyphenated name: no theme id, no Vite token entry). */
    public const HERO_DECO_DIR = 'hero-decos';

    /** Font families with Cyrillic glyphs (theme spec typography.coverage): others fall back to the body face on ru pages. */
    public const CYRILLIC_FAMILIES = ['Cormorant Garamond', 'EB Garamond', 'Exo 2', 'Geist', 'IBM Plex Mono', 'IBM Plex Sans', 'Inter',
        'JetBrains Mono', 'Manrope', 'Montserrat', 'Nunito', 'Onest', 'Playfair Display', 'Plus Jakarta Sans', 'Roboto', 'Tektur', 'Unbounded'];

    /** The 9 themes built and signed off first (Gate A): their colours stay as accepted there (see build()). */
    public const PHASE_A_THEMES = ['tokyo_neon', 'pure_oled_black', 'daylight_paper', 'rose_gold_velvet', 'fairy_floss', 'shibuya_rain',
        'broadsheet', 'lavender_cloud', 'obsidian_gold'];

    /**
     * Viewports (width x height in px) the page backdrop is sampled over (backdropPeak()): small and large phones,
     * a tablet, laptops and a full-HD desktop. The backdrop is fixed to the viewport, so where its glows land depends
     * on the screen.
     */
    private const BACKDROP_VIEWPORTS = [[360, 740], [390, 844], [430, 932], [768, 1024], [1280, 800], [1440, 900], [1920, 1080]];

    /** Page text starts below the bar: the shortest header (slim, 56px) is the top of the sampled area. */
    private const BACKDROP_TOP = 56;

    /** Grid step (px) of the backdrop sampling. */
    private const BACKDROP_STEP = 13;

    /** Static (non-variable) families of the v1 font list: Google needs their weights listed one by one. */
    private const STATIC_FAMILY_SPECS = ['Poppins' => 'Poppins:wght@400;500;600;700'];

    /** Owner colour settings and the palette role each one replaces. */
    public const OWNER_COLOURS = [
        'primary_color' => 'brand',
        'brand_secondary_color' => 'brand2',
        'accent_color' => 'accent',
        'dark_bg_color' => 'bg',
        'dark_surface_color' => 's1',
        'light_bg_color' => 'bg',
        'light_surface_color' => 's1',
    ];

    private const MOTION = [
        'calm' => ['dur' => '180ms', 'ease' => 'cubic-bezier(.2,.7,.2,1)', 'lift' => '0px', 'scale' => '1', 'zoom' => '1.03', 'glow' => '0'],
        'smooth' => ['dur' => '200ms', 'ease' => 'cubic-bezier(.2,.7,.2,1)', 'lift' => '2px', 'scale' => '1', 'zoom' => '1.04', 'glow' => '0'],
        'snappy' => ['dur' => '120ms', 'ease' => 'cubic-bezier(.3,.9,.3,1)', 'lift' => '2px', 'scale' => '1', 'zoom' => '1', 'glow' => '1'],
        'cinematic' => ['dur' => '280ms', 'ease' => 'cubic-bezier(.2,.8,.2,1)', 'lift' => '0px', 'scale' => '1.06', 'zoom' => '1', 'glow' => '0'],
        'cinematic_slow' => ['dur' => '380ms', 'ease' => 'cubic-bezier(.25,.8,.25,1)', 'lift' => '0px', 'scale' => '1', 'zoom' => '1.03', 'glow' => '1'],
        'bouncy' => ['dur' => '240ms', 'ease' => 'cubic-bezier(.34,1.56,.64,1)', 'lift' => '4px', 'scale' => '1.02', 'zoom' => '1', 'glow' => '0'],
        'mechanical' => ['dur' => '80ms', 'ease' => 'steps(2,end)', 'lift' => '0px', 'scale' => '1', 'zoom' => '1', 'glow' => '0'],
        'drift' => ['dur' => '260ms', 'ease' => 'cubic-bezier(.2,.7,.2,1)', 'lift' => '3px', 'scale' => '1', 'zoom' => '1.03', 'glow' => '0'],
    ];

    private const DENSITY = [
        'compact' => ['gap' => '12px', 'section_gap' => '26px', 'chip_h' => '30px', 'control_h' => '36px', 'pad' => '16px'],
        'comfortable' => ['gap' => '16px', 'section_gap' => '34px', 'chip_h' => '34px', 'control_h' => '40px', 'pad' => '24px'],
        'relaxed' => ['gap' => '24px', 'section_gap' => '46px', 'chip_h' => '36px', 'control_h' => '44px', 'pad' => '32px'],
    ];

    private const AVATAR_CLIP = [
        'circle' => 'none',
        'squircle' => 'none',
        'square' => 'none',
        'hexagon' => 'polygon(25% 4%, 75% 4%, 100% 50%, 75% 96%, 25% 96%, 0 50%)',
    ];

    private const SERIF_FAMILIES = ['Playfair Display', 'Cinzel', 'Cormorant Garamond', 'Bodoni Moda', 'Newsreader', 'Fraunces', 'EB Garamond'];

    private const MONO_FAMILIES = ['JetBrains Mono', 'Space Mono', 'IBM Plex Mono', 'VT323', 'Silkscreen'];

    private const GRAIN_SVG = 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'160\' height=\'160\'>'
        ."<filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='2' stitchTiles='stitch'/>"
        ."<feColorMatrix values='0 0 0 0 0.5  0 0 0 0 0.5  0 0 0 0 0.5  0 0 0 {op} 0'/></filter>"
        ."<rect width='100%' height='100%' filter='url(%23n)'/></svg>\")";

    /** The reference star field (Python random.Random(7)): [cx, cy, r, opacity]. */
    private const STAR_FIELD = [
        [165, 77, '1.2', 0.35], [37, 274, '0.5', 0.7], [298, 29, '0.7', 0.35], [44, 222, '1.2', 0.35], [123, 46, '1.2', 0.35],
        [289, 63, '0.7', 0.35], [295, 299, '1.2', 0.35], [113, 23, '0.7', 0.7], [214, 73, '0.5', 0.7], [286, 92, '0.5', 0.5],
        [190, 49, '0.5', 0.35], [105, 254, '1.2', 0.7], [238, 299, '1.2', 0.7], [153, 127, '0.7', 0.5], [41, 294, '0.9', 0.9],
        [175, 229, '0.9', 0.35], [60, 262, '1.2', 0.5], [175, 77, '1.2', 0.9], [20, 39, '0.9', 0.7], [179, 254, '1.2', 0.35],
        [47, 138, '1.2', 0.35], [31, 158, '1.2', 0.7], [197, 177, '0.5', 0.9], [181, 86, '0.5', 0.9], [30, 111, '0.9', 0.5],
        [126, 203, '1.2', 0.9], [41, 85, '1.2', 0.9], [281, 142, '0.7', 0.9], [281, 142, '1.2', 0.7], [194, 118, '0.7', 0.35],
        [90, 77, '0.7', 0.5], [6, 248, '0.7', 0.7], [144, 2, '0.7', 0.9], [273, 189, '0.9', 0.5], [263, 27, '1.2', 0.9],
        [203, 204, '1.2', 0.35], [246, 205, '0.5', 0.5], [34, 106, '1.2', 0.5], [56, 174, '0.5', 0.35], [0, 290, '0.7', 0.35],
        [186, 13, '0.5', 0.5], [192, 76, '0.9', 0.7], [186, 242, '0.5', 0.35], [249, 238, '1.2', 0.9], [159, 43, '0.7', 0.35],
        [175, 135, '1.2', 0.5],
    ];

    /** @var array{themes: array<string, array>, aliases: array<string, array{0: string, 1: string}>}|null */
    private static ?array $catalog = null;

    /** @var array<string, string> backdropPeak() per backdrop + page colour + mode (the flavors of a theme share it) */
    private static array $backdropPeaks = [];

    // ------------------------------------------------------------------ catalogue

    /**
     * The 32 v2 theme records keyed by id (theme_spec.json shape: id, name, family, default_mode, typography,
     * shape, layout, palette, flavors[id, name, seeds, palette_dark, palette_light], theme_owned_recipe).
     *
     * @return array<string, array>
     */
    public static function themes(): array
    {
        return self::catalog()['themes'];
    }

    public static function theme(string $id): ?array
    {
        return self::themes()[$id] ?? null;
    }

    /** One flavor of a theme in the form derive() takes (seeds + id + palette_dark / palette_light). */
    public static function flavor(array $theme, ?string $flavorId): ?array
    {
        foreach ($theme['flavors'] ?? [] as $flavor) {
            if (($flavor['id'] ?? null) === $flavorId) {
                return ($flavor['seeds'] ?? []) + array_filter([
                    'id' => $flavor['id'],
                    'palette_dark' => $flavor['palette_dark'] ?? null,
                    'palette_light' => $flavor['palette_light'] ?? null,
                ], fn ($value) => $value !== null);
            }
        }

        return null;
    }

    /**
     * The theme and flavor to render for a stored theme_preset (v2 id or one of the 120 old ids) and theme_flavor.
     * An old id brings its own flavor; an unknown id falls back to the default theme.
     *
     * @return array{theme: string, flavor: string}
     */
    public static function resolve(?string $themeId, ?string $flavorId = null): array
    {
        $themeId = (string) $themeId;
        $themes = self::themes();
        $alias = self::catalog()['aliases'][$themeId] ?? null;

        if (! isset($themes[$themeId]) && $alias !== null && isset($themes[$alias[0]])) {
            return ['theme' => $alias[0], 'flavor' => $alias[1]];
        }

        if (! isset($themes[$themeId])) {
            $themeId = isset($themes[self::DEFAULT_THEME]) ? self::DEFAULT_THEME : (string) array_key_first($themes);
        }

        $flavorIds = array_column($themes[$themeId]['flavors'] ?? [], 'id');

        return [
            'theme' => $themeId,
            'flavor' => in_array($flavorId, $flavorIds, true) ? (string) $flavorId : ($flavorIds[0] ?? self::DEFAULT_FLAVOR),
        ];
    }

    /** True for a v2 theme id and for each of the 120 old ids. */
    public static function isKnown(?string $id): bool
    {
        return $id !== null && (isset(self::themes()[$id]) || isset(self::catalog()['aliases'][$id]));
    }

    /**
     * The 34 typography pairings: id => [label, display, body, label_font?, vi_display?].
     *
     * @return array<string, array>
     */
    public static function pairings(): array
    {
        return self::catalog()['pairings'];
    }

    /**
     * Theme records, old-id aliases, typography pairings and font specs, from App\Support\ThemeCatalog (the generated
     * catalogue v2): the only place that knows where the data comes from.
     *
     * @return array{themes: array<string, array>, aliases: array<string, array{0: string, 1: string}>, pairings: array<string, array>, font_specs: array<string, string>}
     */
    private static function catalog(): array
    {
        return self::$catalog ??= [
            'themes' => ThemeCatalog::themes(),
            'aliases' => array_map(fn (array $row): array => [$row['theme'], $row['flavor']], ThemeCatalog::aliases()),
            'pairings' => ThemeCatalog::pairings(),
            'font_specs' => ThemeCatalog::fontSpecs(),
        ];
    }

    /** Tests and the build command reload the source after changing it. */
    public static function flush(): void
    {
        self::$catalog = null;
    }

    // ------------------------------------------------------------------ token CSS

    /**
     * Every block of a theme's token file: selector => tokens. Order: per mode the signature tokens and their
     * RTL mirror, then per flavor and mode the tokens that differ from the signature. $pageFit false gives the
     * reference derivation (derive()).
     *
     * @return array<string, array<string, string>>
     */
    public static function blocks(array $theme, bool $pageFit = true): array
    {
        $blocks = [];
        $signature = [];
        $flavors = $theme['flavors'] ?? [];
        $first = self::flavor($theme, ($flavors === [] ? [] : reset($flavors))['id'] ?? self::DEFAULT_FLAVOR);

        foreach (['dark', 'light'] as $mode) {
            $signature[$mode] = self::derive($theme, $mode, $first, pageFit: $pageFit);
            $blocks[self::selector($mode)] = $signature[$mode];
            $rtl = self::rtlMirror($signature[$mode]);

            if ($rtl !== []) {
                $blocks[self::selector($mode, rtl: true)] = $rtl;
            }
        }

        foreach ($flavors as $flavor) {
            if (($flavor['id'] ?? '') === self::DEFAULT_FLAVOR) {
                continue;
            }

            foreach (['dark', 'light'] as $mode) {
                $diff = self::diff(self::derive($theme, $mode, self::flavor($theme, $flavor['id']), pageFit: $pageFit), $signature[$mode]);

                if ($diff !== []) {
                    $blocks[self::selector($mode, $flavor['id'])] = $diff;
                }
            }
        }

        return $blocks;
    }

    /**
     * The token files of one theme, relative to resources/css/themes: '<id>.css' with both modes of the signature
     * colour style and their RTL mirrors, and '<id>/<flavor>.css' with the tokens each other colour style changes.
     * A page links the theme file and its own colour style's file only (App\Support\ThemeCss::themeTags), so the
     * token CSS it loads stays the size of one colour style however many the theme has (Code Editor has 10).
     *
     * The theme file ends with the theme's hero decoration (heroDecoCss()) when its recipe's hero_deco has one, so
     * only the active theme's decoration loads.
     *
     * @return array<string, string> path => CSS
     */
    public static function files(array $theme, ?string $heroDecoDir = null): array
    {
        $id = (string) $theme['id'];
        $names = array_column($theme['flavors'] ?? [], 'name', 'id');
        $groups = [];

        foreach (self::blocks($theme) as $selector => $tokens) {
            $flavor = preg_match('/\[data-avd-flavor="([a-z0-9_]+)"\]/', $selector, $match) === 1 ? $match[1] : '';
            $groups[$flavor][] = self::cssBlock($selector, $tokens);
        }

        $files = [];

        foreach ($groups as $flavor => $blocks) {
            $title = sprintf('%s (%s)', $theme['name'] ?? $id, $id).($flavor === '' ? '' : sprintf(', colour style %s (%s)', $names[$flavor] ?? $flavor, $flavor));
            $header = sprintf('/* %s - generated by `php artisan avd:themes:build` (App\Support\ThemeTokens); do not edit. */', $title);
            $files[$flavor === '' ? $id.'.css' : $id.'/'.$flavor.'.css'] = implode("\n\n", [$header, ...$blocks])."\n";
        }

        $deco = (string) ($theme['theme_owned_recipe']['hero_deco'] ?? '');
        $decoCss = $deco !== '' ? self::heroDecoCss($deco, $heroDecoDir) : null;

        if ($decoCss !== null && trim($decoCss) !== '' && isset($files[$id.'.css'])) {
            $files[$id.'.css'] .= sprintf("\n/* Hero decoration %s (resources/css/themes/%s/%s.css): loads with this theme only. */\n", $deco, self::HERO_DECO_DIR, $deco)
                .trim($decoCss)."\n";
        }

        return $files;
    }

    /**
     * The CSS of one hero decoration (resources/css/themes/hero-decos/<deco>.css), or null when the decoration has
     * none. Its rules ship at the end of the token file of every theme whose recipe names it (files()), which a page
     * loads BEFORE layout-theme-a/b, so a decoration rule wins over a shared hero rule by specificity only, never by
     * order. Every rule therefore starts with html[data-avd-hero-deco="<deco>"][data-avd-theme] (heroDecoProblems()):
     * it applies only under that decoration, and its two-attribute key out-ranks a shared rule of layout-theme-b.css
     * that has the same chain under a one-attribute html key (html[data-avd-theme] ..., html[data-bs-theme="light"] ...).
     * A rule moved out of layout-theme-b.css keeps its chain; check the moved rules with a computed-style diff.
     */
    public static function heroDecoCss(string $deco, ?string $dir = null): ?string
    {
        if (preg_match('/^[a-z0-9_]+$/', $deco) !== 1) {
            return null;
        }

        $file = rtrim($dir ?? resource_path('css/themes/'.self::HERO_DECO_DIR), '/\\').DIRECTORY_SEPARATOR.$deco.'.css';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * What keeps a hero decoration's CSS from shipping: a rule whose selector does not start with
     * html[data-avd-hero-deco="<deco>"][data-avd-theme] (unkeyed it would style every theme's hero; with a one-attribute
     * key it could lose to a shared hero rule loaded after it, see heroDecoCss()), or unbalanced braces.
     *
     * @return list<string>
     */
    public static function heroDecoProblems(string $deco, string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        if (substr_count($css, '{') !== substr_count($css, '}')) {
            return ['unbalanced braces'];
        }

        $prefix = 'html[data-avd-hero-deco="'.$deco.'"][data-avd-theme]';
        $problems = [];
        preg_match_all('/([^{};]+)\{/', $css, $matches);

        foreach ($matches[1] as $selectors) {
            if (str_starts_with(trim($selectors), '@')) {
                continue;
            }

            foreach (self::topLevelSelectors($selectors) as $selector) {
                if (! str_starts_with(str_replace("'", '"', $selector), $prefix)) {
                    $problems[] = 'not under '.$prefix.': '.$selector;
                }
            }
        }

        return $problems;
    }

    /**
     * The selectors of a selector list, split at its top-level commas only: not the ones inside :is(...) / :not(...)
     * arguments (a list of attribute selectors included) or inside [attr="a,b"].
     *
     * @return list<string>
     */
    private static function topLevelSelectors(string $list): array
    {
        $out = [];
        $depth = 0;
        $quote = null;
        $current = '';

        foreach (str_split($list) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $out[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        return array_values(array_filter([...$out, trim($current)], fn (string $selector): bool => $selector !== ''));
    }

    public static function cssBlock(string $selector, array $tokens): string
    {
        $lines = [$selector.' {'];

        foreach ($tokens as $name => $value) {
            $lines[] = '  '.$name.': '.$value.';';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /** html[data-bs-theme="dark"], + [data-avd-flavor="coral"], + [dir="rtl"]. */
    public static function selector(string $mode, ?string $flavor = null, bool $rtl = false): string
    {
        return 'html[data-bs-theme="'.$mode.'"]'
            .($flavor !== null ? '[data-avd-flavor="'.$flavor.'"]' : '')
            .($rtl ? '[dir="rtl"]' : '');
    }

    /** @return array<string, string> the tokens of `$tokens` whose value differs from `$base` */
    public static function diff(array $tokens, array $base): array
    {
        return array_filter($tokens, fn ($value, $name) => ($base[$name] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * RTL values for asymmetric shapes: clip-path polygons mirrored on x, 4-value radii with left and right
     * corners swapped (2-value radii swapped). Symmetric values are left out.
     *
     * @return array<string, string>
     */
    public static function rtlMirror(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $name => $value) {
            if (str_starts_with($value, 'polygon(')) {
                $mirrored = self::mirrorPolygon($value);

                if ($mirrored !== $value) {
                    $out[$name] = $mirrored;
                }
            } elseif (str_starts_with($name, '--avd-r-')) {
                $parts = preg_split('/\s+/', trim($value)) ?: [];

                if (count($parts) === 4 && $parts[0] !== $parts[1]) {
                    $out[$name] = "{$parts[1]} {$parts[0]} {$parts[3]} {$parts[2]}";
                } elseif (count($parts) === 2 && $parts[0] !== $parts[1]) {
                    $out[$name] = "{$parts[1]} {$parts[0]}";
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ owner colours

    /**
     * The owner's custom colours as an inline block (critique M10: same engine as the catalogue). A colour
     * setting counts as custom when it is a valid hex that differs from the active flavor's own value for that
     * role; the theme is then derived again with the custom palette and only the tokens that change are
     * printed, keyed on html[data-bs-theme][data-avd-flavor] so they beat the token file's flavor blocks.
     * brand_contrast_autofix = '0' keeps the exact colours on fills and text ('not accessible').
     * theme_oled_black_enabled = '1' ("Pure black in dark mode") is a dark-mode palette change of the same kind: the
     * page colours (bg, bg2) become #000000 without backdrop glow or texture, and the dark tokens are derived again,
     * so the theme's tokens still own every background and every ink is fitted to black (a no-op for black themes).
     *
     * @param  list<string>  $modes  the modes the page can show (the visitor switch may flip it client-side)
     */
    public static function ownerOverrides(array $settings, string $themeId, string $flavorId, array $modes = ['dark', 'light']): string
    {
        $theme = self::theme($themeId);
        $flavor = $theme !== null ? self::flavor($theme, $flavorId) : null;

        if ($theme === null || $flavor === null) {
            return '';
        }

        $autofix = ! in_array(strtolower(trim((string) ($settings['brand_contrast_autofix'] ?? '1'))), ['0', 'false', 'off', 'no'], true);
        $oled = in_array(strtolower(trim((string) ($settings['theme_oled_black_enabled'] ?? '0'))), ['1', 'true', 'on', 'yes'], true)
            && in_array('dark', $modes, true);
        $custom = self::customColours($settings, $theme, $flavor);

        if ($custom === [] && ! $oled) {
            return '';
        }

        $key = 'avd:theme-tokens:owner:'.md5((string) json_encode([self::ENGINE_VERSION, $theme, $flavor, $modes, $custom, $autofix, $oled]));
        $build = static function () use ($theme, $flavor, $modes, $custom, $autofix, $oled): string {
            $css = '';

            foreach ($modes as $mode) {
                $roles = array_filter($custom, fn ($role) => $role['mode'] === null || $role['mode'] === $mode);
                $palette = [];

                foreach ($roles as $role) {
                    $palette[$role['role']] = $role['value'];
                }

                if ($oled && $mode === 'dark') {
                    // Pure black page colours win over a custom dark background colour.
                    $palette['bg'] = '#000000';
                    $palette['bg2'] = '#000000';
                }

                if ($palette === []) {
                    continue;
                }

                $tokens = self::derive($theme, $mode, $flavor, $palette, $autofix);

                if ($oled && $mode === 'dark') {
                    // True black: no backdrop glow or texture over the page either.
                    $tokens['--avd-page-backdrop'] = 'none';
                    $tokens['--avd-backdrop-tile'] = 'none';
                }

                $diff = self::diff($tokens, self::derive($theme, $mode, $flavor));

                if ($diff !== []) {
                    $block = [];

                    foreach ($diff as $name => $value) {
                        $block[] = $name.':'.$value;
                    }

                    $css .= 'html[data-bs-theme="'.$mode.'"][data-avd-flavor]{'.implode(';', $block).'}';
                }
            }

            return $css;
        };

        try {
            return (string) SafeCache::rememberForever($key, $build);
        } catch (Throwable) {
            return $build();
        }
    }

    /**
     * Custom colour settings: role => ['role' => palette key, 'value' => '#rrggbb', 'mode' => null|'dark'|'light'].
     *
     * @return array<string, array{role: string, value: string, mode: ?string}>
     */
    public static function customColours(array $settings, array $theme, array $flavor): array
    {
        $out = [];

        foreach (self::OWNER_COLOURS as $key => $role) {
            $value = strtolower(trim((string) ($settings[$key] ?? '')));

            if (! preg_match('/^#[0-9a-f]{6}$/', $value)) {
                continue;
            }

            $mode = str_starts_with($key, 'dark_') ? 'dark' : (str_starts_with($key, 'light_') ? 'light' : null);
            $own = [];

            foreach ($mode !== null ? [$mode] : ['dark', 'light'] as $m) {
                [$palette] = self::palette($theme, $m, $flavor);
                $own[] = strtolower((string) Oklch::hex((string) $palette[$role]));
            }

            if (! in_array($value, $own, true)) {
                $out[$key] = ['role' => $role, 'value' => $value, 'mode' => $mode];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ fonts

    /**
     * The fonts a public page of this theme loads and the font tokens that differ from the theme's token file.
     * Font budget (critique M5): the pairing's display and body families (plus a label family), only the styles
     * the theme uses (e.g. Playfair italic only where a title is italic), static families with explicit weights
     * (the old 'Poppins:wght@400..700' was dropped by Google). Vietnamese pages swap a display face without
     * Vietnamese glyphs for the pairing's vi_display; Cyrillic pages fall back to the body face. Families the
     * owner's custom code names are added as before.
     *
     * @param  string|null  $pairingId  the typography_pairing setting (the theme's own pairing when null or unknown)
     * @return array{pairing: string, families: array<string, list<string>>, url: ?string, tokens: array<string, string>}
     */
    public static function fonts(array $theme, ?string $pairingId = null, string $locale = 'en', string $customCode = ''): array
    {
        $T = $theme['typography'];
        $pairings = self::pairings();
        $pairingId = isset($pairings[(string) $pairingId]) ? (string) $pairingId : (string) ($T['pairing'] ?? '');
        $pairing = $pairings[$pairingId] ?? $T;
        $display = (string) ($pairing['display'] ?? $T['display']);
        $body = (string) ($pairing['body'] ?? $T['body']);
        $label = (string) ($pairing['label_font'] ?? $body);
        $base = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        if ($base === 'vi' && ! empty($pairing['vi_display'])) {
            $display = (string) $pairing['vi_display'];
        } elseif ($base === 'ru' && ! in_array($display, self::CYRILLIC_FAMILIES, true)) {
            $display = $body;
        }

        $titleFont = ($T['title_font'] ?? null) === 'display' ? $display : $body;
        $kickerFont = ['label' => $label, 'display' => $display][$T['kicker_font'] ?? 'body'] ?? $body;
        $metaFont = ($T['meta_font'] ?? null) === 'label' ? $label : $body;
        $tokens = [
            '--avd-font-display' => self::fontStack($display), '--avd-font-body' => self::fontStack($body),
            '--avd-font-label' => self::fontStack($label), '--avd-font-title' => self::fontStack($titleFont),
            '--avd-font-kicker' => self::fontStack($kickerFont), '--avd-font-meta' => self::fontStack($metaFont),
        ];
        $themeTokens = [
            '--avd-font-display' => self::fontStack($T['display']), '--avd-font-body' => self::fontStack($T['body']),
            '--avd-font-label' => self::fontStack($T['label_font'] ?? $T['body']),
            '--avd-font-title' => self::fontStack(($T['title_font'] ?? null) === 'display' ? $T['display'] : $T['body']),
            '--avd-font-kicker' => self::fontStack(['label' => $T['label_font'] ?? $T['body'], 'display' => $T['display']][$T['kicker_font'] ?? 'body'] ?? $T['body']),
            '--avd-font-meta' => self::fontStack(($T['meta_font'] ?? null) === 'label' ? ($T['label_font'] ?? $T['body']) : $T['body']),
        ];

        // The styles each family really renders (the loader requests only these).
        $displayStyles = [$T['display_style'] ?? 'normal'];

        if (($T['title_font'] ?? null) === 'display') {
            $displayStyles[] = $T['title_style'] ?? 'normal';
        }

        if (($T['kicker_font'] ?? null) === 'display') {
            $displayStyles[] = 'normal';
        }

        $bodyStyles = ['normal'];

        if (($T['title_font'] ?? 'body') === 'body') {
            $bodyStyles[] = $T['title_style'] ?? 'normal';
        }

        $families = [];

        foreach ([[$display, $displayStyles], [$body, $bodyStyles], [$label, ['normal']]] as [$family, $styles]) {
            $families[$family] = array_values(array_unique(array_merge($families[$family] ?? [], $styles)));
        }

        $specs = self::catalog()['font_specs'];

        if (trim($customCode) !== '') {
            foreach (array_keys($specs) as $family) {
                if (! isset($families[$family]) && preg_match('/(?<![a-z0-9_-])'.preg_quote($family, '/').'(?![a-z0-9_-])/i', $customCode)) {
                    $families[$family] = ['normal', 'italic'];
                }
            }
        }

        foreach ($families as $family => $styles) {
            sort($styles);
            $families[$family] = $styles;
        }

        return [
            'pairing' => $pairingId,
            'families' => $families,
            'url' => self::googleFontsUrl($families),
            'tokens' => self::diff($tokens, $themeTokens),
        ];
    }

    /**
     * One css2 request for [family => styles] with the v2 specs (unknown families are skipped), or null.
     *
     * @param  array<string, list<string>>  $families
     */
    public static function googleFontsUrl(array $families): ?string
    {
        $specs = self::catalog()['font_specs'];
        $parts = [];

        foreach ($families as $family => $styles) {
            if (isset($specs[$family])) {
                $parts[] = 'family='.self::fontSpec($specs[$family], $styles);
            }
        }

        return $parts === [] ? null : 'https://fonts.googleapis.com/css2?'.implode('&', $parts).'&display=swap';
    }

    /**
     * A css2 family spec narrowed to the given styles: 'Playfair+Display:ital,wght@0,500..900;1,500..900' with only
     * 'normal' becomes 'Playfair+Display:wght@500..900'. Specs without an ital axis are returned as they are.
     *
     * @param  list<string>  $styles
     */
    public static function fontSpec(string $spec, array $styles): string
    {
        if (! str_contains($spec, ':') || ! str_contains($spec, '@')) {
            return $spec;
        }

        [$name, $axisPart] = explode(':', $spec, 2);
        [$axisList, $tupleList] = explode('@', $axisPart, 2);
        $axes = explode(',', $axisList);
        $ital = array_search('ital', $axes, true);

        if ($ital === false) {
            return $spec;
        }

        $kept = [];

        foreach (explode(';', $tupleList) as $tuple) {
            $values = explode(',', $tuple);

            if (in_array(($values[$ital] ?? '0') === '1' ? 'italic' : 'normal', $styles, true)) {
                $kept[] = $values;
            }
        }

        if ($kept === []) {
            return $spec;
        }

        if (array_filter($kept, fn (array $values): bool => $values[$ital] === '1') === []) {
            unset($axes[$ital]);

            foreach ($kept as $i => $values) {
                unset($values[$ital]);
                $kept[$i] = $values;
            }
        }

        return $name.':'.implode(',', $axes).'@'.implode(';', array_map(fn (array $values): string => implode(',', $values), $kept));
    }

    /**
     * The v1 back office's font request (every v1 family: the Theme Studio previews each pairing) plus the
     * active theme's families. Static families get their weights listed one by one: Google rejects a weight
     * range for them ('Poppins:wght@400..700') and silently drops the family from a combined request.
     *
     * @param  array<string, string>  $legacySpecs  family => v1 css2 spec (AppearanceSettings::googleFontFamilySpecs())
     * @param  array<string, list<string>>  $themeFamilies  family => styles (fonts()['families'])
     */
    public static function backOfficeFontsUrl(array $legacySpecs, array $themeFamilies): ?string
    {
        $specs = self::catalog()['font_specs'];
        $parts = [];

        foreach ($legacySpecs as $family => $spec) {
            $parts[$family] = 'family='.(self::STATIC_FAMILY_SPECS[$family] ?? $spec);
        }

        foreach ($themeFamilies as $family => $styles) {
            if (! isset($parts[$family]) && isset($specs[$family])) {
                $parts[$family] = 'family='.self::fontSpec($specs[$family], $styles);
            }
        }

        return $parts === [] ? null : 'https://fonts.googleapis.com/css2?'.implode('&', $parts).'&display=swap';
    }

    // ------------------------------------------------------------------ derivation

    /**
     * The palette a mode + flavor renders with, and the theme extras (metal, holo, bevel, syntax...).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function palette(array $theme, string $mode, ?array $flavor): array
    {
        $p = $theme['palette'][$mode];
        $extra = $p['extra'] ?? [];
        unset($p['extra']);

        if ($flavor) {
            $pal = $flavor['palette_'.$mode] ?? null;

            if ($pal) {
                foreach ($pal as $k => $v) {
                    if (str_starts_with($k, 'syn_') || $k === 'status_bg') {
                        $extra[$k] = $v;
                        unset($pal[$k]);
                    }
                }

                $p = array_replace($p, $pal);
            } elseif (! empty($flavor['palette_dark']) && $mode === 'light') {
                // An editor palette with no designed light twin: the theme's light base, recoloured accents.
                $dk = $flavor['palette_dark'];
                [$p['brand'], $p['brand2'], $p['accent']] = [$dk['brand'], $dk['brand2'], $dk['accent']];
            } elseif (($flavor['id'] ?? null) === self::DEFAULT_FLAVOR) {
                // The designed palette is the source of truth for the signature flavor.
            } else {
                if (isset($flavor['brand'])) {
                    $p['brand'] = $flavor['brand_'.$mode] ?? $flavor['brand'];
                    $p['brand2'] = $flavor['brand2'] ?? $p['brand2'];
                    $p['accent'] = $flavor['accent'] ?? $p['accent'];
                }

                if ($mode === 'dark' && ! empty($flavor['text_dark'])) {
                    $p['text'] = $flavor['text_dark'];
                }

                if ($mode === 'dark' && ! empty($flavor['muted_dark'])) {
                    $p['muted'] = $flavor['muted_dark'];
                }

                if (! empty($flavor['metal'])) {
                    $extra['metal'] = $flavor['metal'];
                }

                if (! empty($flavor['holo'])) {
                    $extra['holo'] = $flavor['holo'];
                }
            }
        }

        return [$p, $extra];
    }

    /**
     * Every token of one theme, mode and flavor (ordered as in the reference token files).
     *
     * @param  array<string, string>  $overrides  palette roles replaced before the derivation (owner colours)
     * @param  bool  $fit  false = the brand, second brand and accent colours are used exactly on fills and text
     *                     (brand_contrast_autofix off); labels still take the more readable of white and ink
     * @param  bool  $pageFit  false = the reference derivation (spec build_spec.derive): inks are fitted to the flat
     *                         page and surface colours only, not to the colours the page backdrop paints behind text;
     *                         the transparent bar keeps its scrim tokens and no header overlay / solid-bar tokens are
     *                         added; the active menu ink is fitted to 4.5:1 without the real-page margin
     * @return array<string, string>
     */
    public static function derive(array $theme, string $mode, ?array $flavor = null, array $overrides = [], bool $fit = true, bool $pageFit = true): array
    {
        return self::build($theme, $mode, $flavor, $overrides, $fit, false, $pageFit)[0];
    }

    /**
     * The WCAG checks behind one derivation: [check, fg, bg, ratio, target, ok, hard] rows.
     *
     * @return list<array{check: string, fg: string, bg: string, ratio: float, target: float, ok: bool, hard: bool}>
     */
    public static function checks(array $theme, string $mode, ?array $flavor = null, array $overrides = [], bool $fit = true, bool $pageFit = true): array
    {
        return self::build($theme, $mode, $flavor, $overrides, $fit, true, $pageFit)[1];
    }

    /** @return array{0: array<string, string>, 1: list<array>} */
    private static function build(array $theme, string $mode, ?array $flavor, array $overrides, bool $fit, bool $collect, bool $pageFit = true): array
    {
        $L = $theme['layout'];
        $T = $theme['typography'];
        $S = $theme['shape'];
        [$p, $extra] = self::palette($theme, $mode, $flavor);
        $p = array_replace($p, $overrides);
        $dark = $mode === 'dark';
        $rows = [];
        $need = static function (string $name, string $fg, string $bg, float $target, bool $hard = true) use (&$rows, $collect): void {
            if ($collect) {
                $ratio = Contrast::contrast($fg, $bg);
                $rows[] = ['check' => $name, 'fg' => $fg, 'bg' => $bg, 'ratio' => round($ratio, 2), 'target' => $target, 'ok' => $ratio >= $target - 1e-6, 'hard' => $hard];
            }
        };
        $toward = $dark ? 'lighter' : 'darker';

        $bg = Oklch::hex($p['bg']);
        $bg2 = Oklch::hex($p['bg2']);
        $peak = $extra['backdrop_peak'] ?? $bg;
        $s1o = self::opaque($p['s1'], $bg);
        $s2o = self::opaque($p['s2'], $bg);
        $s3o = self::opaque($p['s3'], $bg);
        $surfaces = [$bg, $s1o, $s2o];

        if (! empty($L['glass'])) {
            $surfaces[] = self::opaque($p['s1'], $peak);
            $surfaces[] = self::opaque($p['s2'], $peak);
        }

        $text = $p['text'];

        foreach (['bg' => $bg, 'surface-1' => $s1o, 'surface-2' => $s2o] as $sname => $sv) {
            $need("text/{$sname}", $text, $sv, 7.0, false);
            $need("text/{$sname} (AA)", $text, $sv, 4.5);
        }

        if (! empty($L['glass'])) {
            $need('text/glass-over-backdrop-peak', $text, self::opaque($p['s1'], $peak), 4.5);
        }

        // The page backdrop (glows, mesh, vignette) paints behind text that sits on the page: on real pages the
        // inks are fitted to the colours it reaches too (Gate A, e.g. Liquid Glass light on its peach mesh stop),
        // and on glass themes to the glass surfaces over them. A no-op wherever an ink already reads on them.
        [$backdrop, $floor, $tile] = self::backdrop($L['backdrop'], $dark, $p['brand'], $p['brand2'], $p['accent'], $bg, $bg2, $text);
        $inkSurfaces = $surfaces;

        if ($pageFit) {
            foreach (self::backdropColours($backdrop, $bg) as $stop) {
                $inkSurfaces[] = $stop;

                if (! empty($L['glass'])) {
                    $inkSurfaces[] = self::opaque($p['s1'], $stop);
                    $inkSurfaces[] = self::opaque($p['s2'], $stop);
                }
            }

            // Phase B themes: where the page glows overlap on screen, stronger glows than the soft washes included,
            // or they stack on the backdrop's gradient floor (Interstellar's danger ink 4.24-4.32:1 on a phone,
            // Outrun's inks ~4.0 on its horizon glow over the floor). The Phase A themes keep the colours signed off
            // at Gate A (a no-op for them on a phone or desktop viewport below the header anyway).
            if (! in_array((string) $theme['id'], self::PHASE_A_THEMES, true)) {
                $inkSurfaces[] = self::backdropPeak($backdrop, $bg, $dark);
            }

            $inkSurfaces = array_values(array_unique($inkSurfaces));
        }

        $muted = Contrast::fitLightness($p['muted'], array_merge($inkSurfaces, [$s3o]), 4.5, $toward);
        $faint = Contrast::fitLightness($p['faint'], array_merge([$bg, $s1o], array_diff($inkSurfaces, $surfaces)), 3.0, $toward);

        foreach (['bg' => $bg, 'surface-1' => $s1o, 'surface-2' => $s2o] as $sname => $sv) {
            $need("muted/{$sname}", $muted, $sv, 4.5);
        }

        $need('faint/bg (non-text or >=18px only)', $faint, $bg, 3.0);

        // ---- brand
        $brand = $p['brand'];
        $brand2 = $p['brand2'];
        $accent = $p['accent'];
        $pref = $p['on_brand'] ?? 'auto';
        $inkDark = $dark ? $bg : $text;

        if (Oklch::relativeLuminance($inkDark) > 0.2) {
            $inkDark = '#111111';
        }

        $inkDark = Oklch::relativeLuminance($inkDark) < 0.03 ? $inkDark : '#0b0b0f';
        $lightInk = '#ffffff';

        if ($fit) {
            [$brandFill, $onBrand] = Contrast::labelFill($brand, $pref, $inkDark);
            $brand2Fill = Contrast::fillForText($brand2, $onBrand, 4.5);
        } else {
            [$brandFill, $brand2Fill] = [Oklch::hex($brand), Oklch::hex($brand2)];
            $onBrand = Contrast::bestTextOn($brandFill, $lightInk, $inkDark);
        }

        $need('on-brand/brand-fill', $onBrand, $brandFill, 4.5);

        if (($L['chip_active'] ?? null) === 'gradient' || ($L['buttons'] ?? null) === 'pill_gradient') {
            $need('on-brand/brand-2-fill (gradient end)', $onBrand, $brand2Fill, 4.5);
        }

        $brandInk = $fit ? Contrast::fitLightness($brand, $inkSurfaces, 4.5, $toward) : $brand;

        foreach (['bg' => $bg, 'surface-1' => $s1o, 'surface-2' => $s2o] as $sname => $sv) {
            $need("brand-ink/{$sname}", $brandInk, $sv, 4.5);
        }

        $accentInk = $fit ? Contrast::fitLightness($accent, $inkSurfaces, 4.5, $toward) : $accent;
        $need('accent-ink/bg', $accentInk, $bg, 4.5);
        $onAccent = Contrast::bestTextOn($accent, $lightInk, $inkDark);
        $accentFill = $fit ? Contrast::fillForText($accent, $onAccent, 4.5) : Oklch::hex($accent);
        $need('on-accent/accent-fill', $onAccent, $accentFill, 4.5);
        $focus = $brandInk;
        $need('focus-ring/bg (3:1)', $focus, $bg, 3.0);
        $need('focus-ring/surface-1 (3:1)', $focus, $s1o, 3.0);

        $live = $p['live'] ?? ($dark ? '#ff3b4e' : '#d61f2c');
        $liveFill = Contrast::fillForText($live, '#ffffff', 4.5);
        $need('live-label/live-fill', '#ffffff', $liveFill, 4.5);

        $brandGradient = "linear-gradient(135deg, {$brandFill} 0%, {$brand2Fill} 100%)";
        $textInverse = $dark ? $bg : '#ffffff';

        $M = self::MOTION[$L['motion']];
        $DN = self::DENSITY[$L['density']];

        $tok = [
            '--avd-bg' => $bg, '--avd-bg-2' => $bg2,
            '--avd-surface-1' => $p['s1'], '--avd-surface-2' => $p['s2'], '--avd-surface-3' => $p['s3'],
            '--avd-surface-solid' => $extra['glass_solid'] ?? $s1o,
            '--avd-text' => $text, '--avd-text-inverse' => $textInverse, '--avd-muted' => $muted, '--avd-faint' => $faint,
            '--avd-border' => $p['border'], '--avd-border-strong' => $p['border_strong'],
            '--avd-brand' => $brand, '--avd-brand-rgb' => self::rgb($brand), '--avd-brand-2' => $brand2, '--avd-brand-2-rgb' => self::rgb($brand2),
            '--avd-accent' => $accent, '--avd-accent-rgb' => self::rgb($accent),
            '--avd-brand-fill' => $brandFill, '--avd-brand-2-fill' => $brand2Fill, '--avd-on-brand' => $onBrand,
            '--avd-brand-ink' => $brandInk, '--avd-brand-soft' => self::a($brand, $dark ? 0.14 : 0.10),
            '--avd-accent-ink' => $accentInk, '--avd-accent-fill' => $accentFill, '--avd-on-accent' => $onAccent,
            '--avd-brand-gradient' => $brandGradient, '--avd-focus' => $focus,
            '--avd-live' => $live, '--avd-live-fill' => $liveFill,
        ];

        // Status colours fitted to this palette (critique M2): text inks + fills with their labels.
        $status = Contrast::status($mode, $inkSurfaces, $inkDark);

        foreach (['success', 'warning', 'danger', 'info'] as $kind) {
            $tok["--avd-{$kind}"] = $status[$kind];
            $tok["--avd-{$kind}-rgb"] = self::rgb($status[$kind]);
            $tok["--avd-{$kind}-fill"] = $status[$kind.'-fill'];
            $tok["--avd-on-{$kind}"] = $status[$kind.'-on'];

            foreach (['bg' => $bg, 'surface-1' => $s1o, 'surface-2' => $s2o] as $sname => $sv) {
                $need("status {$kind} text/{$sname}", $status[$kind], $sv, 4.5);
            }

            $need("status {$kind} label/fill", $status[$kind.'-on'], $status[$kind.'-fill'], 4.5);
        }

        foreach (array_diff($inkSurfaces, $surfaces) as $stop) {
            $need("muted/backdrop {$stop}", $muted, $stop, 4.5);
            $need("brand-ink/backdrop {$stop}", $brandInk, $stop, 4.5);
            $need("accent-ink/backdrop {$stop}", $accentInk, $stop, 4.5);

            foreach (['success', 'warning', 'danger', 'info'] as $kind) {
                $need("status {$kind} text/backdrop {$stop}", $status[$kind], $stop, 4.5);
            }
        }

        $borderInput = Contrast::fitLightness(self::opaque($p['border_strong'], $bg), [$bg, $s1o], 3.0, $toward);
        $tok['--avd-border-input'] = $borderInput;
        $need('border-input/bg (3:1 non-text)', $borderInput, $bg, 3.0);
        $need('border-input/surface-1 (3:1 non-text)', $borderInput, $s1o, 3.0);

        // ---- typography
        $display = $T['display'];
        $body = $T['body'];
        $labelFont = $T['label_font'] ?? $body;
        $titleFont = ($T['title_font'] ?? null) === 'display' ? $display : $body;
        $kickerFont = ['label' => $labelFont, 'display' => $display][$T['kicker_font'] ?? 'body'] ?? $body;
        $metaFont = ($T['meta_font'] ?? null) === 'label' ? $labelFont : $body;
        $tok += [
            '--avd-font-display' => self::fontStack($display), '--avd-font-body' => self::fontStack($body),
            '--avd-font-label' => self::fontStack($labelFont), '--avd-font-title' => self::fontStack($titleFont),
            '--avd-font-kicker' => self::fontStack($kickerFont), '--avd-font-meta' => self::fontStack($metaFont),
            '--avd-display-weight' => self::str($T['display_weight']), '--avd-display-case' => $T['display_case'],
            '--avd-display-tracking' => $T['display_tracking'], '--avd-display-style' => $T['display_style'],
            '--avd-title-weight' => self::str($T['title_weight']), '--avd-title-size' => self::str($T['title_size']).'px',
            '--avd-title-style' => $T['title_style'] ?? 'normal',
            '--avd-section-size' => self::str($T['section_size']).'px',
            '--avd-kicker-case' => $T['kicker_case'], '--avd-kicker-tracking' => $T['kicker_tracking'], '--avd-kicker-weight' => self::str($T['kicker_weight']),
            '--avd-meta-size' => '12.5px',
        ];

        // ---- shape
        $tok += [
            '--avd-r-card' => self::px($S['card']), '--avd-r-thumb' => self::px($S['thumb']), '--avd-r-chip' => self::px($S['chip']),
            '--avd-r-control' => self::px($S['control']), '--avd-r-button' => self::px($S['button']), '--avd-r-badge' => self::px($S['badge']),
            '--avd-r-avatar' => (string) $S['avatar'], '--avd-bw' => self::str($S['bw']).'px',
            '--avd-avatar-clip' => self::AVATAR_CLIP[$L['avatar_shape'] ?? 'circle'],
        ];

        if (($L['avatar_shape'] ?? null) === 'squircle') {
            $tok['--avd-r-avatar'] = '30%';
        }

        // ---- density & motion
        $tok += [
            '--avd-gap' => $DN['gap'], '--avd-section-gap' => $DN['section_gap'], '--avd-chip-h' => $DN['chip_h'],
            '--avd-control-h' => $DN['control_h'], '--avd-content-pad' => $DN['pad'], '--avd-grid-cols' => self::str($L['grid_cols']),
            '--avd-dur' => $M['dur'], '--avd-ease' => $M['ease'], '--avd-hover-lift' => $M['lift'], '--avd-hover-scale' => $M['scale'],
            '--avd-thumb-zoom' => $M['zoom'],
            '--avd-hover-glow' => $M['glow'] === '1' ? '0 0 0 1px '.self::a($brandInk, 0.55).', 0 0 22px '.self::a($brand, 0.28) : 'none',
        ];

        // ---- header
        $hv = $L['header'];
        $headerText = $text;
        $headerBg = self::a($bg, 0.86);
        $headerBorder = '1px solid '.$p['border'];
        $headerShadow = 'none';
        $headerH = '64px';
        $headerBlur = '0px';
        $headerRadius = '0px';
        $headerInset = '0px';
        $headerStripe = 'none';
        $headerRule = 'none';

        if ($hv === 'glass') {
            [$headerBg, $headerBlur] = [self::a($bg, 0.80), '14px'];
        } elseif ($hv === 'glass_line') {
            [$headerBg, $headerBlur, $headerBorder] = [self::a($bg, 0.84), '12px', '1px solid '.self::a($accent, 0.45)];
        } elseif ($hv === 'solid' || $hv === 'crest') {
            $headerBg = $s1o;

            if ($hv === 'crest') {
                $headerH = '76px';
                $headerBorder = '1px solid '.self::a($brand, 0.35);
            }
        } elseif ($hv === 'solid_stripe') {
            $headerBg = $s1o;
            $headerBorder = '0 solid transparent';
            $headerStripe = "linear-gradient(90deg, {$brand} 0%, ".Oklch::mix($brand, $brand2, 0.5)." 50%, {$brand2} 100%)";
        } elseif (in_array($hv, ['slim', 'slim_tabs', 'slim_terminal'], true)) {
            [$headerBg, $headerH] = [$bg, '56px'];

            if ($hv === 'slim_tabs') {
                $headerBg = $extra['tabs_bg'] ?? $bg2;
            }
        } elseif ($hv === 'slim_double') {
            [$headerBg, $headerH, $headerBorder] = [$bg, '60px', '0 solid transparent'];
            $headerRule = '0 1px 0 '.self::a($text, 0.45).", 0 4px 0 {$bg}, 0 5px 0 ".self::a($text, 0.45);
        } elseif ($hv === 'slim_heavy') {
            [$headerBg, $headerH, $headerBorder] = [$bg, '60px', '3px solid '.$text];
        } elseif ($hv === 'island') {
            $headerBg = self::a(empty($L['glass']) ? $s1o : ($dark ? '#1a1a2a' : '#ffffff'), $dark ? 0.70 : 0.78);
            [$headerBlur, $headerRadius, $headerInset, $headerH] = ['16px', '999px', '12px', '58px'];
            $headerBorder = '1px solid '.$p['border'];
            $headerShadow = '0 10px 30px -12px '.self::a('#000000', $dark ? 0.45 : 0.18);
        } elseif ($hv === 'megabar') {
            [$headerBg, $headerH] = [$s1o, '104px'];
        } elseif ($hv === 'masthead' || $hv === 'masthead_brutal') {
            [$headerBg, $headerH] = [$bg, '112px'];
            $headerBorder = ($hv === 'masthead_brutal' ? '2px' : '1px').' solid '.$text;
        } elseif ($hv === 'transparent') {
            $headerBg = 'linear-gradient(180deg, rgba(0,0,0,.78) 0%, rgba(0,0,0,.35) 60%, rgba(0,0,0,0) 100%)';
            $headerText = '#ffffff';
            $headerBorder = '0 solid transparent';
        } elseif ($hv === 'band') {
            $band = $extra['pop2'] ?? $brand2;
            $headerBg = $band;
            $headerText = Contrast::bestTextOn($band, '#ffffff', '#111111');
            $headerBorder = '2px solid '.($extra['ink'] ?? $text);
        } elseif ($hv === 'band_titlebar') {
            $headerBg = 'linear-gradient(90deg, '.Contrast::fillForText($brand, '#ffffff', 4.5).' 0%, '.Contrast::fillForText($brand2, '#ffffff', 4.5).' 100%)';
            $headerText = '#ffffff';
            $headerBorder = '2px solid '.($extra['bevel_dark'] ?? $p['border_strong']);
        }

        // Real pages (not the reference derivation): the transparent bar's scrim and white ink only read over a hero
        // photo, so they become the overlay tokens layout-theme-a.css uses there (header_style transparent_overlay), and
        // everywhere else (pages without a hero, the other header styles, the solid surface style) the bar is the
        // page colour with the page ink - Gate A: Noir Premiere light had white links on white bars. A designed colour
        // band is opaque already, so the solid surface style keeps it (--avd-header-solid-bg) instead of the surface.
        $headerExtra = [];

        if ($pageFit && $hv === 'transparent') {
            // The scrim is a little denser behind the links (a link over a bright billboard read 4.45:1 on /ar).
            $headerExtra = [
                '--avd-header-overlay-bg' => 'linear-gradient(180deg, rgba(0,0,0,.8) 0%, rgba(0,0,0,.55) 70%, rgba(0,0,0,0) 100%)',
                '--avd-header-overlay-text' => $headerText,
            ];
            [$headerBg, $headerText] = [$bg, $text];
        } elseif ($pageFit && in_array($hv, ['band', 'band_titlebar'], true)) {
            $headerExtra = ['--avd-header-solid-bg' => $headerBg];
        }

        $tok += [
            '--avd-header-bg' => $headerBg, '--avd-header-text' => $headerText, '--avd-header-border' => $headerBorder,
            '--avd-header-shadow' => $headerShadow, '--avd-header-h' => $headerH, '--avd-header-blur' => $headerBlur,
            '--avd-header-radius' => $headerRadius, '--avd-header-inset' => $headerInset, '--avd-header-stripe' => $headerStripe,
            '--avd-header-rule' => $headerRule,
        ] + $headerExtra;

        if ($collect && ($hv !== 'transparent' || $pageFit)) {
            if (str_starts_with($headerBg, 'linear-gradient')) {
                foreach ([Contrast::fillForText($brand, '#ffffff', 4.5), Contrast::fillForText($brand2, '#ffffff', 4.5)] as $i => $stop) {
                    $need("header-text/header-bg stop{$i}", $headerText, $stop, 4.5);
                }
            } else {
                $need('header-text/header-bg', $headerText, self::opaque($headerBg, $bg), 4.5);

                if (Oklch::parse($headerBg)[3] < 1) {
                    $need('header-text/header-bg over white content (lite: no blur)', $headerText, self::opaque($headerBg, $dark ? '#d0d0d0' : '#ffffff'), 3.0, false);
                }
            }
        }

        // ---- sidebar
        $sv = $L['sidebar'];
        [$sbBg, $sbText, $sbActiveBg, $sbActiveText, $sbBar] = [$bg, $text, $s2o, $text, 'transparent'];
        [$sbW, $sbRadius, $sbBorder, $sbItemR] = ['232px', '0px', '1px solid '.$p['border'], '10px'];

        if ($sv === 'rail') {
            [$sbW, $sbItemR] = ['76px', '10px'];
            [$sbActiveBg, $sbActiveText] = [self::a($brand, $dark ? 0.16 : 0.12), $brandInk];
            $sbBar = $brandInk;
        } elseif ($sv === 'floating') {
            $sbBg = $p['s1'];
            [$sbRadius, $sbBorder] = [is_int($S['card']) ? self::px($S['card']) : '18px', '1px solid '.$p['border']];
            [$sbActiveBg, $sbActiveText] = [$brandFill, $onBrand];
        } elseif ($sv === 'startmenu') {
            $sbBg = $s1o;
            [$sbActiveBg, $sbActiveText] = [Contrast::fillForText($brand, '#ffffff', 4.5), '#ffffff'];
            $sbItemR = '0px';
        } elseif ($sv === 'hidden') {
            $sbW = '0px';
        }

        if (in_array($L['header'], ['solid', 'megabar'], true) && $sv === 'full') {
            $sbBg = $bg;
        }

        if ($theme['id'] === 'tokyo_neon') {
            [$sbActiveBg, $sbActiveText] = [self::a($brand, $dark ? 0.14 : 0.10), $brandInk];
            $sbBar = $brandInk;
        }

        if (in_array($theme['id'], ['synthwave_sunset', 'fairy_floss', 'bubblegum_pop', 'rose_gold_velvet', 'amazon_rainforest', 'sahara_dune'], true)) {
            [$sbActiveBg, $sbActiveText] = [$brandFill, $onBrand];
        }

        $sbBase = self::opaque($sbBg, $bg);
        $sbActiveOpaque = self::opaque($sbActiveBg, $sbBase);
        // On real pages an ink on the active item's tint keeps a margin over 4.5:1: the surface grain renders the tint
        // a shade darker than the flat colours (Gate A: 4.46 on Midnight Spotlight coral). Labels on fills stay at 4.5.
        $sbTarget = $pageFit && Oklch::parse($sbActiveBg)[3] < 1 ? 4.6 : 4.5;
        // Under "solid surfaces" the side menu is the solid surface (layout-theme-a), so a see-through active tint
        // sits on that one as well.
        $sbUnder = $pageFit ? array_values(array_unique([$sbActiveOpaque, self::opaque($sbActiveBg, $extra['glass_solid'] ?? $s1o)])) : [$sbActiveOpaque];

        if (min(array_map(fn (string $under): float => Contrast::contrast($sbActiveText, $under), $sbUnder)) < $sbTarget) {
            $sbActiveText = Contrast::fitLightness($sbActiveText, $sbUnder, $sbTarget, $toward);
        }

        $tok += [
            '--avd-sidebar-bg' => $sbBg, '--avd-sidebar-text' => $sbText, '--avd-sidebar-muted' => $muted,
            '--avd-sidebar-active-bg' => $sbActiveBg, '--avd-sidebar-active-text' => $sbActiveText, '--avd-sidebar-active-bar' => $sbBar,
            '--avd-sidebar-w' => $sbW, '--avd-sidebar-radius' => $sbRadius, '--avd-sidebar-border' => $sbBorder, '--avd-sidebar-item-r' => $sbItemR,
        ];

        if ($sv !== 'hidden') {
            $need('sidebar-text/sidebar-bg', $sbText, self::opaque($sbBg, $bg), 4.5);
            $need('sidebar-active-text/active-bg', $sbActiveText, self::opaque($sbActiveBg, self::opaque($sbBg, $bg)), 4.5);
        }

        // ---- metal gradient: the designed one only if dark labels pass on every stop
        if (isset($extra['metal'])) {
            preg_match_all('/#[0-9a-fA-F]{6}/', $extra['metal'], $m);
            $failing = array_filter($m[0], fn ($stop) => Contrast::contrast('#1a1405', $stop) < 4.5);

            if ($failing !== []) {
                [$lb, $cb, $hb] = Oklch::toOklch($brand);
                $top = Oklch::fromOklch(min($lb + 0.17, 0.97), $cb * 0.55, $hb);
                $mid = Contrast::fillForText($brand, '#1a1405', 4.5);
                $low = Contrast::fitLightness(Oklch::fromOklch(max($lb - 0.09, 0.2), $cb, $hb), ['#1a1405'], 4.6, 'lighter');
                $extra['metal'] = "linear-gradient(135deg,{$top} 0%,{$mid} 46%,{$low} 100%)";
            }
        }

        // ---- syntax colours (used as meta text): 4.5:1 on bg and surface-1
        foreach (['syn_keyword', 'syn_string', 'syn_number', 'syn_func', 'syn_type', 'syn_comment'] as $k) {
            if (isset($extra[$k])) {
                $extra[$k] = Contrast::fitLightness($extra[$k], [$bg, $s1o], 4.5, $toward);
            }
        }

        // ---- chips
        $cv = $L['chips'];
        $ca = $L['chip_active'];
        $chip = ['bg' => $s2o, 'text' => $text, 'border' => 'transparent', 'case' => 'none', 'weight' => '600', 'tracking' => '0', 'clip' => 'none', 'shadow' => 'none'];

        if ($cv === 'soft') {
            $chip['bg'] = $s2o;
        } elseif ($cv === 'outline') {
            $chip = array_replace($chip, ['bg' => 'transparent', 'border' => $p['border_strong']]);
        } elseif ($cv === 'underline') {
            $chip = array_replace($chip, ['bg' => 'transparent', 'text' => $muted, 'weight' => '600']);
        } elseif ($cv === 'square') {
            $chip = array_replace($chip, ['bg' => 'transparent', 'border' => $text, 'case' => 'uppercase', 'weight' => '700', 'tracking' => '.04em']);
        } elseif ($cv === 'bracket') {
            $chip = array_replace($chip, ['bg' => 'transparent', 'border' => self::a($accent, 0.35), 'case' => 'uppercase', 'weight' => '700', 'tracking' => '.08em', 'text' => $text]);
        } elseif ($cv === 'clipped' || $cv === 'clipped_outline') {
            $chip = array_replace($chip, [
                'bg' => $cv === 'clipped' ? $s2o : 'transparent', 'border' => $cv === 'clipped_outline' ? $p['border_strong'] : 'transparent',
                'case' => 'uppercase', 'weight' => '700', 'tracking' => '.06em', 'clip' => 'polygon(8px 0, 100% 0, calc(100% - 8px) 100%, 0 100%)',
            ]);
        } elseif ($cv === 'tag') {
            $chip = array_replace($chip, ['bg' => $s1o, 'border' => $extra['ink'] ?? $text, 'weight' => '800', 'shadow' => '2px 2px 0 '.($extra['ink'] ?? $text)]);
        } elseif ($cv === 'bevel') {
            [$bl, $bd] = [$extra['bevel_light'] ?? '#ffffff', $extra['bevel_dark'] ?? $p['border_strong']];
            $chip = array_replace($chip, ['bg' => $s2o, 'border' => 'transparent', 'weight' => '700', 'shadow' => "inset -2px -2px 0 {$bd}, inset 2px 2px 0 {$bl}"]);
        } elseif ($cv === 'glass') {
            $chip = array_replace($chip, ['bg' => self::a('#ffffff', $dark ? 0.10 : 0.55), 'border' => self::a('#ffffff', $dark ? 0.16 : 0.8)]);
        } elseif ($cv === 'gradient_border') {
            $chip = array_replace($chip, ['bg' => $s1o, 'border' => self::a($brand, 0.45)]);
        } elseif ($cv === 'holo') {
            $chip = array_replace($chip, ['bg' => $s1o, 'border' => self::a($text, 0.12)]);
        } elseif ($cv === 'clay') {
            $chip = array_replace($chip, ['bg' => $s1o, 'shadow' => '0 4px 10px -4px '.self::a($brand, 0.35).', inset 0 -2px 0 '.self::a($brand, 0.12)]);
        } elseif ($cv === 'tabs') {
            $chip = array_replace($chip, ['bg' => 'transparent', 'text' => $muted, 'border' => 'transparent', 'weight' => '500']);
        }

        // active state
        [$actBg, $actText, $actBorder, $actShadow] = [$brandFill, $onBrand, 'transparent', 'none'];

        if ($ca === 'text') {
            [$actBg, $actText] = [$text, $dark ? $textInverse : '#ffffff'];

            if (! $dark) {
                $actText = Contrast::contrast('#ffffff', $text) >= 4.5 ? '#ffffff' : $bg;
            }
        } elseif ($ca === 'gradient') {
            $actBg = $brandGradient;
        } elseif ($ca === 'accent') {
            [$actBg, $actText] = [$accentFill, $onAccent];
        } elseif ($ca === 'underline') {
            [$actBg, $actText] = ['transparent', $text];
            $actShadow = 'inset 0 -2px 0 '.$brandInk;
        } elseif ($ca === 'bevel') {
            [$bl, $bd] = [$extra['bevel_light'] ?? '#ffffff', $extra['bevel_dark'] ?? $p['border_strong']];
            [$actBg, $actText] = [$s3o, $text];
            $actShadow = "inset 2px 2px 0 {$bd}, inset -2px -2px 0 {$bl}";
        } elseif ($ca === 'metal') {
            $actBg = $extra['metal'] ?? $brandGradient;
            $actText = '#1a1405';
        } elseif ($ca === 'holo') {
            [$actBg, $actText] = [$extra['holo'] ?? $brandGradient, '#14151f'];
        } elseif ($ca === 'tab') {
            [$actBg, $actText] = [$s1o, $text];
            $actShadow = 'inset 0 2px 0 '.$brandInk;
        }

        if ($theme['id'] === 'neo_pop') {
            [$actBg, $actText] = [$extra['ink'] ?? $text, $dark ? $bg : '#ffffff'];
        }

        if ($theme['id'] === 'clean_concrete') {
            [$actBg, $actText] = [$accentFill, '#111111'];
        }

        $tok += [
            '--avd-chip-bg' => $chip['bg'], '--avd-chip-text' => $chip['text'], '--avd-chip-border' => $chip['border'],
            '--avd-chip-case' => $chip['case'], '--avd-chip-weight' => $chip['weight'], '--avd-chip-tracking' => $chip['tracking'],
            '--avd-chip-clip' => $chip['clip'], '--avd-chip-shadow' => $chip['shadow'],
            '--avd-chip-active-bg' => $actBg, '--avd-chip-active-text' => $actText, '--avd-chip-active-border' => $actBorder,
            '--avd-chip-active-shadow' => $actShadow,
        ];

        if ($collect) {
            $need('chip-text/chip-bg', $chip['text'], $chip['bg'] !== 'transparent' ? self::opaque($chip['bg'], $bg) : $bg, 4.5);

            if (str_starts_with($actBg, 'linear-gradient')) {
                preg_match_all('/#[0-9a-fA-F]{6}/', $actBg, $m);

                foreach ($m[0] as $i => $stop) {
                    $need("chip-active-text/active-bg stop{$i}", $actText, $stop, 4.5);
                }
            } else {
                $need('chip-active-text/chip-active-bg', $actText, $actBg !== 'transparent' ? self::opaque($actBg, $bg) : $bg, 4.5);
            }
        }

        // ---- buttons
        $bv = $L['buttons'];
        $btn = ['bg' => $brandFill, 'text' => $onBrand, 'border' => 'transparent', 'shadow' => 'none', 'r' => self::px($S['button']), 'clip' => 'none', 'case' => 'none', 'weight' => '700', 'tracking' => '0'];

        if ($bv === 'pill_gradient') {
            $btn['bg'] = $brandGradient;
        } elseif ($bv === 'metal') {
            $btn = array_replace($btn, ['bg' => $extra['metal'] ?? $brandGradient, 'text' => '#1a1405', 'case' => 'uppercase', 'tracking' => '.12em', 'weight' => '700']);
        } elseif ($bv === 'clipped') {
            $btn = array_replace($btn, ['clip' => 'polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px)', 'case' => 'uppercase', 'tracking' => '.06em']);
        } elseif ($bv === 'hard') {
            $ink = $extra['ink'] ?? $text;
            $btn = array_replace($btn, ['border' => "2px solid {$ink}", 'shadow' => "3px 3px 0 {$ink}", 'text' => Contrast::contrast('#111111', $brandFill) >= 4.5 ? '#111111' : $onBrand]);
        } elseif ($bv === 'bevel') {
            $btn['shadow'] = 'inset -2px -2px 0 rgba(0,0,0,.35), inset 2px 2px 0 rgba(255,255,255,.55)';
        } elseif ($bv === 'clay') {
            $btn['shadow'] = '0 8px 18px -8px '.self::a($brand, 0.6).', inset 0 -3px 0 rgba(0,0,0,.14), inset 0 2px 0 rgba(255,255,255,.35)';
        } elseif ($bv === 'holo') {
            $btn = array_replace($btn, ['bg' => $extra['holo'] ?? $brandGradient, 'text' => '#14151f', 'border' => '1px solid '.self::a('#14151f', 0.12)]);
        } elseif ($bv === 'square_solid') {
            $btn = array_replace($btn, ['case' => 'uppercase', 'tracking' => '.04em', 'weight' => '700']);
        } elseif ($bv === 'square' || $bv === 'square_soft') {
            $btn['case'] = 'none';
        } elseif ($bv === 'leaf') {
            $btn['r'] = self::px($S['button']);
        }

        $btn2Bg = empty($L['glass']) ? $s2o : self::a('#ffffff', $dark ? 0.12 : 0.6);
        $btn2Text = $text;
        $btn2Border = 'transparent';

        if (in_array($bv, ['square', 'metal', 'clipped', 'hard', 'square_solid'], true)) {
            $btn2Bg = 'transparent';
            $btn2Border = self::str($S['bw'] ?: 1).'px solid '.($bv !== 'hard' ? $p['border_strong'] : ($extra['ink'] ?? $text));
        }

        $playBg = $p['play_bg'] ?? $btn['bg'];
        $playText = $p['play_text'] ?? $btn['text'];
        $tok += [
            '--avd-btn-bg' => $btn['bg'], '--avd-btn-text' => $btn['text'], '--avd-btn-border' => $btn['border'], '--avd-btn-shadow' => $btn['shadow'],
            '--avd-btn-r' => $btn['r'], '--avd-btn-clip' => $btn['clip'], '--avd-btn-case' => $btn['case'], '--avd-btn-weight' => $btn['weight'],
            '--avd-btn-tracking' => $btn['tracking'],
            '--avd-btn2-bg' => $btn2Bg, '--avd-btn2-text' => $btn2Text, '--avd-btn2-border' => $btn2Border,
            '--avd-play-bg' => $playBg, '--avd-play-text' => $playText,
        ];

        if ($collect) {
            preg_match_all('/#[0-9a-fA-F]{6}/', $btn['bg'], $m);
            $stops = str_starts_with($btn['bg'], 'linear-gradient') ? $m[0] : [$btn['bg']];

            foreach ($stops as $i => $stop) {
                $need("button-text/button-bg stop{$i}", $btn['text'], $stop, 4.5);
            }

            $need('button-2-text/button-2-bg', $btn2Text, $btn2Bg !== 'transparent' ? self::opaque($btn2Bg, $bg) : $bg, 4.5);

            if (! str_starts_with($playBg, 'linear')) {
                $need('play-text/play-bg', $playText, $playBg, 4.5);
            }
        }

        // ---- cards
        $cf = $L['card_frame'];
        $card = ['bg' => 'transparent', 'border' => 'none', 'shadow' => 'none', 'pad' => '0px', 'thumb_border' => 'none', 'thumb_shadow' => 'none',
            'thumb_deco' => 'none', 'hover_border' => 'none', 'hover_shadow' => 'none', 'clip' => 'none', 'titlebar' => 'none', 'body_pad' => '10px 2px 0'];

        if ($cf === 'framed') {
            $card = array_replace($card, ['bg' => $s1o, 'border' => '1px solid '.$p['border'], 'pad' => '8px', 'body_pad' => '10px 4px 4px', 'hover_border' => '1px solid '.self::a($brand, 0.45)]);
        } elseif ($cf === 'tile') {
            $card = array_replace($card, ['bg' => $s1o, 'border' => '1px solid '.$p['border'], 'body_pad' => '10px 12px 12px', 'hover_border' => '1px solid '.self::a($brandInk, 0.6)]);
        } elseif ($cf === 'tile_angle') {
            $card = array_replace($card, ['bg' => $s1o, 'border' => '1px solid '.$p['border'], 'body_pad' => '10px 12px 12px', 'hover_border' => '1px solid '.$brandFill,
                'clip' => 'polygon(0 0, 100% 0, 100% calc(100% - 14px), calc(100% - 14px) 100%, 0 100%)']);
        } elseif ($cf === 'matted') {
            $card = array_replace($card, ['thumb_border' => 'inset 0 0 0 1px '.self::a($text, 0.28).', inset 0 0 0 6px '.$s1o, 'pad' => '0px', 'body_pad' => '12px 0 0']);
        } elseif ($cf === 'neon') {
            $card = array_replace($card, ['bg' => self::a($s1o, 0.9), 'border' => '1px solid '.self::a($accent, 0.22), 'body_pad' => '10px 10px 12px',
                'hover_border' => '1px solid '.self::a($accent, 0.7), 'hover_shadow' => '0 0 0 1px '.self::a($accent, 0.35).', 0 0 24px '.self::a($accent, 0.22)]);
        } elseif ($cf === 'stripe') {
            $card = array_replace($card, ['thumb_deco' => "linear-gradient(90deg, {$brand}, {$brand2})", 'body_pad' => '10px 2px 0']);
        } elseif ($cf === 'window') {
            [$bl, $bd] = [$extra['bevel_light'] ?? '#ffffff', $extra['bevel_dark'] ?? $p['border_strong']];
            $card = array_replace($card, ['bg' => $s1o, 'border' => "2px solid {$bd}", 'pad' => '4px', 'body_pad' => '8px 6px 6px',
                'shadow' => "inset 2px 2px 0 {$bl}, 3px 3px 0 ".self::a($bd, 0.45),
                'titlebar' => 'linear-gradient(90deg, '.Contrast::fillForText($brand, '#ffffff', 4.5).', '.Contrast::fillForText($brand2, '#ffffff', 4.5).')']);
        } elseif ($cf === 'gilded') {
            $card['thumb_border'] = 'inset 0 0 0 1px '.self::a($brand, 0.55).', inset 0 0 0 4px '.self::a('#000000', 0.35);
        } elseif ($cf === 'soft_glow') {
            $card['hover_shadow'] = '0 18px 40px -14px '.self::a($brand, 0.45);
        } elseif ($cf === 'print') {
            $card = array_replace($card, ['bg' => $dark ? $s1o : '#ffffff', 'border' => '1px solid '.self::a($text, 0.08), 'pad' => '10px', 'body_pad' => '12px 4px 6px',
                'shadow' => '0 1px 2px '.self::a('#000000', 0.06).', 0 14px 28px -16px '.self::a('#3c2a10', $dark ? 0.8 : 0.35)]);
        } elseif ($cf === 'column') {
            $card = array_replace($card, ['border' => 'none', 'body_pad' => '10px 0 0', 'bg' => 'transparent']);
        } elseif ($cf === 'soft') {
            $card = array_replace($card, ['bg' => $s1o, 'pad' => '8px', 'body_pad' => '12px 6px 8px',
                'shadow' => '0 1px 2px '.self::a('#000000', $dark ? 0.3 : 0.04).', 0 16px 34px -18px '.self::a($dark ? '#000000' : '#1f2328', $dark ? 0.7 : 0.30)]);
        } elseif ($cf === 'leaf') {
            $card['body_pad'] = '10px 2px 0';
        } elseif ($cf === 'clay') {
            $card = array_replace($card, ['bg' => $s1o, 'pad' => '8px', 'body_pad' => '10px 6px 8px',
                'shadow' => $dark
                    ? 'inset 0 1px 0 '.self::a('#ffffff', 0.06).', 0 12px 26px -12px '.self::a('#000000', 0.6)
                    : '0 12px 26px -14px '.self::a($brand, 0.35).', inset 0 -3px 0 '.self::a($brand2, 0.10).', inset 0 2px 0 rgba(255,255,255,.9)']);
        } elseif ($cf === 'hairline') {
            $card = array_replace($card, ['body_pad' => '12px 0 12px', 'border' => 'none']);
        } elseif ($cf === 'caption') {
            $card['thumb_border'] = 'inset 0 0 0 1px '.self::a('#ffffff', 0.08);
        } elseif ($cf === 'glass') {
            $card = array_replace($card, ['bg' => self::a('#ffffff', $dark ? 0.07 : 0.62), 'border' => '1px solid '.self::a('#ffffff', $dark ? 0.14 : 0.85), 'pad' => '8px',
                'body_pad' => '10px 6px 6px', 'shadow' => 'inset 0 1px 0 '.self::a('#ffffff', $dark ? 0.18 : 0.9).', 0 18px 36px -22px '.self::a('#000000', $dark ? 0.55 : 0.25)]);
        } elseif ($cf === 'rim') {
            $card = array_replace($card, ['bg' => $s1o, 'border' => '1px solid '.self::a($brand, 0.28), 'pad' => '8px', 'body_pad' => '10px 6px 8px',
                'hover_border' => '1px solid '.self::a($brand, 0.65)]);
        } elseif ($cf === 'holo') {
            $card = array_replace($card, ['bg' => $s1o, 'border' => '2px solid transparent', 'pad' => '8px', 'body_pad' => '10px 6px 8px']);
        } elseif ($cf === 'hard') {
            $ink = $extra['ink'] ?? $text;
            $card = array_replace($card, ['bg' => $s1o, 'border' => "2px solid {$ink}", 'pad' => '0px', 'shadow' => "4px 4px 0 {$ink}", 'body_pad' => '10px 12px 12px',
                'hover_shadow' => "6px 6px 0 {$ink}"]);
        } elseif ($cf === 'brutal') {
            $card = array_replace($card, ['border' => "2px solid {$text}", 'bg' => $s1o, 'body_pad' => '10px 12px']);
        } elseif ($cf === 'clipped') {
            $card = array_replace($card, ['clip' => 'polygon(0 0, calc(100% - 16px) 0, 100% 16px, 100% 100%, 0 100%)', 'bg' => $s1o, 'body_pad' => '10px 10px 12px']);
        } elseif ($cf === 'clipped_outline') {
            $card = array_replace($card, ['bg' => $s1o, 'border' => '1px solid '.self::a($brand, 0.30), 'body_pad' => '10px 10px 12px',
                'clip' => 'polygon(14px 0, 100% 0, 100% calc(100% - 14px), calc(100% - 14px) 100%, 0 100%, 0 14px)',
                'hover_border' => '1px solid '.$brand]);
        } elseif ($cf === 'dashed') {
            $card = array_replace($card, ['border' => '1px dashed '.$p['border_strong'], 'body_pad' => '8px 10px']);
        }

        $tok += [
            '--avd-card-bg' => $card['bg'], '--avd-card-border' => $card['border'], '--avd-card-shadow' => $card['shadow'],
            '--avd-card-pad' => $card['pad'], '--avd-card-body-pad' => $card['body_pad'], '--avd-thumb-border' => $card['thumb_border'],
            '--avd-thumb-shadow' => $card['thumb_shadow'], '--avd-thumb-deco' => $card['thumb_deco'],
            '--avd-card-hover-border' => $card['hover_border'], '--avd-card-hover-shadow' => $card['hover_shadow'],
            '--avd-card-clip' => $card['clip'], '--avd-card-titlebar' => $card['titlebar'],
            '--avd-caption-scrim' => 'linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,.55) 38%, rgba(0,0,0,.86) 100%)',
            '--avd-caption-max' => '38%',
        ];

        // Real pages: a card without a fill has no surface the solid surface style could make opaque, so it stays
        // frameless there. It still clips its content to its rounded corners (overflow and paint containment), so a
        // body that runs to its bottom edge keeps its last line clear of the bottom corners, at every radius scale
        // (Gate A: Velvet Boudoir's 18px corner cut the first figure of every view count).
        if ($pageFit && $card['bg'] === 'transparent') {
            $tok['--avd-card-solid-bg'] = 'transparent';
            preg_match_all('/[\d.]+/', $tok['--avd-r-card'], $radii);
            $radii = array_map('floatval', $radii[0]) ?: [0.0];
            // border-radius shorthand: the bottom-right and bottom-left corners of 1-4 values.
            $bottomRadius = max($radii[2] ?? $radii[0], $radii[3] ?? $radii[1] ?? $radii[0]);
            $pad = preg_split('/\s+/', trim($card['body_pad'])) ?: ['0'];
            $pad = [$pad[0], $pad[1] ?? $pad[0], $pad[2] ?? $pad[0], $pad[3] ?? $pad[1] ?? $pad[0]];

            if ($card['pad'] === '0px' && $bottomRadius >= 8 && (float) $pad[2] === 0.0) {
                $pad[2] = 'calc('.self::str(floor($bottomRadius) === $bottomRadius ? (int) $bottomRadius : $bottomRadius).'px * var(--avd-radius-k, 1) * .55)';
                $tok['--avd-card-body-pad'] = implode(' ', $pad);
            }
        }

        if ($collect) {
            $cardBg = $card['bg'] !== 'transparent' ? self::opaque($card['bg'], $bg) : $bg;
            $need('card-title/card-bg', $text, $cardBg, 4.5);
            $need('card-meta/card-bg', $muted, $cardBg, 4.5);

            if ($L['card'] === 'card_glass_overlay') {
                $need('caption-title/scrim over white thumbnail (at 38% line)', '#ffffff', Oklch::over('rgba(0,0,0,.70)', '#ffffff'), 4.5);
            }
        }

        // ---- badges / duration / live
        $dur = $L['duration'];

        if ($dur === 'pill') {
            [$durBg, $durText, $durR] = ['rgba(8,10,16,.78)', '#ffffff', '999px'];
        } elseif ($dur === 'badge_solid') {
            [$durBg, $durText, $durR] = ['rgba(0,0,0,.82)', '#ffffff', $S['badge'] !== 999 ? self::px($S['badge']) : '4px'];
        } else {
            [$durBg, $durText, $durR] = ['rgba(0,0,0,.66)', '#ffffff', '2px'];
        }

        foreach (['#ffffff', '#000000', '#ffd27a'] as $under) {
            $need("duration-text/duration-bg over {$under}", $durText, Oklch::over($durBg, $under), 4.5);
        }

        $special = in_array($L['buttons'], ['metal', 'holo'], true);
        $tok += [
            '--avd-duration-bg' => $durBg, '--avd-duration-text' => $durText, '--avd-duration-r' => $durR,
            '--avd-badge-bg' => ! $special ? $brandFill : (($extra['metal'] ?? null) ?: ($extra['holo'] ?? null)),
            '--avd-badge-text' => ! $special ? $onBrand : ($L['buttons'] === 'metal' ? '#1a1405' : '#14151f'),
            '--avd-badge-r' => self::px($S['badge']),
            '--avd-new-bg' => $accentFill, '--avd-new-text' => $onAccent,
        ];

        if ($collect && $special) {
            preg_match_all('/#[0-9a-fA-F]{6}/', (string) ($extra[$L['buttons']] ?? ''), $m);

            foreach ($m[0] as $i => $stop) {
                $need("{$L['buttons']}-label/{$L['buttons']} stop{$i}", $L['buttons'] === 'metal' ? '#1a1405' : '#14151f', $stop, 4.5);
            }
        }

        // ---- hero
        $tok += [
            '--avd-hero-scrim' => 'linear-gradient(90deg, rgba(0,0,0,.82) 0%, rgba(0,0,0,.62) 38%, rgba(0,0,0,.18) 72%, rgba(0,0,0,0) 100%)',
            '--avd-hero-title' => '#ffffff', '--avd-hero-meta' => 'rgba(255,255,255,.86)',
            '--avd-hero-r' => $L['hero_variant'] !== 'billboard_full' ? self::px($S['card']) : '0px',
        ];
        // Critique M4: <= 768px the hero text spans the full width, so the scrim is bottom-up and sits behind
        // the whole text block (title, meta, buttons) at >= 62% black, whatever the poster.
        $tok['--avd-hero-scrim-phone'] = 'linear-gradient(0deg, rgba(0,0,0,.86) 0%, rgba(0,0,0,.70) 55%, rgba(0,0,0,.62) 78%, rgba(0,0,0,0) 100%)';
        $tok['--avd-hero-text-scrim'] = 'rgba(0,0,0,.62)';

        if ($collect) {
            $need('hero-title/scrim over white photo (left 38%)', '#ffffff', Oklch::over('rgba(0,0,0,.62)', '#ffffff'), 4.5);

            foreach (['white' => '#ffffff', 'yellow' => '#ffd400', 'skin' => '#f1c27d', 'sky' => '#bfe3ff'] as $name => $poster) {
                $scrim = Oklch::over('rgba(0,0,0,.62)', $poster);
                $need("phone hero title/62% scrim over {$name} poster", '#ffffff', $scrim, 4.5);
                $need("phone hero meta (white 86%)/62% scrim over {$name} poster", Oklch::over('rgba(255,255,255,.86)', $scrim), $scrim, 4.5);
            }
        }

        // ---- icons, kicker, section
        $ic = $L['icons'];
        [$iconColor, $iconBg, $iconR] = [$muted, 'transparent', '10px'];

        if (in_array($ic, ['tile', 'tile_round', 'tile_soft'], true)) {
            [$iconColor, $iconBg] = [$brandInk, self::a($brand, $dark ? 0.14 : 0.10)];
            $iconR = $ic === 'tile_round' ? '999px' : ($ic === 'tile_soft' ? '12px' : '999px');
        } elseif ($ic === 'brand') {
            $iconColor = $brandInk;
        } elseif ($ic === 'accent') {
            $iconColor = $accentInk;
        } elseif ($ic === 'glass') {
            [$iconColor, $iconBg] = [$text, self::a('#ffffff', $dark ? 0.12 : 0.6)];
        } elseif ($ic === 'tile_hard') {
            [$iconColor, $iconBg, $iconR] = ['#111111', $extra['pop2'] ?? $brand2, '10px'];
        }

        $tok += ['--avd-icon-color' => $iconColor, '--avd-icon-bg' => $iconBg, '--avd-icon-r' => $iconR];

        $sec = $L['section'];
        $kickerColor = $brandInk;
        [$prefix, $prefixColor, $rule, $align] = ["''", $brandInk, 'none', 'start'];
        $displayFill = 'none';

        if ($sec === 'bracket') {
            [$prefix, $prefixColor] = ["'// '", $accentInk];
        } elseif ($sec === 'prompt') {
            [$prefix, $prefixColor] = ["'> '", $brandInk];
        } elseif ($sec === 'comment') {
            [$prefix, $prefixColor] = ["'// '", $extra['syn_comment'] ?? $muted];
        } elseif ($sec === 'numbered') {
            [$prefix, $prefixColor] = ["'01  '", $faint];
        } elseif ($sec === 'rule' || $sec === 'rule_kicker') {
            $rule = '1px solid '.self::a($text, 0.22);
        } elseif ($sec === 'double_rule') {
            $rule = "3px double {$text}";
        } elseif ($sec === 'ornament') {
            [$prefix, $prefixColor, $align] = ["'\\25C6  '", $brandInk, 'center'];
            $rule = '1px solid '.self::a($brand, 0.4);
        } elseif ($sec === 'swiss_number') {
            [$prefix, $prefixColor, $rule] = ["'01 '", $brandInk, "3px solid {$text}"];
        } elseif ($sec === 'slash') {
            [$prefix, $prefixColor] = ["'/// '", $brandInk];
        } elseif ($sec === 'hazard') {
            [$prefix, $prefixColor] = ["'\\25B2 '", $accentInk];
        } elseif ($sec === 'chrome') {
            $displayFill = $dark
                ? 'linear-gradient(180deg, '.Oklch::mix('#ffffff', $brand2, 0.15)." 0%, {$brandInk} 100%)"
                : "linear-gradient(180deg, {$brandInk}, ".Contrast::fitLightness($brand2, [$bg], 4.5, 'darker').')';
        } elseif ($sec === 'seal') {
            [$prefix, $prefixColor] = ["'\\25A0  '", $brandInk];
        } elseif ($sec === 'leaf') {
            [$prefix, $prefixColor] = ["'\\2766  '", $brandInk];
        } elseif ($sec === 'orbit') {
            [$prefix, $prefixColor] = ["'\\25CB  '", $accentInk];
        } elseif ($sec === 'sparkle') {
            [$prefix, $prefixColor] = ["'\\2726  '", $brandInk];
        } elseif ($sec === 'emoji_dot') {
            [$prefix, $prefixColor] = ["'\\25CF  '", $brandInk];
        } elseif ($sec === 'glow_dot') {
            [$prefix, $prefixColor] = ["'\\25CF  '", $accentInk];
        } elseif ($sec === 'sun') {
            [$prefix, $prefixColor] = ["'\\25D2  '", $brandInk];
        } elseif ($sec === 'holo_bar') {
            $rule = 'none';
        }

        $tok += [
            '--avd-kicker-color' => $kickerColor, '--avd-section-prefix' => $prefix, '--avd-section-prefix-color' => $prefixColor,
            '--avd-section-rule' => $rule, '--avd-section-align' => $align, '--avd-display-fill' => $displayFill,
            '--avd-rank-color' => self::a($text, 0.9), '--avd-rank-stroke' => self::a($text, 0.85),
            '--avd-glow' => self::a($brand, 0.30), '--avd-divider' => $p['border'],
        ];

        // ---- backdrop
        $tok += ['--avd-backdrop-tile' => $tile, '--avd-page-backdrop' => $backdrop, '--avd-backdrop-floor' => $floor];

        // ---- extra (theme-specific)
        foreach ($extra as $k => $v) {
            if ($k === 'backdrop_peak') {
                continue;
            }

            $tok['--avd-x-'.str_replace('_', '-', $k)] = $v;
        }

        foreach (['syn_keyword', 'syn_string', 'syn_number', 'syn_func', 'syn_type', 'syn_comment'] as $k) {
            if (isset($extra[$k])) {
                $need("syntax {$k}/bg", $extra[$k], $bg, 4.5);
            }
        }

        // ---- compatibility aliases (audit G3) + Bootstrap bridge (critique M2)
        $tok = array_replace($tok, [
            '--avd-primary' => $brand, '--avd-primary-rgb' => self::rgb($brand), '--avd-surface-rgb' => self::rgb($s1o),
            '--avd-dark-surface-rgb' => self::rgb($s1o), '--avd-surface-card' => $s1o, '--avd-cyan' => $accent, '--avd-cyan-rgb' => self::rgb($accent),
            '--avd-brand-hot' => $brandInk,
            // .text-primary / .link-primary read --bs-primary-rgb: the brand INK (text-safe); .bg-primary / .btn-primary
            // are restyled to brand-fill + on-brand in CSS.
            '--bs-primary' => $brandInk, '--bs-primary-rgb' => self::rgb($brandInk),
            '--bs-success' => $tok['--avd-success'], '--bs-success-rgb' => self::rgb($tok['--avd-success']),
            '--bs-warning' => $tok['--avd-warning'], '--bs-warning-rgb' => self::rgb($tok['--avd-warning']),
            '--bs-danger' => $tok['--avd-danger'], '--bs-danger-rgb' => self::rgb($tok['--avd-danger']),
            '--bs-info' => $tok['--avd-info'], '--bs-info-rgb' => self::rgb($tok['--avd-info']),
            '--bs-secondary-color' => $muted, '--bs-tertiary-color' => $muted,
            '--bs-body-bg' => $bg, '--bs-body-color' => $text, '--bs-border-color' => self::opaque($p['border'], $bg),
            '--bs-link-color' => $brandInk, '--bs-link-hover-color' => $brandInk,
        ]);

        return [array_map('strval', $tok), $rows];
    }

    /**
     * The colours a backdrop paints behind page text, each as the opaque colour over the page background: every
     * colour stop of its page-wide layers (glows, washes, mesh, vignette), an rgba() stop at its full alpha. Tiled
     * patterns (a layer with a background size: dot grids, hairline grids, halftone), radial dots of at most 40px,
     * repeating gradients (rain lines) and url() images (sparkles, topography) are small marks, not areas text sits
     * on, and are skipped.
     *
     * @return list<string>
     */
    private static function backdropColours(string $backdrop, string $bg): array
    {
        $out = [];
        $glows = [];
        $depth = 0;
        $layer = '';

        foreach (str_split($backdrop.',') as $char) {
            if ($char === ',' && $depth === 0) {
                $layer = trim($layer);

                $dot = preg_match('/^radial-gradient\(\s*(?:circle\s+|ellipse\s+)?(\d+(?:\.\d+)?)px(?:\s+(\d+(?:\.\d+)?)px)?/', $layer, $size) === 1
                    && max((float) $size[1], (float) ($size[2] ?? 0)) <= 40;

                if ($layer !== '' && $layer !== 'none' && ! $dot && ! str_starts_with($layer, 'repeating-') && ! str_starts_with($layer, 'url(')
                    && ! preg_match('/\)\s*[^,()]*\//', $layer)
                    && preg_match_all('/rgba?\([^)]*\)|#[0-9a-fA-F]{6}(?![0-9a-fA-F])|#[0-9a-fA-F]{3}(?![0-9a-fA-F])/', $layer, $matches)) {
                    foreach ($matches[0] as $colour) {
                        $out[] = self::opaque($colour, $bg);
                    }

                    $strongest = collect($matches[0])->sortByDesc(fn (string $colour): float => Oklch::parse($colour)[3])->first();

                    if (preg_match('/^radial-gradient\(\s*\d+px\s+\d+px/', $layer) === 1 && Oklch::parse($strongest)[3] <= 0.15) {
                        $glows[] = $strongest;
                    }
                }

                $layer = '';

                continue;
            }

            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            $layer .= $char;
        }

        // Soft wash glows (radial, sized in px, alpha <= .15) are wider than a phone: there they overlap, and their
        // strongest stops stacked in paint order (the first layer on top) are one more colour text sits on
        // (Interstellar light: Bootstrap inks 4.41-4.46:1 on a phone). Strong accent glows stay single stops.
        if (count($glows) > 1) {
            $out[] = array_reduce(array_reverse($glows), fn (string $under, string $colour): string => self::opaque($colour, $under), $bg);
        }

        return array_values(array_unique($out));
    }

    /**
     * The colour of the page backdrop that is hardest to read on where text can sit: the lightest point (dark mode)
     * or the darkest (light mode) of the backdrop as the browser paints it, sampled on a grid over
     * BACKDROP_VIEWPORTS below BACKDROP_TOP. Its px-sized radial glows fade linearly from their centre to their
     * transparent stop along the ellipse and stack in paint order (the first layer on top) over the backdrop's
     * linear-gradient floor, or over the page background when it has none. Small marks (dots, tiles, repeating
     * lines, url() images) and %-sized vignettes are skipped as in backdropColours(). Contrast depends on luminance
     * only, so one colour stands for every ink. --avd-bg when the backdrop has no glow.
     */
    private static function backdropPeak(string $backdrop, string $bg, bool $dark): string
    {
        $glows = [];
        $floor = null;
        $layers = [];
        $depth = 0;
        $layer = '';

        foreach (str_split($backdrop.',') as $char) {
            if ($char === ',' && $depth === 0) {
                $layers[] = trim($layer);
                $layer = '';

                continue;
            }

            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            $layer .= $char;
        }

        $colour = '(rgba?\([^)]*\)|#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})';

        foreach ($layers as $layer) {
            if (preg_match('/^radial-gradient\(\s*(\d+(?:\.\d+)?)px\s+(\d+(?:\.\d+)?)px\s+at\s+(-?\d+(?:\.\d+)?)%\s+(-?\d+(?:\.\d+)?)%\s*,\s*'.$colour.'\s*,\s*transparent\s+(\d+(?:\.\d+)?)%\s*\)$/', $layer, $m) === 1
                && max((float) $m[1], (float) $m[2]) > 40) {
                $glows[] = ['w' => (float) $m[1], 'h' => (float) $m[2], 'x' => (float) $m[3] / 100, 'y' => (float) $m[4] / 100,
                    'c' => Oklch::parse($m[5]), 'end' => (float) $m[6] / 100];
            } elseif ($floor === null && preg_match('/^linear-gradient\(\s*(-?\d+(?:\.\d+)?)deg\s*,(.*)\)$/s', $layer, $m) === 1) {
                $floor = self::gradientStops((float) $m[1], $m[2], $colour);
            }
        }

        if ($glows === []) {
            return $bg;
        }

        $memo = $backdrop.'|'.$bg.'|'.($dark ? 'd' : 'l');

        if (isset(self::$backdropPeaks[$memo])) {
            return self::$backdropPeaks[$memo];
        }

        $base = Oklch::parse($bg);
        $best = null;
        $linear = static fn (float $v): float => ($v /= 255) <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        foreach (self::BACKDROP_VIEWPORTS as [$vw, $vh]) {
            // The grid plus the viewport edges and every glow centre inside the sampled area (a glow peaks there,
            // e.g. Outrun's horizon glow at the bottom centre of the screen).
            $xs = array_merge(range(0, $vw, self::BACKDROP_STEP), [$vw]);
            $ys = array_merge(range(self::BACKDROP_TOP, $vh, self::BACKDROP_STEP), [$vh]);

            foreach ($glows as $g) {
                $xs[] = (int) round(min($vw, max(0, $g['x'] * $vw)));
                $ys[] = (int) round(min($vh, max(self::BACKDROP_TOP, $g['y'] * $vh)));
            }

            foreach (array_unique($xs) as $x) {
                foreach (array_unique($ys) as $y) {
                    $c = $floor === null ? $base : self::gradientAt($floor, $vw, $vh, $x, $y, $base);

                    foreach (array_reverse($glows) as $g) {
                        $r = hypot(($x - $g['x'] * $vw) / $g['w'], ($y - $g['y'] * $vh) / $g['h']);
                        $a = $g['c'][3] * max(0.0, 1 - $r / $g['end']);

                        for ($i = 0; $i < 3; $i++) {
                            $c[$i] = $g['c'][$i] * $a + $c[$i] * (1 - $a);
                        }
                    }

                    // Rounded toward the harder side (lighter in dark mode, darker in light mode), so an ink fitted
                    // to this colour also reads on the unrounded paint.
                    $rgb = $dark ? [ceil($c[0]), ceil($c[1]), ceil($c[2])] : [floor($c[0]), floor($c[1]), floor($c[2])];
                    $lum = 0.2126 * $linear($rgb[0]) + 0.7152 * $linear($rgb[1]) + 0.0722 * $linear($rgb[2]);

                    if ($best === null || ($dark ? $lum > $best[0] : $lum < $best[0])) {
                        $best = [$lum, $rgb];
                    }
                }
            }
        }

        return self::$backdropPeaks[$memo] = Oklch::toHex($best[1]);
    }

    /**
     * The stops of a linear-gradient(<angle>deg, ...) as [angle, [[r, g, b, a], position 0..1 or px string|null]...],
     * or null when a stop is not a colour with an optional % / px position.
     *
     * @return array{0: float, 1: list<array{0: array, 1: float|string|null}>}|null
     */
    private static function gradientStops(float $angle, string $list, string $colour): ?array
    {
        $stops = [];
        $depth = 0;
        $part = '';

        foreach (str_split($list.',') as $char) {
            if ($char === ',' && $depth === 0) {
                if (preg_match('/^'.$colour.'(?:\s+(-?\d+(?:\.\d+)?)(%|px))?$/', trim($part), $m) !== 1) {
                    return null;
                }

                $stops[] = [Oklch::parse($m[1]), isset($m[2]) && $m[2] !== '' ? ($m[3] === '%' ? (float) $m[2] / 100 : $m[2].'px') : null];
                $part = '';

                continue;
            }

            $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            $part .= $char;
        }

        return count($stops) >= 2 ? [$angle, $stops] : null;
    }

    /**
     * The colour of a linear gradient (gradientStops()) at one point of a vw x vh box (CSS gradient line through
     * the centre, its length set by the angle), composited over the opaque $under; [r, g, b, 1].
     */
    private static function gradientAt(?array $gradient, int $vw, int $vh, int $x, int $y, array $under): array
    {
        if ($gradient === null) {
            return $under;
        }

        [$angle, $stops] = $gradient;
        $rad = deg2rad($angle);
        $length = abs($vw * sin($rad)) + abs($vh * cos($rad));
        $t = (($x - $vw / 2) * sin($rad) - ($y - $vh / 2) * cos($rad)) / $length + 0.5;
        $pos = array_map(fn (array $stop): ?float => is_string($stop[1]) ? (float) $stop[1] / $length : $stop[1], $stops);
        $last = count($pos) - 1;
        $pos[0] ??= 0.0;
        $pos[$last] ??= 1.0;

        for ($i = 1; $i <= $last; $i++) {
            if ($pos[$i] !== null && $pos[$i] < $pos[$i - 1]) {
                $pos[$i] = $pos[$i - 1];
            }
        }

        for ($i = 1; $i < $last; $i++) {
            if ($pos[$i] === null) {
                $j = $i;

                while ($pos[$j] === null) {
                    $j++;
                }

                for ($k = $i; $k < $j; $k++) {
                    $pos[$k] = $pos[$i - 1] + ($pos[$j] - $pos[$i - 1]) * ($k - $i + 1) / ($j - $i + 1);
                }
            }
        }

        if ($t <= $pos[0]) {
            $c = $stops[0][0];
        } elseif ($t >= $pos[$last]) {
            $c = $stops[$last][0];
        } else {
            $c = $stops[$last][0];

            for ($i = 1; $i <= $last; $i++) {
                if ($t <= $pos[$i]) {
                    $f = ($t - $pos[$i - 1]) / (($pos[$i] - $pos[$i - 1]) ?: 1e-9);
                    $c = array_map(fn (float|int $a, float|int $b): float => $a + ($b - $a) * $f, $stops[$i - 1][0], $stops[$i][0]);

                    break;
                }
            }
        }

        return [$c[0] * $c[3] + $under[0] * (1 - $c[3]), $c[1] * $c[3] + $under[1] * (1 - $c[3]), $c[2] * $c[3] + $under[2] * (1 - $c[3]), 1.0];
    }

    /**
     * The page backdrop, the floor layer (horizon, checker sky) and the texture tile of one backdrop style.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function backdrop(string $bk, bool $dark, string $brand, string $brand2, string $accent, string $bg, string $bg2, string $text): array
    {
        $B = self::rgb($brand);
        $B2 = self::rgb($brand2);
        $A = self::rgb($accent);
        $f = static fn (float|int $v): string => self::str($v);
        [$backdrop, $floor, $tile] = ['none', 'none', 'none'];

        switch ($bk) {
            case 'dawn_glow':
                $backdrop = "radial-gradient(1100px 520px at 6% -12%, rgba({$B},{$f($dark ? .16 : .10)}), transparent 62%), "
                    ."radial-gradient(900px 480px at 96% -14%, rgba({$A},{$f($dark ? .11 : .08)}), transparent 60%), "
                    ."linear-gradient(180deg, {$bg2} 0%, {$bg} 460px)";
                break;
            case 'dot_grid':
                $backdrop = 'radial-gradient('.self::a($dark ? '#ffffff' : '#000000', $dark ? .075 : .085).' 1px, transparent 1.4px) 0 0 / 22px 22px';
                break;
            case 'neon_rain':
                $backdrop = "radial-gradient(1200px 420px at 50% -12%, rgba({$B},{$f($dark ? .22 : .12)}), transparent 70%), "
                    ."repeating-linear-gradient(105deg, rgba({$A},{$f($dark ? .035 : .05)}) 0 1px, transparent 1px 22px), "
                    ."linear-gradient(rgba({$A},{$f($dark ? .05 : .07)}) 1px, transparent 1px) 0 0 / 48px 48px, "
                    ."linear-gradient(90deg, rgba({$A},{$f($dark ? .05 : .07)}) 1px, transparent 1px) 0 0 / 48px 48px";
                break;
            case 'uv_glow':
                $backdrop = "radial-gradient(900px 620px at -5% -10%, rgba({$B},{$f($dark ? .26 : .14)}), transparent 62%), "
                    ."radial-gradient(800px 600px at 105% 105%, rgba({$B2},{$f($dark ? .18 : .10)}), transparent 62%), "
                    ."linear-gradient(160deg, {$bg2}, {$bg} 60%)";
                break;
            case 'horizon':
                $backdrop = "radial-gradient(520px 260px at 50% 100%, rgba({$B2},{$f($dark ? .35 : .25)}), transparent 70%), "
                    ."radial-gradient(1100px 420px at 50% -10%, rgba({$B},{$f($dark ? .18 : .10)}), transparent 70%), "
                    ."linear-gradient(180deg, {$bg2} 0%, {$bg} 55%, ".Oklch::mix($bg, $brand, .10).' 100%)';
                $floor = "linear-gradient(rgba({$B},{$f($dark ? .55 : .45)}) 1.5px, transparent 1.5px) 0 0 / 100% 46px, "
                    ."linear-gradient(90deg, rgba({$B},{$f($dark ? .55 : .45)}) 1.5px, transparent 1.5px) 0 0 / 70px 100%";
                break;
            case 'checker_sky':
                $backdrop = "linear-gradient(180deg, {$bg2} 0%, {$bg} 46%, ".Oklch::mix($bg, $dark ? '#123d6b' : '#bdefff', .55).' 100%)';
                $floor = 'conic-gradient('.self::a($brand2, $dark ? .30 : .22).' 25%, transparent 0 50%, '.self::a($brand2, $dark ? .30 : .22).' 0 75%, transparent 0) 0 0 / 80px 80px';
                break;
            case 'gold_vignette':
                $backdrop = "radial-gradient(1000px 520px at 50% -14%, rgba({$B},{$f($dark ? .12 : .10)}), transparent 70%), "
                    ."radial-gradient(130% 95% at 50% 35%, transparent 62%, rgba(0,0,0,{$f($dark ? .55 : .06)}) 100%)";
                break;
            case 'grain_vignette':
                $tile = self::grain($dark ? .10 : .08);
                $backdrop = "radial-gradient(125% 95% at 50% 35%, transparent 58%, rgba(0,0,0,{$f($dark ? .5 : .08)}) 100%)";
                break;
            case 'velvet':
                $tile = self::grain(.06);
                $backdrop = "radial-gradient(1000px 640px at 50% -22%, rgba({$B2},{$f($dark ? .30 : .14)}), transparent 70%), "
                    ."radial-gradient(130% 100% at 50% 30%, transparent 55%, rgba(0,0,0,{$f($dark ? .55 : .05)}) 100%)";
                break;
            case 'paper':
                $tile = self::grain($dark ? .08 : .06);
                $backdrop = "linear-gradient(180deg, {$bg2}, {$bg})";
                break;
            case 'newsprint':
                $tile = self::grain($dark ? .08 : .07);
                $backdrop = 'none';
                break;
            case 'topo':
                $backdrop = self::topo($B, $dark ? .09 : .12).", radial-gradient(900px 480px at 80% -10%, rgba({$B},{$f($dark ? .10 : .08)}), transparent 70%)";
                break;
            case 'sun_grain':
                $tile = self::grain(.07);
                $backdrop = "radial-gradient(900px 520px at 88% -12%, rgba({$B2},{$f($dark ? .22 : .25)}), transparent 65%), linear-gradient(180deg, {$bg2}, {$bg} 520px)";
                break;
            case 'washi':
                $tile = self::grain($dark ? .08 : .09);
                $backdrop = "linear-gradient(180deg, {$bg2}, {$bg})";
                break;
            case 'abyss':
                if ($dark) {
                    $dots = [];

                    foreach ([[12, 34, .6], [28, 70, .45], [46, 22, .5], [63, 58, .55], [79, 30, .4], [90, 76, .5], [8, 88, .35]] as [$x, $y, $o]) {
                        $dots[] = "radial-gradient(3px 3px at {$x}% {$y}%, rgba({$A},{$f($o)}), transparent 70%)";
                    }

                    $backdrop = implode(', ', $dots).", linear-gradient(180deg, {$bg2} 0%, ".Oklch::mix($bg2, $bg, .55)." 38%, {$bg} 100%)";
                } else {
                    $backdrop = "linear-gradient(180deg, {$bg2} 0%, {$bg} 60%, ".Oklch::mix($bg, '#bfe0f2', .5).' 100%)';
                }
                break;
            case 'stars':
                $tile = $dark ? self::stars(1) : 'none';
                $backdrop = "radial-gradient(800px 520px at 82% -6%, rgba({$B2},{$f($dark ? .20 : .12)}), transparent 70%), "
                    ."radial-gradient(700px 520px at 8% 18%, rgba({$B},{$f($dark ? .16 : .10)}), transparent 70%)";
                break;
            case 'aurora':
                $backdrop = "radial-gradient(900px 520px at 14% -8%, rgba({$B},{$f($dark ? .30 : .30)}), transparent 64%), "
                    ."radial-gradient(820px 520px at 86% 4%, rgba({$B2},{$f($dark ? .28 : .22)}), transparent 64%), "
                    ."radial-gradient(900px 620px at 50% 112%, rgba({$A},{$f($dark ? .16 : .18)}), transparent 70%), linear-gradient(180deg,{$bg2},{$bg})";
                break;
            case 'pastel_mesh':
                $backdrop = $dark
                    ? "radial-gradient(800px 600px at 0% 0%, rgba({$B},.22), transparent 60%), radial-gradient(800px 600px at 100% 0%, rgba({$B2},.18), transparent 60%), "
                        ."radial-gradient(900px 700px at 60% 100%, rgba({$A},.14), transparent 65%)"
                    : 'radial-gradient(820px 620px at 0% 0%, #ffd9c7, transparent 60%), radial-gradient(820px 620px at 100% 0%, #e4d6ff, transparent 60%), '
                        .'radial-gradient(900px 700px at 60% 105%, #cfe8ff, transparent 65%)';
                break;
            case 'opal':
                $backdrop = "linear-gradient(120deg, rgba(255,179,225,{$f($dark ? .08 : .20)}) 0%, rgba(155,231,255,{$f($dark ? .07 : .20)}) 50%, rgba(255,241,168,{$f($dark ? .06 : .18)}) 100%), "
                    ."radial-gradient(800px 400px at 50% -10%, rgba(255,255,255,{$f($dark ? .05 : .7)}), transparent 70%)";
                break;
            case 'candy_blobs':
                $backdrop = "radial-gradient(520px 420px at 6% 8%, rgba({$B},{$f($dark ? .18 : .16)}), transparent 70%), "
                    ."radial-gradient(560px 440px at 96% 16%, rgba({$B2},{$f($dark ? .14 : .20)}), transparent 70%), "
                    ."radial-gradient(600px 500px at 70% 100%, rgba({$A},{$f(.10)}), transparent 70%)";
                break;
            case 'sparkles':
                $backdrop = self::sparkles(str_replace('#', '%23', $brand), str_replace('#', '%23', $brand2), $dark ? .55 : .5).', '
                    ."radial-gradient(900px 600px at 20% -10%, rgba({$B},{$f($dark ? .18 : .14)}), transparent 65%), "
                    ."radial-gradient(800px 600px at 100% 100%, rgba({$B2},{$f(.12)}), transparent 65%), linear-gradient(180deg,{$bg2},{$bg})";
                break;
            case 'halftone':
                $backdrop = 'radial-gradient('.self::a($text, $dark ? .12 : .10).' 1.3px, transparent 1.7px) 0 0 / 16px 16px';
                break;
            case 'concrete':
                $tile = self::grain($dark ? .09 : .10);
                $backdrop = 'linear-gradient('.self::a($text, .07).' 1px, transparent 1px) 0 0 / 96px 96px, '
                    .'linear-gradient(90deg, '.self::a($text, .07).' 1px, transparent 1px) 0 0 / 96px 96px';
                break;
            case 'stripes':
                $backdrop = "repeating-linear-gradient(-45deg, rgba({$B},{$f($dark ? .045 : .06)}) 0 2px, transparent 2px 14px), "
                    ."radial-gradient(1000px 420px at 50% -16%, rgba({$B},{$f($dark ? .22 : .14)}), transparent 70%)";
                break;
            case 'carbon':
                $backdrop = "repeating-linear-gradient(45deg, rgba(255,255,255,{$f($dark ? .028 : .0)}) 0 2px, transparent 2px 4px), "
                    ."repeating-linear-gradient(-45deg, rgba(0,0,0,{$f($dark ? .30 : .03)}) 0 2px, transparent 2px 4px), "
                    ."radial-gradient(900px 400px at 50% -10%, rgba({$B},{$f($dark ? .14 : .10)}), transparent 70%)";
                break;
            case 'panels':
                $backdrop = 'linear-gradient(115deg, transparent 0 58%, '.self::a('#ffffff', $dark ? .03 : .65).' 58% 76%, transparent 76%), '
                    ."linear-gradient(115deg, transparent 0 77%, rgba({$B},{$f($dark ? .10 : .14)}) 77% 78.2%, transparent 78.2%), "
                    ."linear-gradient(115deg, transparent 0 80%, rgba({$B2},{$f(.10)}) 80% 80.8%, transparent 80.8%)";
                break;
            case 'scanlines':
                $backdrop = $dark
                    ? "repeating-linear-gradient(0deg, rgba(0,0,0,{$f(.30)}) 0 1px, transparent 1px 3px), "
                        ."radial-gradient(120% 90% at 50% 40%, rgba({$B},{$f(.08)}), transparent 70%)"
                    : "repeating-linear-gradient(180deg, {$bg} 0 44px, {$bg2} 44px 88px)";
                break;
        }

        return [$backdrop, $floor, $tile];
    }

    // ------------------------------------------------------------------ helpers

    private static function opaque(string $color, string $under): string
    {
        return Oklch::parse($color)[3] < 1 ? Oklch::over($color, $under) : Oklch::hex($color);
    }

    private static function rgb(string $color): string
    {
        return Oklch::rgbTriplet($color);
    }

    private static function a(string $color, float $alpha): string
    {
        return Oklch::rgba($color, $alpha);
    }

    /** Python's str() of an int or float (shortest repr; '0.0' for a float zero). */
    private static function str(int|float|string $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        return var_export($value, true);
    }

    private static function px(int|float|string $value): string
    {
        return is_string($value) ? $value : self::str($value).'px';
    }

    private static function fontStack(string $family): string
    {
        $kind = in_array($family, self::SERIF_FAMILIES, true) ? 'serif' : (in_array($family, self::MONO_FAMILIES, true) ? 'mono' : 'sans');

        return "'{$family}', ".match ($kind) {
            'mono' => "ui-monospace, 'Cascadia Code', Consolas, monospace",
            'serif' => "Georgia, 'Times New Roman', serif",
            default => "system-ui, 'Segoe UI', Roboto, sans-serif",
        };
    }

    private static function grain(float $op): string
    {
        return str_replace('{op}', Oklch::number($op), self::GRAIN_SVG).' 0 0 / 160px 160px';
    }

    private static function topo(string $rgb, float $op): string
    {
        $paths = 'M0 40 C40 20 80 60 120 40 S200 20 240 40 M0 80 C50 60 90 100 140 80 S210 60 240 80'
            .' M0 120 C40 100 100 140 150 118 S220 100 240 120 M0 160 C60 140 100 180 160 158 S220 140 240 160'
            .' M0 200 C40 182 90 220 140 198 S210 180 240 200';

        return 'url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'240\' height=\'240\'>'
            ."<path d='{$paths}' fill='none' stroke='rgba({$rgb},".self::str($op).")' stroke-width='1.2'/></svg>\") 0 0 / 240px 240px";
    }

    private static function stars(float|int $op): string
    {
        $dots = '';

        foreach (self::STAR_FIELD as [$cx, $cy, $r, $o]) {
            $dots .= "<circle cx='{$cx}' cy='{$cy}' r='{$r}' fill='white' opacity='".sprintf('%.2f', $o * $op)."'/>";
        }

        return "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='300' height='300'>{$dots}</svg>\") 0 0 / 300px 300px";
    }

    private static function sparkles(string $c1, string $c2, float $op): string
    {
        $star = static function (int $x, int $y, int|float $s, string $color) use ($op): string {
            $n = static fn (int|float $v): string => self::str($v);

            return "<path d='M{$x} {$n($y - $s)} L{$n($x + $s * .28)} {$n($y - $s * .28)} L{$n($x + $s)} {$y} L{$n($x + $s * .28)} {$n($y + $s * .28)} L{$x} {$n($y + $s)} "
                ."L{$n($x - $s * .28)} {$n($y + $s * .28)} L{$n($x - $s)} {$y} L{$n($x - $s * .28)} {$n($y - $s * .28)} Z' fill='{$color}' opacity='".self::str($op)."'/>";
        };
        $body = $star(30, 40, 5, $c1).$star(150, 90, 3.5, $c2).$star(220, 30, 4, $c1).$star(90, 170, 6, $c2).$star(250, 200, 3, $c1).$star(180, 250, 4.5, $c2);

        return "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='280' height='280'>{$body}</svg>\") 0 0 / 280px 280px";
    }

    private static function mirrorX(string $x): string
    {
        $x = trim($x);

        if ($x === '0') {
            return '100%';
        }

        if ($x === '100%') {
            return '0';
        }

        if (preg_match('/^calc\(100% - ([\d.]+px)\)$/', $x, $m)) {
            return $m[1];
        }

        if (preg_match('/^[\d.]+px$/', $x)) {
            return "calc(100% - {$x})";
        }

        if (preg_match('/^[\d.]+%$/', $x)) {
            return Oklch::number(100 - (float) substr($x, 0, -1)).'%';
        }

        return $x;
    }

    private static function mirrorPolygon(string $value): string
    {
        $inner = substr(trim($value), strlen('polygon('), -1);
        $points = [];
        $depth = 0;
        $current = '';

        foreach (str_split($inner) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }

            if ($ch === ',' && $depth === 0) {
                $points[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        $points[] = trim($current);
        $out = [];

        foreach ($points as $point) {
            $out[] = preg_match('/^(calc\([^)]*\)|\S+)\s+(calc\([^)]*\)|\S+)$/', $point, $m)
                ? self::mirrorX($m[1]).' '.$m[2]
                : $point;
        }

        return 'polygon('.implode(', ', array_reverse($out)).')';
    }
}
