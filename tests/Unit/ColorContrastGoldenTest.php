<?php

namespace Tests\Unit;

use App\Support\Color\Contrast;
use App\Support\Color\Oklch;
use App\Support\ThemeTokens;
use Tests\TestCase;

/**
 * The ONE colour engine (App\Support\Color) against the redesign's golden vectors
 * (tests/Fixtures/color_engine_golden.json = spec/data/color_engine_golden.json, produced by the reference
 * contrast_engine.py + colorlib.py + build_spec.derive): 42 label cases, 20 admin accent cases (both modes)
 * and 348 theme x mode x flavor vectors. Tolerance: +-1 per sRGB channel, and every WCAG target still holds.
 */
class ColorContrastGoldenTest extends TestCase
{
    private static ?array $golden = null;

    private static function golden(): array
    {
        return self::$golden ??= json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/color_engine_golden.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_fixture_has_every_golden_case(): void
    {
        $golden = self::golden();

        $this->assertSame(['theme_vectors' => 348, 'label_cases' => 42, 'admin_cases' => 20], $golden['counts']);
        $this->assertSame(Contrast::BUDGET, $golden['budget_preferred_label_L']);
        $this->assertSame(Contrast::ADMIN_BUDGET, $golden['admin_budget_L']);
        $this->assertSame(Contrast::RED_ZONE, $golden['red_zone_oklch_hue']);
        $this->assertSame(Contrast::NEUTRAL_ACCENT, $golden['neutral_accent']);
    }

    public function test_engine_basics(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::contrast('#ffffff', '#000000'), 1e-9);
        $this->assertEqualsWithDelta(1.0, Contrast::contrast('#777777', '#777777'), 1e-9);
        // Alpha foregrounds are composited over the background first.
        $this->assertEqualsWithDelta(Contrast::contrast('#808080', '#ffffff'), Contrast::contrast('rgba(0,0,0,0.498)', '#ffffff'), 0.05);

        // A colour that already passes comes back untouched; the search keeps the hue.
        $this->assertSame('#000000', Contrast::fitLightness('#000000', ['#ffffff'], 4.5));
        $fitted = Contrast::fitLightness('#ff0055', ['#ffffff'], 4.5, 'darker');
        $this->assertGreaterThanOrEqual(4.5, Contrast::contrast($fitted, '#ffffff'));
        $this->assertEqualsWithDelta(Oklch::toOklch('#ff0055')[2], Oklch::toOklch($fitted)[2], 2.0);

        // OKLCH round trip within one channel step.
        foreach (['#ff0055', '#0b0e15', '#00dfd8', '#d4af37', '#7928ca', '#f5f6fa'] as $hex) {
            [$l, $c, $h] = Contrast::toOklch($hex);
            $this->assertColourNear($hex, Contrast::fromOklch($l, $c, $h), "OKLCH round trip of {$hex}");
        }

        // Python's round(): half to even on the exact binary value (PHP's round() pre-rounds 220.50000000000003).
        $this->assertSame(220, Oklch::round(220.5));
        $this->assertSame(221, Oklch::round(220.50000000000003));
        $this->assertSame(222, Oklch::round(221.5));
        $this->assertSame('0.14', Oklch::number(0.14));
        $this->assertSame('rgba(255,0,85,0.1)', Oklch::rgba('#ff0055', 0.10));
    }

    public function test_demo1_hot_pink_keeps_its_white_labels(): void
    {
        // Owner decision D1 / critique B2: #ff0055 + white -> #ea094e at 4.52:1, the label stays white.
        [$fill, $label, $rule] = Contrast::labelFill('#ff0055', 'light');

        $this->assertSame('#ea094e', $fill);
        $this->assertSame('#ffffff', $label);
        $this->assertStringStartsWith('fill moved 0.038 L', $rule);
        $this->assertGreaterThanOrEqual(4.5, Contrast::contrast($label, $fill));
    }

    public function test_label_fill_matches_the_golden_cases(): void
    {
        $failures = [];

        foreach (self::golden()['label_fill_cases'] as $case) {
            [$fill, $label, $rule] = Contrast::labelFill($case['input'], $case['prefer']);
            $where = "{$case['input']} prefer {$case['prefer']}";

            if (! $this->near($fill, $case['fill']) || $label !== $case['label'] || $rule !== $case['rule']) {
                $failures[] = "{$where}: got {$fill} {$label} '{$rule}', want {$case['fill']} {$case['label']} '{$case['rule']}'";
            }

            if (Contrast::contrast($label, $fill) < min(4.5, $case['ratio']) - 0.01) {
                $failures[] = "{$where}: label ratio ".round(Contrast::contrast($label, $fill), 2)." below {$case['ratio']}";
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_admin_accent_matches_the_golden_cases(): void
    {
        $failures = [];
        $surfaces = ['light' => ['#ffffff', '#f4f5f7'], 'dark' => ['#1a1d23', '#121419']];

        foreach (self::golden()['admin_accent_cases'] as $case) {
            foreach ($surfaces as $mode => [$surface, $canvas]) {
                $got = Contrast::adminAccent($case['input'], $mode, $surface, $canvas, $case['use_site_colour']);
                $want = $case[$mode];
                $where = "{$case['name']} ({$case['input']}) {$mode} site=".($case['use_site_colour'] ? 'yes' : 'no');

                foreach (['accent', 'on_accent', 'accent_ink', 'accent_soft', 'accent_line', 'focus'] as $key) {
                    if (! $this->near($got[$key], $want[$key])) {
                        $failures[] = "{$where}: {$key} {$got[$key]}, want {$want[$key]}";
                    }
                }

                if ($got['rule'] !== $want['rule']) {
                    $failures[] = "{$where}: rule '{$got['rule']}', want '{$want['rule']}'";
                }

                // The targets every admin accent must meet, whatever the site colour.
                if (Contrast::contrast($got['on_accent'], $got['accent']) < 4.5
                    || Contrast::contrast($got['accent'], $surface) < 3.0
                    || Contrast::contrast($got['accent_ink'], $surface) < 4.5
                    || Contrast::contrast($got['accent_ink'], $canvas) < 4.5) {
                    $failures[] = "{$where}: a WCAG target is not met ".json_encode($got['checks']);
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_theme_vectors_match_the_golden_outputs(): void
    {
        $failures = [];
        $vectors = self::golden()['theme_vectors'];

        foreach ($vectors as $vector) {
            $theme = ThemeTokens::theme($vector['theme']);
            $where = "{$vector['theme']}/{$vector['flavor']}/{$vector['mode']}";

            if ($theme === null) {
                $failures[] = "{$where}: theme missing from the catalogue";

                continue;
            }

            // The reference derivation: the page-backdrop fit (Gate A) is checked in ThemeTokensBuildTest.
            $tokens = ThemeTokens::derive($theme, $vector['mode'], ThemeTokens::flavor($theme, $vector['flavor']), pageFit: false);

            foreach ($vector['outputs'] as $name => $want) {
                $got = $tokens[$name] ?? null;

                if ($got === null || ! $this->near($got, $want)) {
                    $failures[] = "{$where} {$name}: ".json_encode($got).", want {$want}";
                }
            }

            // Every listed pair still reaches its target with the PHP values.
            $bg = $tokens['--avd-bg'];
            $s1 = Oklch::over($tokens['--avd-surface-1'], $bg);
            $s2 = Oklch::over($tokens['--avd-surface-2'], $bg);
            $pairs = [
                ['on-brand/brand-fill', $tokens['--avd-on-brand'], $tokens['--avd-brand-fill'], 4.5],
                ['on-accent/accent-fill', $tokens['--avd-on-accent'], $tokens['--avd-accent-fill'], 4.5],
                ['focus/bg', $tokens['--avd-focus'], $bg, 3.0],
                ['border-input/bg', $tokens['--avd-border-input'], $bg, 3.0],
            ];

            foreach (['bg' => $bg, 'surface-1' => $s1, 'surface-2' => $s2] as $surfaceName => $surface) {
                $pairs[] = ["brand-ink/{$surfaceName}", $tokens['--avd-brand-ink'], $surface, 4.5];
                $pairs[] = ["muted/{$surfaceName}", $tokens['--avd-muted'], $surface, 4.5];
                $pairs[] = ["bs-primary/{$surfaceName}", $tokens['--bs-primary'], $surface, 4.5];

                foreach (['success', 'warning', 'danger', 'info'] as $kind) {
                    $pairs[] = ["{$kind}/{$surfaceName}", $tokens["--avd-{$kind}"], $surface, 4.5];
                }
            }

            foreach (['success', 'warning', 'danger', 'info'] as $kind) {
                $pairs[] = ["on-{$kind}/{$kind}-fill", $tokens["--avd-on-{$kind}"], $tokens["--avd-{$kind}-fill"], 4.5];
            }

            foreach ($pairs as [$check, $fg, $against, $target]) {
                if (Contrast::contrast($fg, $against) < $target - 1e-6) {
                    $failures[] = "{$where} {$check}: ".round(Contrast::contrast($fg, $against), 2)." < {$target}";
                }
            }
        }

        $this->assertCount(348, $vectors);
        $this->assertSame([], array_slice($failures, 0, 40), count($failures).' golden theme failures');
    }

    private function near(string $got, string $want): bool
    {
        if ($got === $want) {
            return true;
        }

        if (! Oklch::isColor($got) || ! Oklch::isColor($want)) {
            return false;
        }

        $a = Oklch::parse($got);
        $b = Oklch::parse($want);

        for ($i = 0; $i < 3; $i++) {
            if (abs($a[$i] - $b[$i]) > 1) {
                return false;
            }
        }

        return true;
    }

    private function assertColourNear(string $want, string $got, string $message): void
    {
        $this->assertTrue($this->near($got, $want), "{$message}: got {$got}, want {$want}");
    }
}
