<?php

namespace Tests\Unit;

use App\Support\Color\Contrast;
use App\Support\ThemeTokens;
use Tests\TestCase;

/**
 * 1.2.3 round 1b (R1R-13): the label of the look's primary button reads at 4.5:1 as rendered. The film grain
 * (surface_noise_grain_enabled, on by default) draws every fill a shade lighter or darker than its flat colour, so the
 * guest Subscribe of Swiss Bento dark (white on #e62335, 4.51:1 by the numbers) measured 4.45-4.48:1 on screen, and
 * Esports Arena light (white on #e22b3a, 4.525) 4.47-4.48. On real pages a flat primary fill now moves just enough in
 * lightness for 4.6:1 with its label (ThemeTokens::build, like the side menu's active ink); a gradient (the default
 * look's demo colours, owner decision D1) and an owner's exact colour (brand_contrast_autofix off) stay as they are.
 */
class PrimaryButtonContrastTest extends TestCase
{
    public function test_every_flat_primary_button_label_keeps_4_6_on_real_pages(): void
    {
        $short = [];
        $checked = 0;

        foreach (ThemeTokens::themes() as $id => $theme) {
            foreach ($theme['flavors'] as $flavor) {
                $flavor = ThemeTokens::flavor($theme, $flavor['id']);

                foreach (['dark', 'light'] as $mode) {
                    $tokens = ThemeTokens::derive($theme, $mode, $flavor);
                    [$bg, $text] = [$tokens['--avd-btn-bg'], $tokens['--avd-btn-text']];

                    if (preg_match('/^#[0-9a-f]{6}$/i', $bg) !== 1 || preg_match('/^#[0-9a-f]{6}$/i', $text) !== 1) {
                        continue;
                    }

                    $checked++;
                    $ratio = Contrast::contrast($text, $bg);
                    if ($ratio < 4.6) {
                        $short[] = sprintf('%s/%s/%s: %s on %s = %.3f', $id, $flavor['id'], $mode, $text, $bg, $ratio);
                    }

                    // only the fill moves, and only a little: the label and the hue stay
                    $reference = ThemeTokens::derive($theme, $mode, $flavor, pageFit: false);
                    $this->assertSame($reference['--avd-btn-text'], $text, "{$id}/{$flavor['id']}/{$mode}: the label stays");
                    $this->assertLessThan(0.03, abs(Contrast::lightness($bg) - Contrast::lightness($reference['--avd-btn-bg'])), "{$id}/{$flavor['id']}/{$mode}: a shade, not another colour");
                }
            }
        }

        $this->assertGreaterThan(200, $checked);
        $this->assertSame([], $short, 'primary buttons whose label is under 4.6:1 on their flat fill');
    }

    public function test_the_looks_that_measured_under_4_5_on_screen(): void
    {
        // Swiss Bento dark and Esports Arena light: a shade darker, white labels at 4.6:1 or more
        $swiss = ThemeTokens::derive(ThemeTokens::theme('swiss_monolith'), 'dark');
        $this->assertSame('#ffffff', $swiss['--avd-btn-text']);
        $this->assertNotSame('#e62335', $swiss['--avd-btn-bg']);
        $this->assertGreaterThanOrEqual(4.6, Contrast::contrast('#ffffff', $swiss['--avd-btn-bg']));
        $this->assertSame('#e62335', ThemeTokens::derive(ThemeTokens::theme('swiss_monolith'), 'dark', pageFit: false)['--avd-btn-bg'], 'the reference derivation is unchanged');

        $esports = ThemeTokens::derive(ThemeTokens::theme('cyber_gladiator'), 'light');
        $this->assertGreaterThanOrEqual(4.6, Contrast::contrast($esports['--avd-btn-text'], $esports['--avd-btn-bg']));

        // the shipped token file carries it
        $file = (string) file_get_contents(resource_path('css/themes/swiss_monolith.css'));
        $this->assertStringContainsString('--avd-btn-bg: '.$swiss['--avd-btn-bg'].';', $file);

        // the default look's gradient keeps the demo site's colours (owner decision D1)
        $default = ThemeTokens::derive(ThemeTokens::theme('tokyo_neon'), 'dark');
        $this->assertSame('linear-gradient(135deg, #ea094e 0%, #7928ca 100%)', $default['--avd-btn-bg']);
    }
}
