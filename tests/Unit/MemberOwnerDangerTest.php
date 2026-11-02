<?php

namespace Tests\Unit;

use App\Support\Member\DangerTokens;
use App\Support\ThemeTokens;
use Tests\TestCase;

/**
 * Member design 3.1a (revision 3): under the OWNER's own colours (Appearance > Brand "Main
 * colour", "Pure black in dark mode") the member danger is decided again for the owner's brand, so Save and Delete never
 * become one colour, and the danger is never the warning colour either ("Upload failed" next to "Unlisted").
 */
class MemberOwnerDangerTest extends TestCase
{
    /** The 12 looks: theme and colour style. */
    private const LOOKS = [
        'youtube_spotlight_pro' => ['tokyo_neon', 'signature'], 'daylight_creator' => ['daylight_paper', 'signature'],
        'netflix_cinema' => ['pure_oled_black', 'signature'], 'velvet_lounge' => ['rose_gold_velvet', 'signature'],
        'tiktok_shorts_hub' => ['fairy_floss', 'signature'], 'apple_bento_studio' => ['swiss_monolith', 'signature'],
        'cyberpunk_hud' => ['shibuya_rain', 'signature'], 'twitch_esports_live' => ['cyber_gladiator', 'signature'],
        'cosmic_astronomy_theater' => ['interstellar', 'orion'], 'luxury_imperial_salon' => ['obsidian_gold', 'signature'],
        'editorial_magazine' => ['broadsheet', 'signature'], 'glass_lounge' => ['lavender_cloud', 'signature'],
    ];

    /** The setting's default, the reds an owner is most likely to pick, the extremes and a pastel. */
    private const OWNER_COLOURS = ['#ff3d61', '#dc2626', '#e11d48', '#ffffff', '#111111', '#f2a7ff'];

    public function test_owner_colours_keep_danger_away_from_the_brand_and_the_warning_in_the_twelve_looks(): void
    {
        $cases = 0;
        $problems = [];

        foreach (self::LOOKS as $look => [$themeId, $flavorId]) {
            $theme = ThemeTokens::theme($themeId);
            $this->assertNotNull($theme, "theme {$themeId}");
            $flavor = ThemeTokens::flavor($theme, $flavorId);

            foreach (['dark', 'light'] as $mode) {
                $palettes = [];

                foreach (self::OWNER_COLOURS as $colour) {
                    $palettes[$colour] = ['brand' => $colour];
                }

                if ($mode === 'dark') {
                    $palettes['pure black'] = ['bg' => '#000000', 'bg2' => '#000000'];
                    $palettes['#ff3d61 + pure black'] = ['brand' => '#ff3d61', 'bg' => '#000000', 'bg2' => '#000000'];
                }

                foreach ($palettes as $name => $palette) {
                    $cases++;
                    $tokens = ThemeTokens::derive($theme, $mode, $flavor, $palette, true);
                    $result = DangerTokens::decide($tokens, $mode);
                    $where = "{$look} {$mode} {$name} ({$result['strategy']})";

                    $bg = $this->rgb($tokens['--avd-bg']);
                    $surface1 = DangerTokens::over(DangerTokens::colour($tokens['--avd-surface-1']), $bg);
                    $brandInk = DangerTokens::over(DangerTokens::colour($tokens['--avd-brand-ink']), $bg);
                    $brandFill = DangerTokens::over(DangerTokens::colour($tokens['--avd-brand-fill']), $surface1);
                    $warning = DangerTokens::over(DangerTokens::colour($tokens['--avd-warning']), $bg);

                    foreach (['ink' => $result['ink'], 'fill' => $result['fill']] as $part => $danger) {
                        foreach (['brand ink' => $brandInk, 'brand fill' => $brandFill, 'warning ink' => $warning] as $label => $other) {
                            $distance = DangerTokens::deltaE($other, $danger);

                            if ($distance < 20.0) {
                                $problems[] = sprintf('%s: danger %s is dE00 %.1f from the %s', $where, $part, $distance, $label);
                            }
                        }
                    }

                    foreach (['bg' => $bg, 'surface-1' => $surface1] as $label => $surface) {
                        $contrast = DangerTokens::contrast($result['ink'], $surface);

                        if ($contrast < 4.5) {
                            $problems[] = sprintf('%s: danger text %.2f:1 on %s', $where, $contrast, $label);
                        }
                    }

                    $labelContrast = DangerTokens::contrast($result['label'], $result['fill']);

                    if ($labelContrast < 4.5) {
                        $problems[] = sprintf('%s: label %.2f:1 on the danger fill', $where, $labelContrast);
                    }
                }
            }
        }

        $this->assertSame(12 * (2 * 6 + 2), $cases);
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_pure_black_velvet_lounge_no_longer_gets_the_amber_of_its_warning(): void
    {
        // #e88a16, dE00 6.6 from the warning #f59e0b, before the warning rule
        $theme = ThemeTokens::theme('rose_gold_velvet');
        $tokens = ThemeTokens::derive($theme, 'dark', ThemeTokens::flavor($theme, 'signature'), ['bg' => '#000000', 'bg2' => '#000000'], true);
        $result = DangerTokens::decide($tokens, 'dark');
        $warning = DangerTokens::over(DangerTokens::colour($tokens['--avd-warning']), $this->rgb($tokens['--avd-bg']));

        $this->assertNotSame('#e88a16', $result['vars']['--mk-danger']);
        $this->assertGreaterThanOrEqual(22.0, DangerTokens::deltaE($warning, $result['ink']));
    }

    public function test_the_owner_css_carries_the_member_danger_block_and_a_new_engine_version(): void
    {
        $this->assertSame('2.3', ThemeTokens::ENGINE_VERSION);

        $css = ThemeTokens::ownerOverrides(['primary_color' => '#ff3d61'], 'daylight_paper', 'signature', ['dark', 'light']);

        $this->assertStringContainsString('html:root[data-avd-theme][data-avd-flavor][data-bs-theme="dark"]{--mk-danger:', $css);
        $this->assertStringContainsString('html:root[data-avd-theme][data-avd-flavor][data-bs-theme="light"]{--mk-danger:', $css);
        // Daylight's own red is dE00 0.4 from #ff3d61 in dark mode: the owner block must not keep the theme's red there
        $this->assertStringNotContainsString('[data-bs-theme="dark"]{--mk-danger:var(--avd-danger)', $css);
    }

    public function test_no_owner_colour_prints_no_owner_block(): void
    {
        $this->assertSame('', ThemeTokens::ownerOverrides([], 'tokyo_neon', 'signature', ['dark', 'light']));
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $value): array
    {
        $c = DangerTokens::colour($value);

        return [$c[0], $c[1], $c[2]];
    }
}
