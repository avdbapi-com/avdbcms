<?php

namespace Tests\Feature;

use App\Support\ThemeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (R3-023): the metric-matched fallback faces (R1-068, R1R-03) made the watch page's first full layout
 * about a third dearer on a weak device (CPU x4: 824 ms of layout against 617 ms without them): a browser sets up its
 * local font matching the first time a page in a new renderer needs a local() face, and that happened inside the page's
 * first full layout, whatever the number of faces, weights or local() names. The layout now asks for the faces right
 * after their block (document.fonts.load), while the page still waits for its stylesheets, so the set-up happens there.
 */
class FontFallbackEarlyLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_families_with_a_fallback_face_in_their_order(): void
    {
        $this->assertSame(['Outfit', 'Plus Jakarta Sans'], ThemeTokens::fallbackFamilies(['Outfit', 'No Such Family', 'Plus Jakarta Sans', 'Outfit']));
        $this->assertSame([], ThemeTokens::fallbackFamilies(['No Such Family']));
        $this->assertSame([], ThemeTokens::fallbackFamilies([]));

        // exactly the families fallbackFaces() prints faces for
        $fonts = ThemeTokens::fonts((array) ThemeTokens::theme(ThemeTokens::DEFAULT_THEME));
        $families = array_keys($fonts['families']);
        preg_match_all('/font-family:"([^"]+) Fallback"/', ThemeTokens::fallbackFaces($families), $named);
        $this->assertSame(array_values(array_unique($named[1])), ThemeTokens::fallbackFamilies($families));
    }

    public function test_a_page_asks_for_its_faces_right_after_their_block(): void
    {
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<style id="avd-font-fallbacks">.*?</style>\s*<script>(.*?)</script>#s', $html, $script), 'the script follows the block');
        $fonts = ThemeTokens::fonts((array) ThemeTokens::theme(ThemeTokens::DEFAULT_THEME));
        $families = ThemeTokens::fallbackFamilies(array_keys($fonts['families']));
        $this->assertNotEmpty($families);
        $this->assertStringEndsWith('(document.fonts,'.json_encode($families, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).')', $script[1]);
        // the regular and the bold face of each family; a face that cannot load (no Arial on the device) stays quiet
        $this->assertStringContainsString("f.load('1em \"'+n[i]+' Fallback\"')['catch'](function(){})", $script[1]);
        $this->assertStringContainsString("f.load('700 1em \"'+n[i]+' Fallback\"')['catch'](function(){})", $script[1]);
        $this->assertStringContainsString('if(f&&f.load)', $script[1], 'only where the browser has the font loading API');
        $this->assertStringNotContainsString('=>', $script[1], 'ES5');
        // once, and before the stylesheets the page waits for
        $this->assertSame(1, substr_count($html, '(function(f,n){if(f&&f.load)'));
        $this->assertLessThan(strpos($html, 'id="dynamic-theme"'), strpos($html, '(function(f,n){if(f&&f.load)'));
    }
}
