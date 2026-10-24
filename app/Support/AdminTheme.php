<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The admin's own look: the accent colour (made safe by the colour engine), light/dark and the icon sprite.
 *
 * The admin never follows the public theme, except for the accent: the published site colour, or neutral
 * blue when the site colour sits in the red zone of Delete/danger (a pink or red site would make "Save" look
 * like "Delete"). Rules and reference values: admin_design_spec.json tokens.accent and
 * spec/data/color_engine_golden.json (admin_accent_cases).
 */
final class AdminTheme
{
    /** Admin surface and canvas of each mode (resources/admin/admin.scss). */
    public const SURFACES = [
        'light' => ['surface' => '#ffffff', 'canvas' => '#f4f5f7'],
        'dark' => ['surface' => '#1a1d23', 'canvas' => '#121419'],
    ];

    public const NEUTRAL_ACCENT = '#1f5bc7';

    /** The site colour a fresh install publishes (the theme default) when none is stored. */
    public const DEFAULT_SITE_COLOUR = '#ff3d61';

    private const WHITE = '#ffffff';
    private const INK = '#16191e';
    private const ADMIN_BUDGET = 0.16;
    // The same zone as App\Support\Color\Contrast::RED_ZONE (OKLCH hue; 25 -> 35 in 1.2.2), whose added part (above
    // 25) counts only strong reds (chroma 0.10 or more), as Contrast::RED_ZONE_EDGE / RED_ZONE_EDGE_MIN_CHROMA.
    private const RED_ZONE = [330.0, 35.0];
    private const RED_ZONE_EDGE = 25.0;
    private const RED_ZONE_EDGE_MIN_CHROMA = 0.10;

    /** CSS variable suffix per token: --ac-l / --ac-d hold the light / dark value (see admin.scss). */
    private const STYLE_VARS = [
        'accent' => 'ac',
        'on_accent' => 'oa',
        'accent_ink' => 'ai',
        'accent_soft' => 'as',
        'accent_line' => 'al',
        'focus' => 'fo',
    ];

    /** Symbols every admin page carries for the scripts (toasts, dialogs, palette, menus). */
    private const BASE_ICONS = ['x', 'check', 'check-circle', 'alert', 'alert-circle', 'info', 'search', 'chev-right', 'file', 'sliders', 'user', 'video', 'external'];

    /** @var array<string, true> */
    private static array $usedIcons = [];

    /** @var array<string, string>|null */
    private static ?array $symbols = null;

    /** @var array<string, array> */
    private static array $accentCache = [];

    /**
     * The admin accent tokens for one mode.
     *
     * @return array{accent: string, on_accent: string, accent_ink: string, accent_soft: string, accent_line: string, focus: string, rule: string}
     */
    public static function accentTokens(string $hex, string $mode, bool $useSiteColour = false): array
    {
        $mode = $mode === 'dark' ? 'dark' : 'light';
        $hex = self::normalizeHex($hex) ?? self::DEFAULT_SITE_COLOUR;
        $cacheKey = $hex.'|'.$mode.'|'.($useSiteColour ? 1 : 0);

        if (isset(self::$accentCache[$cacheKey])) {
            return self::$accentCache[$cacheKey];
        }

        ['surface' => $surface, 'canvas' => $canvas] = self::SURFACES[$mode];
        $tokens = null;

        // ONE colour engine: WP1's App\Support\Color\Contrast when it is installed.
        $engine = 'App\\Support\\Color\\Contrast';

        if (class_exists($engine) && method_exists($engine, 'adminAccent')) {
            try {
                $tokens = self::tokenArray($engine::adminAccent($hex, $mode, $surface, $canvas, $useSiteColour));
            } catch (Throwable) {
                $tokens = null;
            }
        }

        $tokens ??= self::adminAccent($hex, $mode, $surface, $canvas, $useSiteColour);

        return self::$accentCache[$cacheKey] = $tokens;
    }

    /**
     * The published site colour: the stored appearance primary colour (the base theme flavour, never the
     * daily-rotated one, which only lives in config('site.*') for the request).
     */
    public static function siteColour(): string
    {
        try {
            $appearance = Setting::getGroup('appearance');
        } catch (Throwable) {
            $appearance = [];
        }

        return self::normalizeHex((string) ($appearance['primary_color'] ?? ''))
            ?? self::normalizeHex((string) config('site.primary_color', ''))
            ?? self::DEFAULT_SITE_COLOUR;
    }

    /**
     * Accent tokens for both modes after the admin's "Admin colour" preference.
     *
     * @return array{light: array, dark: array, brand: string}
     */
    public static function accentFor(?User $user = null): array
    {
        $site = self::siteColour();
        $mode = AdminPreferences::get($user, 'accent_mode');
        [$input, $useSite] = match ($mode) {
            'site' => [$site, true],
            'neutral' => [self::NEUTRAL_ACCENT, false],
            'ink' => [self::WHITE, false],
            default => [$site, false],
        };

        return [
            'light' => self::accentTokens($input, 'light', $useSite),
            'dark' => self::accentTokens($input, 'dark', $useSite),
            'brand' => $site,
        ];
    }

    /**
     * The <style> that sets the accent variables. admin.scss maps --accent to --ac-l / --ac-d per mode
     * and falls back to the neutral blue, so the tag is empty for the most common sites.
     */
    public static function styleTag(?User $user = null): HtmlString
    {
        $accent = self::accentFor($user);
        $defaults = [
            'light' => self::accentTokens(self::NEUTRAL_ACCENT, 'light'),
            'dark' => self::accentTokens(self::NEUTRAL_ACCENT, 'dark'),
        ];
        $vars = ['--brand-dot:'.$accent['brand']];

        foreach (['light' => 'l', 'dark' => 'd'] as $mode => $suffix) {
            foreach (self::STYLE_VARS as $token => $name) {
                if ($accent[$mode][$token] !== $defaults[$mode][$token]) {
                    $vars[] = '--'.$name.'-'.$suffix.':'.$accent[$mode][$token];
                }
            }

            // rgb triplet of the link colour, for Bootstrap's rgba(var(--bs-primary-rgb), ...) utilities
            if ($accent[$mode]['accent_ink'] !== $defaults[$mode]['accent_ink']) {
                $vars[] = '--ar-'.$suffix.':'.implode(',', array_map('intval', self::rgb($accent[$mode]['accent_ink'])));
            }
        }

        return new HtmlString('<style id="ad-accent">:root{'.implode(';', $vars).'}</style>');
    }

    /** 'light', 'dark' or 'system' (no data-theme: the device decides). */
    public static function colorScheme(?User $user = null): string
    {
        // ?theme_mode=light|dark (the old admin's preview switch) shows one page in that mode without saving it.
        $preview = request()->query('theme_mode');

        if (is_string($preview) && in_array($preview, ['light', 'dark'], true)) {
            return $preview;
        }

        return (string) AdminPreferences::get($user, 'admin_theme', 'system');
    }

    /** 'rtl' for right-to-left admin languages (config/languages.php, or the site-wide RTL switch). */
    public static function direction(): string
    {
        $locale = str_replace('_', '-', (string) app()->getLocale());
        $base = strtolower(explode('-', $locale)[0]);
        $info = (array) (config('languages.'.app()->getLocale()) ?? config('languages.'.$base) ?? []);
        $rtl = array_key_exists('rtl', $info)
            ? filter_var($info['rtl'], FILTER_VALIDATE_BOOLEAN)
            : in_array($base, ['ar', 'fa', 'he', 'ur'], true);

        return $rtl || filter_var(config('site.rtl_support', false), FILTER_VALIDATE_BOOLEAN) ? 'rtl' : 'ltr';
    }

    /** An icon from the admin sprite (resources/admin/icons.svg); the page prints only the symbols it used. */
    public static function icon(string $name, string $class = ''): HtmlString
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?: 'dot';
        self::$usedIcons[$name] = true;

        return new HtmlString('<svg class="ad-i'.($class !== '' ? ' '.e($class) : '').'" aria-hidden="true" focusable="false"><use href="#i-'.$name.'"/></svg>');
    }

    /** The inline sprite with the symbols used so far (printed once, at the end of the page) plus the scripts' set. */
    public static function sprite(): HtmlString
    {
        $symbols = self::symbols();
        $names = array_unique(array_merge(self::BASE_ICONS, array_keys(self::$usedIcons)));
        self::$usedIcons = [];
        $out = '';

        foreach ($names as $name) {
            if (isset($symbols[$name])) {
                $out .= '<symbol id="i-'.$name.'" viewBox="0 0 24 24">'.$symbols[$name].'</symbol>';
            }
        }

        return new HtmlString('<svg xmlns="http://www.w3.org/2000/svg" class="ad-sprite" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">'.$out.'</svg>');
    }

    /** @return array<string, string> symbol name => inner SVG */
    public static function symbols(): array
    {
        if (self::$symbols !== null) {
            return self::$symbols;
        }

        $symbols = [];
        $file = resource_path('admin/icons.svg');
        $svg = is_file($file) ? (string) file_get_contents($file) : '';

        if (preg_match_all('#<symbol id="i-([a-z0-9-]+)"[^>]*>(.*?)</symbol>#s', $svg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [, $name, $body]) {
                $symbols[$name] = trim($body);
            }
        }

        return self::$symbols = $symbols;
    }

    public static function normalizeHex(string $hex): ?string
    {
        $hex = trim($hex);

        if (preg_match('/^#?([0-9a-f]{3})$/i', $hex, $m)) {
            $hex = '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#?[0-9a-f]{6}$/i', $hex) ? '#'.strtolower(ltrim($hex, '#')) : null;
    }

    /** WCAG 2.x contrast ratio of two opaque hex colours. */
    public static function contrast(string $fg, string $bg): float
    {
        $l1 = self::luminance($fg);
        $l2 = self::luminance($bg);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** OKLCH hue (degrees) and chroma of a hex colour. @return array{0: float, 1: float, 2: float} */
    public static function oklch(string $hex): array
    {
        [$l, $a, $b] = self::toOklab($hex);
        $h = fmod(rad2deg(atan2($b, $a)), 360.0);

        return [$l, hypot($a, $b), $h < 0 ? $h + 360.0 : $h];
    }

    private static function tokenArray(mixed $result): ?array
    {
        if (! is_array($result)) {
            return null;
        }

        $tokens = [];

        foreach (['accent', 'on_accent', 'accent_ink', 'accent_soft', 'accent_line', 'focus'] as $key) {
            $value = $result[$key] ?? $result[str_replace('_', '-', $key)] ?? $result[lcfirst(str_replace('_', '', ucwords($key, '_')))] ?? null;
            $value = is_string($value) ? self::normalizeHex($value) : null;

            if ($value === null) {
                return null;
            }

            $tokens[$key] = $value;
        }

        $tokens['rule'] = (string) ($result['rule'] ?? '');

        return $tokens;
    }

    // ------------------------------------------------------------------------------------------------
    // Port of contrast_engine.admin_accent + colorlib (spec/theme/scripts), used until WP1's
    // App\Support\Color\Contrast is installed. Same steps, same rounding (half to even).

    private static function adminAccent(string $primary, string $mode, string $surface, string $canvas, bool $useSiteColour): array
    {
        [, $chroma] = self::oklch($primary);

        if ($chroma < 0.04) {
            [$fill, $label] = $mode === 'light' ? [self::INK, self::WHITE] : ['#e9ebef', self::INK];
            $rule = 'A neutral site colour -> monochrome';
        } else {
            $source = $primary;
            $rule = 'C site colour';

            if (self::inRedZone($primary) && ! $useSiteColour) {
                $source = self::NEUTRAL_ACCENT;
                $rule = 'B red-zone site colour -> neutral blue (per-admin opt-in to use the site colour)';
            }

            [$fill, $label] = self::labelFill($source, self::INK, self::ADMIN_BUDGET);

            if (self::contrast($fill, $surface) < 3.0) {
                $fill = self::fitContrast($fill, [$surface], 3.0, $mode === 'light' ? 'darker' : 'lighter');
                $label = self::contrast(self::WHITE, $fill) >= self::contrast(self::INK, $fill) ? self::WHITE : self::INK;

                if (self::contrast($label, $fill) < 4.5) {
                    $fill = self::fillForText($fill, $label, 4.5);
                }

                $rule .= ' + surface 3:1 nudge';
            }
        }

        $inkSource = $chroma >= 0.04 ? $fill : ($mode === 'light' ? self::INK : '#e9ebef');
        $ink = self::fitContrast($inkSource, [$surface, $canvas], 4.5, $mode === 'dark' ? 'lighter' : 'darker');

        return [
            'accent' => $fill,
            'on_accent' => $label,
            'accent_ink' => $ink,
            'accent_soft' => self::mix($surface, $fill, $mode === 'light' ? 0.10 : 0.16),
            'accent_line' => self::mix($surface, $fill, 0.45),
            'focus' => $ink,
            'rule' => $rule,
        ];
    }

    private static function inRedZone(string $hex): bool
    {
        [, $chroma, $hue] = self::oklch($hex);

        if ($chroma < 0.04) {
            return false;
        }

        if ($hue >= self::RED_ZONE[0] || $hue <= self::RED_ZONE_EDGE) {
            return true;
        }

        return $hue <= self::RED_ZONE[1] && $chroma >= self::RED_ZONE_EDGE_MIN_CHROMA;
    }

    /** label_fill(fill, prefer='light', ink): [fill, label] - white label kept when the fill moves <= budget. */
    private static function labelFill(string $fill, string $ink, float $budget, float $target = 4.5): array
    {
        [$preferred, $alternative] = [self::WHITE, $ink];

        if (self::contrast($preferred, $fill) >= $target) {
            return [$fill, $preferred];
        }

        $candidate = self::fillForText($fill, $preferred, $target);
        $moved = abs(self::oklch($fill)[0] - self::oklch($candidate)[0]);

        if ($moved <= $budget && self::contrast($preferred, $candidate) >= $target) {
            return [$candidate, $preferred];
        }

        if (self::contrast($alternative, $fill) >= $target) {
            return [$fill, $alternative];
        }

        $flipped = self::fillForText($fill, $alternative, $target);

        if (abs(self::oklch($fill)[0] - self::oklch($flipped)[0]) < $moved) {
            return [$flipped, $alternative];
        }

        return [$candidate, $preferred];
    }

    private static function fillForText(string $fill, string $text, float $target): string
    {
        if (self::contrast($text, $fill) >= $target) {
            return $fill;
        }

        return self::fitContrast($fill, [$text], $target, self::luminance($text) > 0.5 ? 'darker' : 'lighter');
    }

    /** Smallest OKLCH lightness change (hue and chroma kept, chroma reduced into sRGB) reaching the target. */
    private static function fitContrast(string $color, array $against, float $target, string $direction): string
    {
        $passes = function (string $candidate) use ($against, $target): bool {
            foreach ($against as $background) {
                if (self::contrast($candidate, $background) < $target) {
                    return false;
                }
            }

            return true;
        };

        if ($passes($color)) {
            return $color;
        }

        [$lightness, $chroma, $hue] = self::oklch($color);
        $options = [];

        foreach (['lighter' => 1, 'darker' => -1] as $name => $sign) {
            if ($direction !== 'auto' && $direction !== $name) {
                continue;
            }

            $range = $sign > 0 ? 1.0 - $lightness : $lightness;

            for ($i = 1; $i <= 200; $i++) {
                $delta = $range * $i / 200;
                $candidate = self::fromOklch($lightness + $sign * $delta, $chroma, $hue);

                if ($passes($candidate)) {
                    $options[] = [$delta, $candidate];
                    break;
                }
            }
        }

        if ($options === []) {
            $worst = fn (string $c) => min(array_map(fn (string $bg) => self::contrast($c, $bg), $against));

            return $worst('#ffffff') > $worst('#000000') ? '#ffffff' : '#000000';
        }

        usort($options, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $options[0][1];
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);

        return self::toHex([
            $x[0] + ($y[0] - $x[0]) * $t,
            $x[1] + ($y[1] - $x[1]) * $t,
            $x[2] + ($y[2] - $x[2]) * $t,
        ]);
    }

    private static function luminance(string $hex): float
    {
        $lin = fn (float $c) => ($c /= 255.0) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function toOklab(string $hex): array
    {
        $lin = fn (float $v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        [$r, $g, $b] = array_map(fn (float $c) => $lin($c / 255), self::rgb($hex));

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = array_map(fn (float $x) => ($x < 0 ? -1 : 1) * abs($x) ** (1 / 3), [$l, $m, $s]);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    private static function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        $lightness = max(0.0, min(1.0, $lightness));
        $radians = deg2rad($hue);
        $toLinear = function (float $c) use ($lightness, $radians): array {
            $a = $c * cos($radians);
            $b = $c * sin($radians);
            $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
            $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
            $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

            return [
                4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
                -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
                -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
            ];
        };

        for ($i = 0; $i < 40; $i++) {
            $linear = $toLinear($chroma);

            if (min($linear) >= -1e-4 && max($linear) <= 1 + 1e-4) {
                break;
            }

            $chroma *= 0.93;
        }

        $toSrgb = function (float $v): float {
            $v = max(0.0, min(1.0, $v));

            return $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
        };

        return self::toHex(array_map(fn (float $v) => $toSrgb($v) * 255, $toLinear($chroma)));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalizeHex($hex) ?? '#000000', '#');

        return [(float) hexdec(substr($hex, 0, 2)), (float) hexdec(substr($hex, 2, 2)), (float) hexdec(substr($hex, 4, 2))];
    }

    private static function toHex(array $rgb): string
    {
        return '#'.implode('', array_map(
            fn (float $v) => sprintf('%02x', max(0, min(255, (int) round($v, 0, PHP_ROUND_HALF_EVEN)))),
            array_slice($rgb, 0, 3),
        ));
    }
}
