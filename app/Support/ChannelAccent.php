<?php

namespace App\Support;

/**
 * The creator's channel accent inside the site's look (member design 6.19, QA channel-11).
 *
 * The accent only marks things (the tab underline, the statistics marks, the avatar ring and the band outline); it is
 * never body text. "look" (the default since 1.2.3) means no colour of its own: the page keeps the look's brand ink
 * (--avd-brand-ink), in both modes. A preset or a custom colour is adjusted once per mode so it keeps 4.5:1 against
 * the surfaces of that mode (lighter in dark mode, darker in light mode), so a pale accent never disappears on a light
 * look and a dark one never sinks into a dark look. The result is printed as two custom properties on the channel root;
 * the member CSS (resources/sass/member/_channel.scss) picks the one of the current mode.
 */
final class ChannelAccent
{
    /** The accent value that follows the look. */
    public const LOOK = 'look';

    /** The minimum contrast of an accent mark against the surfaces of its mode. */
    public const MIN_CONTRAST = 4.5;

    /**
     * The preset accents (ChannelCustomizationSettings::options()['accent'] keys), as the creator sees them.
     *
     * @var array<string, string>
     */
    public const PRESETS = [
        'coral' => '#ff6177',
        'cyan' => '#38d7e8',
        'violet' => '#a78bfa',
        'amber' => '#fbbf24',
        'emerald' => '#34d399',
        'rose' => '#fb7185',
    ];

    /**
     * The lightest dark surface and the darkest light surface the 32 themes use for panels and raised areas (the
     * surfaces a mark of the channel page can sit on): an accent that keeps 4.5:1 on these keeps it on every darker
     * (dark mode) or lighter (light mode) surface.
     */
    private const DARK_SURFACE = '#2c3140';

    private const LIGHT_SURFACE = '#e6e8ee';

    /**
     * The accent's colour before any adjustment, or null when the channel follows the look.
     */
    public static function base(string $accent, ?string $custom = null): ?string
    {
        if ($accent === 'custom') {
            return ChannelCustomizationSettings::sanitizeColor((string) $custom);
        }

        return self::PRESETS[$accent] ?? null;
    }

    /**
     * The accent's ink in each mode, or null when the channel follows the look (or the accent is unknown).
     *
     * @return array{dark: string, light: string}|null
     */
    public static function inks(string $accent, ?string $custom = null): ?array
    {
        $base = self::base($accent, $custom);

        if ($base === null) {
            return null;
        }

        return [
            'dark' => self::ink($base, 'dark'),
            'light' => self::ink($base, 'light'),
        ];
    }

    /**
     * The inline style of the channel root: the two mode inks as custom properties ('' when it follows the look).
     */
    public static function style(string $accent, ?string $custom = null): string
    {
        $inks = self::inks($accent, $custom);

        return $inks === null ? '' : '--mk-accent-dark:' . $inks['dark'] . ';--mk-accent-light:' . $inks['light'] . ';';
    }

    /**
     * $hex adjusted for $mode ('dark' or 'light'): unchanged when it already keeps MIN_CONTRAST against that mode's
     * surface, else lightened (dark mode) or darkened (light mode) in small steps of HSL lightness until it does. The
     * hue and the saturation are kept, so a coral stays a coral.
     */
    public static function ink(string $hex, string $mode): string
    {
        $hex = self::normalize($hex);
        $surface = $mode === 'light' ? self::LIGHT_SURFACE : self::DARK_SURFACE;

        if (self::contrast($hex, $surface) >= self::MIN_CONTRAST) {
            return $hex;
        }

        [$h, $s, $l] = self::toHsl($hex);
        $step = $mode === 'light' ? -0.01 : 0.01;

        for ($i = 0; $i < 100; $i++) {
            $l = max(0.0, min(1.0, $l + $step));
            $candidate = self::fromHsl($h, $s, $l);

            if (self::contrast($candidate, $surface) >= self::MIN_CONTRAST || $l <= 0.0 || $l >= 1.0) {
                return $candidate;
            }
        }

        return $mode === 'light' ? '#000000' : '#ffffff';
    }

    /**
     * WCAG 2 contrast ratio of two #rrggbb colours.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance(self::normalize($a));
        $lb = self::luminance(self::normalize($b));
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function normalize(string $hex): string
    {
        $hex = strtolower(trim($hex));

        return preg_match('/^#[0-9a-f]{6}$/', $hex) ? $hex : '#18c8d8';
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue (0-360), saturation and lightness (0-1)
     */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(static fn (string $pair): float => hexdec($pair) / 255, str_split(substr($hex, 1), 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
