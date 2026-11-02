<?php

namespace Tests\Feature;

use App\Support\ThemeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3 round 1b (R1R-03): while a look's web font loads, bold text (600-900) set in the family's fallback face was
 * the regular face drawn in synthetic bold: the header's Upload button grew 3.5-7 px and its buttons and links moved
 * when the web font arrived. Each family with a bold weight now has a bold fallback face: the local BOLD font of its
 * kind (Arial Bold, Times New Roman Bold, Courier New Bold) at the size-adjust and ascent / descent / line-gap
 * overrides measured for the web font's 700 weight, over exactly the characters the regular face covers (the
 * subsets its web font has). The browser picks the bold face for bold text by its weight and sends a character
 * outside that face's range to the next font of the stack, not to the regular face: with a Latin-only bold face, bold
 * Vietnamese was drawn in two fonts inside one word and bold Cyrillic in the system font (second pass, BF-RV-02). The
 * layout prints it with the regular face (FontFallbackFacesTest).
 */
class FontFallbackBoldFacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_family_with_a_bold_weight_gets_a_bold_face_from_the_local_bold_font(): void
    {
        $tokens = new \ReflectionClass(ThemeTokens::class);
        $regular = (array) $tokens->getConstant('FALLBACK_FACES');
        $bold = (array) $tokens->getConstant('FALLBACK_BOLD_FACES');
        $this->assertGreaterThan(40, count($bold));
        $this->assertSame([], array_values(array_diff(array_keys($bold), array_keys($regular))), 'a bold face only for a family with a regular one');
        $this->assertArrayNotHasKey('VT323', $bold, 'no bold weight, no bold face');

        $local = ['sans' => 'local("Arial Bold"), local("Arial-BoldMT")', 'serif' => 'local("Times New Roman Bold"), local("TimesNewRomanPS-BoldMT")', 'mono' => 'local("Courier New Bold"), local("CourierNewPS-BoldMT")'];
        foreach ($bold as $family => [$size, $ascent, $descent, $gap]) {
            $css = ThemeTokens::fallbackFaces([$family]);
            $kind = $regular[$family][0];

            $this->assertSame(2, substr_count($css, '@font-face{font-family:"'.$family.' Fallback";'), $family.': the regular face and one bold face');
            $this->assertStringStartsWith('@font-face{font-family:"'.$family.' Fallback";src:', $css, $family.': the regular face first, as before');
            $this->assertStringContainsString('@font-face{font-family:"'.$family.' Fallback";font-weight:600 900;src:'.$local[$kind], $css, $family);
            $this->assertStringContainsString(';size-adjust:'.$size.'%;ascent-override:'.$ascent.'%;descent-override:'.$descent.'%;'.'line-gap-override:'.$gap.'%;', $css, $family);
            $this->assertGreaterThan(50, $size);
            $this->assertLessThan(200, $size);

            // both faces cover the same characters: those the web font draws
            $this->assertSame(2, preg_match_all('/unicode-range:([^}]*)\}/', $css, $ranges), $family);
            $this->assertSame(ThemeTokens::fallbackRange($family), $ranges[1][0], $family.': the regular face');
            $this->assertSame($ranges[1][0], $ranges[1][1], $family.': the bold face covers what the regular face covers');
        }

        // bold Vietnamese and Cyrillic stay in the fallback where the web font has them, and only there
        $range = fn (string $family): string => $this->boldRange($family);
        $this->assertTrue($this->covers($range('Plus Jakarta Sans'), 0x1EAD), 'Plus Jakarta Sans (vietnamese): "ậ" of "Đăng nhập"');
        $this->assertTrue($this->covers($range('Plus Jakarta Sans'), 0x0110), 'Plus Jakarta Sans: "Đ"');
        $this->assertTrue($this->covers($range('Inter'), 0x0416), 'Inter (cyrillic): "Ж"');
        $this->assertTrue($this->covers($range('Inter'), 0x0412), 'Inter: "В" of "Войти"');
        $this->assertFalse($this->covers($range('Outfit'), 0x0416), 'Outfit has no Cyrillic: the stack\'s system font draws it, as before');
        $this->assertFalse($this->covers($range('Outfit'), 0x1EAD), 'Outfit has no Vietnamese');

        // the default look's two families: the page prints four faces (about 1 KB more than the regular ones)
        $families = array_keys(ThemeTokens::fonts((array) ThemeTokens::theme(ThemeTokens::DEFAULT_THEME))['families']);
        $css = ThemeTokens::fallbackFaces($families);
        $this->assertSame(2 * count($families), substr_count($css, '@font-face'));
        $this->assertLessThan(2000, strlen($css));
    }

    public function test_the_page_prints_the_bold_faces_with_the_regular_ones(): void
    {
        $html = $this->get(route('pages.trend'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<style id="avd-font-fallbacks">(.*?)</style>#', $html, $style));
        $this->assertStringContainsString('font-weight:600 900;src:local("Arial Bold")', $style[1]);
    }

    private function boldRange(string $family): string
    {
        $css = ThemeTokens::fallbackFaces([$family]);
        $this->assertSame(1, preg_match('/font-weight:600 900;[^}]*unicode-range:([^}]*)\}/', $css, $m), $family);

        return $m[1];
    }

    private function covers(string $range, int $codePoint): bool
    {
        foreach (explode(',', $range) as $part) {
            if (preg_match('/^U\+([0-9A-F]+)(?:-([0-9A-F]+))?$/i', trim($part), $m) === 1) {
                $from = (int) hexdec($m[1]);
                $to = isset($m[2]) ? (int) hexdec($m[2]) : $from;

                if ($codePoint >= $from && $codePoint <= $to) {
                    return true;
                }
            }
        }

        return false;
    }
}
