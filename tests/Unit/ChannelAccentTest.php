<?php

namespace Tests\Unit;

use App\Support\ChannelAccent;
use Tests\TestCase;

/**
 * The creator's channel accent inside the look (member design 6.19, QA channel-11): "look" adds no colour; every
 * preset and custom accent keeps 4.5:1 against the surfaces of each mode (on light looks the coral labels were
 * 2.5-2.7:1).
 */
class ChannelAccentTest extends TestCase
{
    public function test_the_look_accent_has_no_colour_of_its_own(): void
    {
        $this->assertNull(ChannelAccent::inks('look'));
        $this->assertSame('', ChannelAccent::style('look'));
        $this->assertNull(ChannelAccent::inks('unknown'));
    }

    public function test_every_preset_and_custom_accents_keep_four_and_a_half_to_one_in_both_modes(): void
    {
        $accents = array_map(fn (string $hex) => ['custom', $hex], ['#ffffff', '#000000', '#fff200', '#18c8d8', '#0000ff', '#ff3d61', '#7f7f7f']);
        foreach (array_keys(ChannelAccent::PRESETS) as $preset) {
            $accents[] = [$preset, null];
        }

        foreach ($accents as [$accent, $custom]) {
            $inks = ChannelAccent::inks($accent, $custom);
            $this->assertNotNull($inks, $accent . ' ' . $custom);
            $this->assertGreaterThanOrEqual(4.5, ChannelAccent::contrast($inks['dark'], '#2c3140'), "{$accent} {$custom} dark");
            $this->assertGreaterThanOrEqual(4.5, ChannelAccent::contrast($inks['dark'], '#0b0e15'), "{$accent} {$custom} dark page");
            $this->assertGreaterThanOrEqual(4.5, ChannelAccent::contrast($inks['light'], '#e6e8ee'), "{$accent} {$custom} light");
            $this->assertGreaterThanOrEqual(4.5, ChannelAccent::contrast($inks['light'], '#ffffff'), "{$accent} {$custom} light page");
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $inks['dark']);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $inks['light']);
        }
    }

    public function test_an_accent_that_already_reads_is_kept_and_a_bad_custom_value_never_reaches_the_page(): void
    {
        // amber reads on the dark surfaces as it is; on light ones it is darkened (same hue)
        $this->assertSame('#fbbf24', ChannelAccent::ink('#fbbf24', 'dark'));
        $this->assertNotSame('#fbbf24', ChannelAccent::ink('#fbbf24', 'light'));

        $style = ChannelAccent::style('custom', '"><script>alert(1)</script>');
        $this->assertStringNotContainsString('<', $style);
        $this->assertMatchesRegularExpression('/^--mk-accent-dark:#[0-9a-f]{6};--mk-accent-light:#[0-9a-f]{6};$/', $style);
    }
}
