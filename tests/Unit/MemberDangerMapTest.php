<?php

namespace Tests\Unit;

use App\Support\Member\DangerMap;
use App\Support\Member\DangerMapData;
use PHPUnit\Framework\TestCase;

/**
 * 1.2.3 round 1 (R1-070): the member danger tokens per look (scripts/member/danger_tokens.py) are no longer all 348
 * blocks of the member CSS entry (resources/sass/member/_danger.scss, 11.6 KB of every signed-in page's render-blocking
 * CSS). The generator writes App\Support\Member\DangerMapData and the layout prints the page's own look's rules
 * (DangerMap::css()), with the stylesheet's selectors and specificity.
 */
class MemberDangerMapTest extends TestCase
{
    private const NEUTRAL_DARK = '--mk-danger:var(--avd-text);--mk-danger-rgb:var(--avd-glint-rgb);--mk-danger-fill:var(--avd-text);--mk-on-danger:var(--avd-bg)';

    private const NEUTRAL_LIGHT = '--mk-danger:var(--avd-text);--mk-danger-rgb:var(--avd-shade-rgb);--mk-danger-fill:var(--avd-text);--mk-on-danger:var(--avd-bg)';

    private const RESET = '--mk-danger:var(--avd-danger);--mk-danger-rgb:var(--avd-danger-rgb);--mk-danger-fill:var(--avd-danger-fill);--mk-on-danger:var(--avd-on-danger)';

    public function test_a_look_gets_its_themes_rules_for_both_modes(): void
    {
        // the default look (Tokyo Neon): its danger is within dE00 22 of its brand in both modes
        $this->assertSame(
            'html[data-avd-theme="tokyo_neon"][data-bs-theme="dark"]{'.self::NEUTRAL_DARK.'}'
            .'html[data-avd-theme="tokyo_neon"][data-bs-theme="light"]{'.self::NEUTRAL_LIGHT.'}',
            DangerMap::css('tokyo_neon', 'signature'),
        );
        // a colour style with a danger of its own puts it back (the theme's block overrides, the style's does not)
        $this->assertSame(
            'html[data-avd-theme="tokyo_neon"][data-bs-theme="dark"]{'.self::NEUTRAL_DARK.'}'
            .'html[data-avd-theme="tokyo_neon"][data-avd-flavor="emerald"][data-bs-theme="dark"]{'.self::RESET.'}'
            .'html[data-avd-theme="tokyo_neon"][data-bs-theme="light"]{'.self::NEUTRAL_LIGHT.'}'
            .'html[data-avd-theme="tokyo_neon"][data-avd-flavor="emerald"][data-bs-theme="light"]{'.self::RESET.'}',
            DangerMap::css('tokyo_neon', 'emerald'),
        );
        // a colour style that needs the override where its theme does not
        $this->assertStringContainsString('html[data-avd-theme="aurora_borealis"][data-avd-flavor="rose"][data-bs-theme="dark"]{'.self::NEUTRAL_DARK.'}', DangerMap::css('aurora_borealis', 'rose'));
        $this->assertStringNotContainsString('[data-avd-flavor', DangerMap::css('aurora_borealis', 'signature'));
    }

    public function test_a_look_that_keeps_its_own_danger_and_anything_else_print_nothing(): void
    {
        $kept = array_filter(['daylight_paper', 'swiss_monolith', 'cyber_matrix'], static fn (string $theme): bool => ! isset(DangerMapData::BLOCKS[$theme.'|dark']) && ! isset(DangerMapData::BLOCKS[$theme.'|light']));
        $this->assertNotEmpty($kept);
        foreach ($kept as $theme) {
            $this->assertSame('', DangerMap::css($theme, 'signature'), $theme);
        }

        foreach ([null, '', 'no_such_theme', 'tokyo_neon"]{color:red}', 'Tokyo_Neon', '../x', str_repeat('a', 65)] as $theme) {
            $this->assertSame('', DangerMap::css($theme, 'signature'), var_export($theme, true));
        }
        // a colour style name that is not a plain identifier is ignored, the theme's rules stay
        $this->assertSame(DangerMap::css('tokyo_neon', null), DangerMap::css('tokyo_neon', 'x"]{color:red}'));
    }

    public function test_the_generated_map_names_existing_looks_and_valid_declarations(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame('neutral', DangerMapData::STRATEGY);
        $this->assertGreaterThan(50, count(DangerMapData::BLOCKS));
        foreach (DangerMapData::BODIES as $body) {
            $this->assertMatchesRegularExpression('/^--mk-danger:[^;{}]+;--mk-danger-rgb:[^;{}]+;--mk-danger-fill:[^;{}]+;--mk-on-danger:[^;{}]+$/', $body);
        }
        foreach (DangerMapData::BLOCKS as $key => $index) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+(\/[a-z0-9_]+)?\|(dark|light)$/', $key);
            $this->assertArrayHasKey($index, DangerMapData::BODIES, $key);
            [$look] = explode('|', $key);
            // a theme's token file (<theme>.css) or a colour style's (<theme>/<flavor>.css)
            $this->assertFileExists($root.'/resources/css/themes/'.$look.'.css', $key);
        }

        // the map is not in the member CSS entry any more, and the old partial is gone
        $this->assertFileDoesNotExist($root.'/resources/sass/member/_danger.scss');
        $this->assertStringNotContainsString('@import "member/danger"', (string) file_get_contents($root.'/resources/sass/member.scss'));
        $this->assertStringContainsString("'app', 'Support', 'Member', 'DangerMapData.php'", (string) file_get_contents($root.'/scripts/member/danger_tokens.py'));
    }
}
