<?php

namespace Tests\Unit;

use App\Helpers\Number;
use App\Support\LocalNumber;
use Tests\TestCase;

/**
 * R1-063: the cards' short counts and the watch page's full count in the reader's language. "11,537 vues" (French) and
 * "18.8 k" inside Arabic text (read "k 18.8") came from English separators and the latin "k". The same numbers are
 * printed by resources/js/utils/format.ts formatCompactNumber (tests/js/front_logic.test.ts).
 */
class LocalNumberCompactTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_english_short_counts_are_unchanged(): void
    {
        app()->setLocale('en');

        foreach ([0 => '0', 7 => '7', 999 => '999', 1000 => '1.0 k', 1050 => '1.1 k', 2900 => '2.9 k', 99449 => '99.4 k',
            99950 => '100 k', 123456 => '123 k', 999999 => '1,000 k', 1000000 => '1.0 M', 3200000000000 => '3.2 T'] as $value => $text) {
            $this->assertSame($text, Number::humanize($value), (string) $value);
        }
        $this->assertSame('0', Number::humanize(-5));
    }

    public function test_short_counts_take_the_readers_decimal_sign_and_word(): void
    {
        $cases = [
            'fr' => [18800 => '18,8 k', 2500000 => '2,5 M', 999 => '999'],
            'de' => [18800 => '18,8 Tsd.', 2500000 => '2,5 Mio.'],
            'ar' => [18800 => '18.8 ألف', 738000 => '738 ألف', 2500000 => '2.5 مليون'],
            'ru' => [18800 => '18,8 тыс.'],
            'es' => [18800 => '18,8 mil'],
            'ja' => [1050 => '1,050', 18800 => '1.9万', 2500000 => '250万'],
            'zh' => [125000 => '12.5万'],
            'ko' => [1050 => '1.1천', 18800 => '1.9만'],
            'hi' => [18800 => '18.8 हज़ार', 150000 => '1.5 लाख'],
        ];

        foreach ($cases as $locale => $values) {
            app()->setLocale($locale);

            foreach ($values as $value => $text) {
                $this->assertSame($text, Number::humanize($value), $locale.' '.$value);
                $this->assertSame($text, LocalNumber::compact($value, $locale), $locale.' '.$value.' (explicit locale)');
            }
        }
    }

    public function test_arabic_short_counts_keep_western_digits_and_never_end_with_a_latin_letter(): void
    {
        app()->setLocale('ar');

        $text = Number::humanize(738000);

        $this->assertMatchesRegularExpression('/^[0-9.,]+ \p{Arabic}+$/u', $text, 'a latin "k" after the number reads "k 738" in Arabic text');
    }

    public function test_full_counts_take_the_readers_thousands_separator(): void
    {
        app()->setLocale('en');
        $this->assertSame('11,537', LocalNumber::format(11537));

        app()->setLocale('fr');
        $this->assertSame("11\u{202F}537", LocalNumber::format(11537));

        app()->setLocale('de');
        $this->assertSame('11.537', LocalNumber::format(11537));

        app()->setLocale('ar');
        $this->assertSame('11,537', LocalNumber::format(11537), 'Western digits, as every other number of the page');
    }

    public function test_the_short_count_word_list_matches_the_front_end_copy(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/utils/format.ts'));

        foreach (LocalNumber::UNITS as $language => $units) {
            $line = $language.': ['.implode(', ', array_map(fn (array $unit): string => '['.$unit[0].", '".$unit[1]."']", $units)).'],';
            $this->assertStringContainsString($line, $source, 'resources/js/utils/format.ts COMPACT_UNITS for '.$language);
        }
    }
}
