<?php

namespace App\Support\Color;

use InvalidArgumentException;

/**
 * Colour maths for the theme engine: parsing, sRGB <-> OKLab / OKLCH, alpha compositing and
 * WCAG relative luminance. A line-for-line port of the redesign's colorlib.py, which produced
 * the golden vectors (tests/Unit/ColorContrastGoldenTest.php): the operation order follows the
 * Python code and rounding is Python's round-half-even, so both give the same hex values.
 */
final class Oklch
{
    private const HEX = '/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    private const RGBA = '/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/';

    /**
     * [r, g, b, a] with r, g, b in 0..255 and a in 0..1 ('#rgb', '#rrggbb', 'rgb()' or 'rgba()').
     *
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: float}
     */
    public static function parse(string $color): array
    {
        $c = trim($color);

        if (preg_match(self::HEX, $c, $m)) {
            $h = $m[1];

            if (strlen($h) === 3) {
                $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
            }

            return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), 1.0];
        }

        if (preg_match(self::RGBA, $c, $m)) {
            $alpha = isset($m[4]) && $m[4] !== '' ? (float) $m[4] : 1.0;

            return [(float) $m[1], (float) $m[2], (float) $m[3], $alpha];
        }

        throw new InvalidArgumentException("Unparseable colour '{$color}'.");
    }

    public static function isColor(string $color): bool
    {
        $c = trim($color);

        return preg_match(self::HEX, $c) === 1 || preg_match(self::RGBA, $c) === 1;
    }

    /** '#rrggbb' from [r, g, b(, a)] channels (Python round-half-even, clamped). */
    public static function toHex(array $rgb): string
    {
        $out = '#';

        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', max(0, min(255, self::round($rgb[$i]))));
        }

        return $out;
    }

    /** Any parseable colour as opaque '#rrggbb' (alpha dropped). */
    public static function hex(string $color): string
    {
        return self::toHex(self::parse($color));
    }

    /** 'r,g,b' (the --*-rgb token format). */
    public static function rgbTriplet(string $color): string
    {
        [$r, $g, $b] = self::parse($color);

        return self::round($r).','.self::round($g).','.self::round($b);
    }

    public static function rgba(string $color, float $alpha): string
    {
        [$r, $g, $b] = self::parse($color);

        return 'rgba('.self::round($r).','.self::round($g).','.self::round($b).','.self::number($alpha).')';
    }

    /** `top` (may carry alpha) composited over an opaque `bottom`; opaque hex. */
    public static function over(string $top, string $bottom): string
    {
        [$tr, $tg, $tb, $ta] = self::parse($top);
        [$br, $bg, $bb] = self::parse($bottom);

        return self::toHex([$tr * $ta + $br * (1 - $ta), $tg * $ta + $bg * (1 - $ta), $tb * $ta + $bb * (1 - $ta)]);
    }

    /** Linear sRGB-space mix: t = 0 gives a, t = 1 gives b (opaque). */
    public static function mix(string $a, string $b, float $t): string
    {
        [$ar, $ag, $ab] = self::parse($a);
        [$br, $bg, $bb] = self::parse($b);

        return self::toHex([$ar + ($br - $ar) * $t, $ag + ($bg - $ag) * $t, $ab + ($bb - $ab) * $t]);
    }

    /** WCAG 2.x relative luminance. */
    public static function relativeLuminance(string $color): float
    {
        [$r, $g, $b] = self::parse($color);

        return 0.2126 * self::channel($r) + 0.7152 * self::channel($g) + 0.0722 * self::channel($b);
    }

    /** @return array{0: float, 1: float, 2: float} OKLab [L, a, b] */
    public static function toOklab(string $color): array
    {
        [$r, $g, $b] = self::parse($color);
        $r = self::srgbToLinear($r / 255);
        $g = self::srgbToLinear($g / 255);
        $b = self::srgbToLinear($b / 255);
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    /** @return array{0: float, 1: float, 2: float} OKLCH [L 0..1, C, h 0..360) */
    public static function toOklch(string $color): array
    {
        [$l, $a, $b] = self::toOklab($color);
        $h = fmod((180.0 / M_PI) * atan2($b, $a), 360.0);

        if ($h < 0) {
            $h += 360.0;
        }

        return [$l, hypot($a, $b), $h];
    }

    /** OKLCH to hex, reducing the chroma (hue and lightness kept) until the colour fits sRGB. */
    public static function fromOklch(float $l, float $c, float $h): string
    {
        $l = max(0.0, min(1.0, $l));
        $hr = (M_PI / 180.0) * $h;

        for ($i = 0; $i < 40; $i++) {
            $lin = self::oklabToLinear($l, $c * cos($hr), $c * sin($hr));

            if (self::inGamut($lin)) {
                break;
            }

            $c *= 0.93;
        }

        $lin = self::oklabToLinear($l, $c * cos($hr), $c * sin($hr));

        return self::toHex(array_map(fn (float $v): float => self::linearToSrgb($v) * 255, $lin));
    }

    /** OKLCH as a CSS string, e.g. 'oklch(62.1% 0.240 12)'. */
    public static function toCss(string $color): string
    {
        [$l, $c, $h] = self::toOklch($color);

        return sprintf('oklch(%.1f%% %.3f %.0f)', $l * 100, $c, $h);
    }

    /** Euclidean distance in OKLab. */
    public static function deltaE(string $a, string $b): float
    {
        [$la, $aa, $ba] = self::toOklab($a);
        [$lb, $ab, $bb] = self::toOklab($b);

        return sqrt(($la - $lb) ** 2 + ($aa - $ab) ** 2 + ($ba - $bb) ** 2);
    }

    /**
     * Python's round() for one channel: half to even on the exact binary value. PHP's round() pre-rounds
     * (220.50000000000003 becomes 220.5, then 220), which put two reference tokens one step off.
     */
    public static function round(float|int $value): int
    {
        $floor = floor($value);
        $fraction = $value - $floor;

        if ($fraction > 0.5 || ($fraction === 0.5 && fmod($floor, 2.0) !== 0.0)) {
            return (int) $floor + 1;
        }

        return (int) $floor;
    }

    /** Python's '{:g}' for the alpha and opacity numbers in generated CSS (0.14, 0.1, 1). */
    public static function number(float|int $value): string
    {
        return (float) $value === 0.0 ? '0' : sprintf('%.6g', $value);
    }

    private static function channel(float|int $c): float
    {
        $c = $c / 255.0;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function srgbToLinear(float $v): float
    {
        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    private static function linearToSrgb(float $v): float
    {
        $v = max(0.0, min(1.0, $v));

        return $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
    }

    private static function cbrt(float $x): float
    {
        $root = abs($x) ** (1 / 3);

        return $x < 0 ? -$root : $root;
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB */
    private static function oklabToLinear(float $l, float $a, float $b): array
    {
        $l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;
        $l3 = $l_ ** 3;
        $m3 = $m_ ** 3;
        $s3 = $s_ ** 3;

        return [
            4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3,
            -1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3,
            -0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.7076147010 * $s3,
        ];
    }

    private static function inGamut(array $lin, float $eps = 1e-4): bool
    {
        foreach ($lin as $v) {
            if ($v < -$eps || $v > 1 + $eps) {
                return false;
            }
        }

        return true;
    }
}
