<?php

namespace Tests\Unit;

use App\Support\TextDirection;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (R3-015, R3-060): the direction of a member's or creator's text is the one dir="auto" would give (its
 * first strong letter), printed only where it differs from the page's.
 */
class TextDirectionTest extends TestCase
{
    public function test_the_first_strong_letter_decides(): void
    {
        $cases = [
            'Tokyo night walk in the rain - 4K binaural city ambience' => 'ltr',
            'رحلة ليلية على الطريق الساحلي' => 'rtl',
            'שלום עולם' => 'rtl',
            '4K binaural city' => 'ltr',          // digits are weak: the first letter decides
            '2026: رحلة' => 'rtl',
            '«Привет, мир»' => 'ltr',
            '— 東京の夜' => 'ltr',
            '😀 مرحبا' => 'rtl',
            "\u{200F}Night" => 'rtl',             // a right-to-left mark is strong
            "\u{200E}مرحبا" => 'ltr',             // so is a left-to-right mark
            "\u{061C}abc" => 'rtl',               // and the Arabic letter mark
            'ހެލޯ' => 'rtl',                       // Thaana
            'ߒߞߏ' => 'rtl',                       // NKo
        ];
        foreach ($cases as $text => $direction) {
            $this->assertSame($direction, TextDirection::of($text), $text);
        }
        foreach (['', '   ', '123 456', '!?…', '😀', null] as $none) {
            $this->assertNull(TextDirection::of($none), var_export($none, true));
        }
        $this->assertNull(TextDirection::of("\xFF\xFE not utf-8"), 'not UTF-8: no answer, no error');
    }

    public function test_only_a_text_of_the_other_direction_gets_the_attribute(): void
    {
        config(['site.rtl_support' => false]);
        app()->setLocale('en');
        $this->assertFalse(TextDirection::pageIsRtl());
        $this->assertSame('', TextDirection::attr('Night drives'));
        $this->assertSame(' dir=rtl', TextDirection::attr('رحلة ليلية'));
        $this->assertSame('', TextDirection::attr('1234'));
        $this->assertSame('', TextDirection::attr(null));

        app()->setLocale('ar');
        $this->assertTrue(TextDirection::pageIsRtl());
        $this->assertSame(' dir=ltr', TextDirection::attr('Night drives'));
        $this->assertSame('', TextDirection::attr('رحلة ليلية'));

        // every other language of the site is left to right
        foreach (array_keys((array) config('languages')) as $locale) {
            app()->setLocale($locale);
            $this->assertSame($locale === 'ar', TextDirection::pageIsRtl(), $locale);
        }
        app()->setLocale('en');
    }
}
