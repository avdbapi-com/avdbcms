<?php

namespace Tests\Feature;

use App\Support\ThemeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round 1 (R1-068, perf-12 b-d): when a look's web font arrived, the text laid out in the fallback font re-wrapped
 * (History at 390px: CLS 0.037 on every slow load, 0.165 when "Pause history" no longer fitted beside the search; the
 * Subscriptions channel chips grew 14px, CLS 0.058-0.065 at 1366). Each web font of a look gets a metric-matched
 * fallback face (a local Arial / Times New Roman / Courier New with the family's size-adjust and ascent / descent
 * overrides), named right after it in the look's font stacks; the layout prints the faces of the page's look.
 */
class FontFallbackFacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stacks_name_the_fallback_face_right_after_the_family(): void
    {
        $fonts = ThemeTokens::fonts((array) ThemeTokens::theme(ThemeTokens::DEFAULT_THEME));
        $families = array_keys($fonts['families']);
        $this->assertNotEmpty($families);

        $css = ThemeTokens::fallbackFaces($families);
        foreach ($families as $family) {
            $this->assertMatchesRegularExpression(
                '/@font-face\{font-family:"'.preg_quote($family, '/').' Fallback";src:local\("(?:Arial|Times New Roman|Courier New)"\)[^}]*;size-adjust:[0-9.]+%;ascent-override:[0-9.]+%;descent-override:[0-9.]+%;line-gap-override:[0-9.]+%;unicode-range:U\+[0-9A-F,U+-]+\}/',
                $css,
                $family,
            );
        }
        $this->assertSame('', ThemeTokens::fallbackFaces(['No Such Family']));

        // the theme token file (built by avd:themes:build) carries the stacks
        $file = (string) file_get_contents(resource_path('css/themes/'.ThemeTokens::DEFAULT_THEME.'.css'));
        $this->assertMatchesRegularExpression("/--avd-font-body: '([^']+)', '\\1 Fallback', /", $file);
        $this->assertMatchesRegularExpression("/--avd-font-display: '([^']+)', '\\1 Fallback', /", $file);
        $this->assertSame(0, $this->artisan('avd:themes:build', ['--check' => true])->run(), 'the token files are up to date');
    }

    public function test_a_page_prints_the_fallback_faces_of_its_looks_fonts(): void
    {
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<style id="avd-font-fallbacks">(.*?)</style>#', $html, $style), 'one block');
        $fonts = ThemeTokens::fonts((array) ThemeTokens::theme(ThemeTokens::DEFAULT_THEME));
        $this->assertSame(ThemeTokens::fallbackFaces(array_keys($fonts['families'])), $style[1]);
        // before the main CSS asks for the families
        $this->assertLessThan(strpos($html, 'id="dynamic-theme"'), strpos($html, 'id="avd-font-fallbacks"'));
    }

    /**
     * Round 1 review (RV-FC-04): the faces had no unicode-range, so they drew every character the web font lacks for
     * good: Russian text in the default look (Outfit and Plus Jakarta Sans have no basic Cyrillic) went from Segoe UI or
     * Georgia (real bold faces) to a regular-only Arial or Times New Roman at the family's size-adjust, with synthetic
     * bold. A face now covers exactly the text subsets its web font has (Google Fonts' own ranges), never more.
     */
    public function test_a_fallback_face_covers_only_what_its_web_font_draws(): void
    {
        $tokens = new \ReflectionClass(ThemeTokens::class);
        $faces = (array) $tokens->getConstant('FALLBACK_FACES');
        $subsets = (array) $tokens->getConstant('FONT_SUBSETS');
        $this->assertNotEmpty($faces);
        $this->assertSame([], array_values(array_diff(array_keys($faces), array_keys($subsets))), 'every fallback family lists its subsets');

        $covers = static function (string $range, int $char): bool {
            foreach (explode(',', $range) as $part) {
                [$from, $to] = array_pad(explode('-', substr(trim($part), 2), 2), 2, null);
                if ($char >= hexdec($from) && $char <= hexdec($to ?? $from)) {
                    return true;
                }
            }

            return false;
        };

        foreach (array_keys($faces) as $family) {
            $range = ThemeTokens::fallbackRange($family);
            $this->assertStringContainsString('unicode-range:'.$range.'}', ThemeTokens::fallbackFaces([$family]), $family);
            $this->assertTrue($covers($range, 0x41) && $covers($range, 0x7A) && $covers($range, 0xE9), $family.': Latin');
            $this->assertSame(in_array('cyrillic', $subsets[$family], true), $covers($range, 0x0436), $family.': Cyrillic "ж" only with its web font');
            $this->assertSame(in_array('greek', $subsets[$family], true), $covers($range, 0x03A9), $family.': Greek "Ω" only with its web font');
            $this->assertSame(in_array('vietnamese', $subsets[$family], true), $covers($range, 0x1EA1), $family.': Vietnamese "ạ" only with its web font');
            $this->assertFalse($covers($range, 0x0E01), $family.': never Thai (no such glyphs in the local fonts)');
            $this->assertFalse($covers($range, 0x0627), $family.': never Arabic (its own stack)');
            $this->assertFalse($covers($range, 0x4E00), $family.': never CJK');
        }

        // the default look's families: no basic Cyrillic in either, so a Russian page keeps the system font for its text
        $default = array_keys(ThemeTokens::fonts((array) ThemeTokens::theme(ThemeTokens::DEFAULT_THEME))['families']);
        foreach ($default as $family) {
            $this->assertFalse($covers(ThemeTokens::fallbackRange($family), 0x0410), $family.': "А" (U+0410)');
        }
        // a family whose web font has Cyrillic keeps its metric-matched face for Russian too
        $this->assertTrue($covers(ThemeTokens::fallbackRange('Inter'), 0x0410));
        // compact and merged: no overlapping or touching ranges left
        $this->assertSame('U+0-FF,U+131,U+152-153,U+2BB-2BC,U+2C6,U+2DA,U+2DC,U+304,U+308,U+329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD', ThemeTokens::fallbackRange('Orbitron'));
        $this->assertSame(ThemeTokens::fallbackRange('Orbitron'), ThemeTokens::fallbackRange('No Such Family'), 'unknown: Latin only');
    }

    public function test_the_history_search_leaves_room_for_the_pause_button_at_360px(): void
    {
        $scss = (string) file_get_contents(resource_path('sass/member/_channel.scss'));

        // 11rem + .5rem gap + "Pause history" (about 8.5rem) fits a 360px phone's 337px row in any font
        $this->assertStringContainsString(':root .mk-history__search { display: flex; flex: 1 1 11rem; min-inline-size: 0; }', $scss);
    }
}
