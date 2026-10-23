<?php

namespace App\Support\Color;

/**
 * The ONE colour engine of the product (redesign critique M10). The theme token build
 * (App\Support\ThemeTokens, `php artisan avd:themes:build`), the owner's custom colours and the
 * admin accent all call these rules, so the same input gives the same fill and label everywhere.
 * Port of the redesign's contrast_engine.py + colorlib.fit_contrast; golden vectors in
 * tests/Unit/ColorContrastGoldenTest.php.
 *
 * - labelFill(): the button / badge / active-chip pair. Keeps the preferred label by moving the
 *   fill's OKLCH lightness (hue kept, chroma only reduced to stay in sRGB) by at most the budget
 *   (#ff0055 + white -> #ea094e, 4.52:1), else flips the label, else takes the smaller change.
 * - ink(): brand-coloured TEXT, lightness moved until it reaches 4.5:1 on every background.
 * - status(): success / warning / danger / info as text inks plus fills with their labels.
 * - adminAccent(): the admin's single accent (neutral blue for red-zone site colours).
 */
final class Contrast
{
    /** Max OKLCH lightness change that keeps the preferred label (theme catalogue + custom colours). */
    public const BUDGET = 0.10;

    /** The admin accent may move a little further (a UI colour, not the brand). */
    public const ADMIN_BUDGET = 0.16;

    public const WHITE = '#ffffff';

    public const INK = '#0b0b0f';

    /** OKLCH hue range that reads as 'danger' next to Bootstrap / admin red. */
    public const RED_ZONE = [330.0, 25.0];

    public const NEUTRAL_ACCENT = '#1f5bc7';

    private const ADMIN_INK = '#16191e';

    private const STATUS_BASE = [
        'dark' => ['success' => '#22c55e', 'warning' => '#f59e0b', 'danger' => '#f43f5e', 'info' => '#38bdf8'],
        'light' => ['success' => '#15803d', 'warning' => '#a15c00', 'danger' => '#c81e3a', 'info' => '#0369a1'],
    ];

    /** WCAG 2.x contrast ratio; fg may carry alpha (composited over the opaque bg). */
    public static function contrast(string $fg, string $bg): float
    {
        if (Oklch::parse($fg)[3] < 1) {
            $fg = Oklch::over($fg, $bg);
        }

        $l1 = Oklch::relativeLuminance($fg);
        $l2 = Oklch::relativeLuminance($bg);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** @return array{0: float, 1: float, 2: float} [L, C, h] */
    public static function toOklch(string $hex): array
    {
        return Oklch::toOklch($hex);
    }

    public static function fromOklch(float $l, float $c, float $h): string
    {
        return Oklch::fromOklch($l, $c, $h);
    }

    /**
     * The smallest OKLCH lightness change of `color` that reaches `target` against every background.
     * direction: 'lighter', 'darker' or 'auto' (the side that needs the smaller change and can reach it).
     * The colour comes back unchanged when it already passes; black or white when no lightness can.
     */
    public static function fitLightness(string $color, array|string $backgrounds, float $target, string $direction = 'auto', bool $keepChroma = true): string
    {
        $against = (array) $backgrounds;

        if (self::passes($color, $against, $target)) {
            return $color;
        }

        [$l, $c, $h] = Oklch::toOklch($color);
        $options = [];

        foreach ([1 => 'lighter', -1 => 'darker'] as $sign => $side) {
            if ($direction !== 'auto' && $direction !== $side) {
                continue;
            }

            $limit = $sign > 0 ? 1.0 - $l : $l;
            $steps = 200;

            for ($i = 1; $i <= $steps; $i++) {
                $d = $limit * $i / $steps;
                $candidate = Oklch::fromOklch($l + $sign * $d, $keepChroma ? $c : $c * (1 - $i / $steps * 0.5), $h);

                if (self::passes($candidate, $against, $target)) {
                    $options[] = [$d, $candidate];
                    break;
                }
            }
        }

        if ($options === []) {
            $black = self::worst('#000000', $against);
            $white = self::worst('#ffffff', $against);

            return $white > $black ? '#ffffff' : '#000000';
        }

        // Stable: on a tie the lighter option (found first) wins, as in the reference sort.
        usort($options, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $options[0][1];
    }

    /** Light or dark text for a fill, whichever contrasts more. */
    public static function bestTextOn(string $fill, string $light = self::WHITE, string $dark = self::INK): string
    {
        return self::contrast($light, $fill) >= self::contrast($dark, $fill) ? $light : $dark;
    }

    /** A fill (lightness only) adjusted until `text` reads at `target` on it. */
    public static function fillForText(string $fill, string $text, float $target = 4.5): string
    {
        if (self::contrast($text, $fill) >= $target) {
            return $fill;
        }

        return self::fitLightness($fill, [$text], $target, Oklch::relativeLuminance($text) > 0.5 ? 'darker' : 'lighter');
    }

    /**
     * The fill / label pair for buttons, badges and active chips.
     * prefer: 'light' (white label), 'dark' (ink label) or 'auto' (the label that already contrasts more).
     *
     * @return array{0: string, 1: string, 2: string} [fill, label, rule]
     */
    public static function labelFill(string $fill, string $prefer = 'auto', string $ink = self::INK, float $budget = self::BUDGET, float $target = 4.5): array
    {
        $fill = Oklch::hex($fill);
        $light = self::WHITE;
        $dark = $ink;

        if ($prefer === 'light') {
            [$pref, $alt] = [$light, $dark];
        } elseif ($prefer === 'dark') {
            [$pref, $alt] = [$dark, $light];
        } else {
            [$pref, $alt] = self::contrast($light, $fill) >= self::contrast($dark, $fill) ? [$light, $dark] : [$dark, $light];
        }

        if (self::contrast($pref, $fill) >= $target) {
            return [$fill, $pref, 'kept'];
        }

        $candidate = self::fillForText($fill, $pref, $target);
        $d1 = abs(self::lightness($fill) - self::lightness($candidate));

        if ($d1 <= $budget && self::contrast($pref, $candidate) >= $target) {
            return [$candidate, $pref, sprintf('fill moved %.3f L for the preferred label', $d1)];
        }

        if (self::contrast($alt, $fill) >= $target) {
            return [$fill, $alt, 'label flipped (fill kept)'];
        }

        $candidate2 = self::fillForText($fill, $alt, $target);
        $d2 = abs(self::lightness($fill) - self::lightness($candidate2));

        if ($d2 < $d1) {
            return [$candidate2, $alt, sprintf('label flipped, fill moved %.3f L', $d2)];
        }

        return [$candidate, $pref, sprintf('fill moved %.3f L (over budget, smallest change)', $d1)];
    }

    /** Brand-coloured text: lighter in dark mode, darker in light mode, until >= target on every background. */
    public static function ink(string $color, array $backgrounds, string $mode, float $target = 4.5): string
    {
        return self::fitLightness($color, $backgrounds, $target, $mode === 'dark' ? 'lighter' : 'darker');
    }

    /**
     * Status colours fitted to a palette (critique M2): '<kind>' is the text ink (>= 4.5:1 on every background),
     * '<kind>-fill' the badge / button fill with '<kind>-on' as its label (>= 4.5:1).
     *
     * @return array<string, string>
     */
    public static function status(string $mode, array $backgrounds, string $inkDark = self::INK): array
    {
        $out = [];

        foreach (self::STATUS_BASE[$mode === 'light' ? 'light' : 'dark'] as $kind => $base) {
            $out[$kind] = self::ink($base, $backgrounds, $mode);
            [$fill, $label] = self::labelFill($base, in_array($kind, ['success', 'danger', 'info'], true) ? 'light' : 'dark', $inkDark);
            $out[$kind.'-fill'] = $fill;
            $out[$kind.'-on'] = $label;
        }

        return $out;
    }

    public static function hueInRedZone(string $color): bool
    {
        [, $c, $h] = Oklch::toOklch(Oklch::hex($color));

        if ($c < 0.04) {
            return false;
        }

        [$lo, $hi] = self::RED_ZONE;

        return $h >= $lo || $h <= $hi;
    }

    /**
     * The admin's single accent for one mode (AdminTheme::accentTokens).
     * A. neutral site colour (OKLCH chroma < 0.04): monochrome accent.
     * B. site hue in the red zone and no opt-in to 'use my site colour': neutral blue #1f5bc7, so primary
     *    actions never look like Delete.
     * C. otherwise the site colour: labelFill(prefer white, budget 0.16), then nudged until the fill has 3:1
     *    against the admin surface (WCAG 1.4.11) with the label re-checked.
     *
     * @return array{accent: string, on_accent: string, accent_ink: string, accent_soft: string, accent_line: string, focus: string, rule: string, checks: array<string, float>}
     */
    public static function adminAccent(string $primary, string $mode, string $surface, string $canvas, bool $useSiteColour = false): array
    {
        $p = Oklch::hex($primary);
        [, $chroma] = Oklch::toOklch($p);

        if ($chroma < 0.04) {
            [$fill, $label] = $mode === 'light' ? [self::ADMIN_INK, self::WHITE] : ['#e9ebef', self::ADMIN_INK];
            $rule = 'A neutral site colour -> monochrome';
        } else {
            $source = $p;
            $rule = 'C site colour';

            if (self::hueInRedZone($p) && ! $useSiteColour) {
                $source = self::NEUTRAL_ACCENT;
                $rule = 'B red-zone site colour -> neutral blue (per-admin opt-in to use the site colour)';
            }

            [$fill, $label] = self::labelFill($source, 'light', self::ADMIN_INK, self::ADMIN_BUDGET);

            if (self::contrast($fill, $surface) < 3.0) {
                $fill = self::fitLightness($fill, [$surface], 3.0, $mode === 'light' ? 'darker' : 'lighter');
                $label = self::contrast(self::WHITE, $fill) >= self::contrast(self::ADMIN_INK, $fill) ? self::WHITE : self::ADMIN_INK;

                if (self::contrast($label, $fill) < 4.5) {
                    $fill = self::fillForText($fill, $label, 4.5);
                }

                $rule .= ' + surface 3:1 nudge';
            }
        }

        $inkSource = $chroma >= 0.04 ? $fill : ($mode === 'light' ? self::ADMIN_INK : '#e9ebef');
        $accentInk = self::ink($inkSource, [$surface, $canvas], $mode);

        return [
            'accent' => $fill,
            'on_accent' => $label,
            'accent_ink' => $accentInk,
            'accent_soft' => Oklch::mix($surface, $fill, $mode === 'light' ? 0.10 : 0.16),
            'accent_line' => Oklch::mix($surface, $fill, 0.45),
            'focus' => $accentInk,
            'rule' => $rule,
            'checks' => [
                'on_accent_vs_accent' => round(self::contrast($label, $fill), 2),
                'accent_vs_surface_non_text' => round(self::contrast($fill, $surface), 2),
                'accent_ink_vs_surface' => round(self::contrast($accentInk, $surface), 2),
                'accent_ink_vs_canvas' => round(self::contrast($accentInk, $canvas), 2),
            ],
        ];
    }

    /** OKLCH lightness of any parseable colour. */
    public static function lightness(string $color): float
    {
        return Oklch::toOklch(Oklch::hex($color))[0];
    }

    private static function passes(string $color, array $against, float $target): bool
    {
        foreach ($against as $bg) {
            if (self::contrast($color, $bg) < $target) {
                return false;
            }
        }

        return true;
    }

    private static function worst(string $color, array $against): float
    {
        return min(array_map(fn (string $bg): float => self::contrast($color, $bg), $against));
    }
}
