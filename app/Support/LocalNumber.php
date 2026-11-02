<?php

namespace App\Support;

/**
 * A whole number written with the reader's thousands separator: "184,312" in English, "184.312" in German (QA
 * channel-16: the channel's About printed "6552004 views"). Uses PHP's intl extension when the host has it, else the
 * English separators.
 *
 * 1.2.3 (R1-063): the short counts of the cards ("18.8 k views") are the reader's too: the decimal separator of the
 * language ("18,8 k vues") and its own word for thousands, millions, ... ("18.8 ألف مشاهدات": "18.8 k" inside Arabic text
 * read "k 18.8"). resources/js/utils/format.ts formatCompactNumber prints the same for the cards the page draws itself.
 */
final class LocalNumber
{
    /**
     * The short units of each language (CLDR "short" decimal formats), largest first: [power of ten, word]. A number
     * under the smallest unit is written in full ("1,234" in Japanese and Chinese, which count in ten thousands).
     * Keep resources/js/utils/format.ts COMPACT_UNITS the same.
     */
    public const UNITS = [
        'en' => [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'k']],
        'ar' => [[12, 'ترليون'], [9, 'مليار'], [6, 'مليون'], [3, 'ألف']],
        'de' => [[12, 'Bio.'], [9, 'Mrd.'], [6, 'Mio.'], [3, 'Tsd.']],
        'es' => [[12, 'B'], [9, 'mil M'], [6, 'M'], [3, 'mil']],
        'fil' => [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'K']],
        'fr' => [[12, 'Bn'], [9, 'Md'], [6, 'M'], [3, 'k']],
        'hi' => [[9, 'अ॰'], [7, 'क॰'], [5, 'लाख'], [3, 'हज़ार']],
        'id' => [[12, 'T'], [9, 'M'], [6, 'jt'], [3, 'rb']],
        'ja' => [[12, '兆'], [8, '億'], [4, '万']],
        'ko' => [[12, '조'], [8, '억'], [4, '만'], [3, '천']],
        'ms' => [[12, 'T'], [9, 'B'], [6, 'J'], [3, 'K']],
        'pt' => [[12, 'tri'], [9, 'bi'], [6, 'mi'], [3, 'mil']],
        'ru' => [[12, 'трлн'], [9, 'млрд'], [6, 'млн'], [3, 'тыс.']],
        'th' => [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'K']],
        'tl' => [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'K']],
        'tr' => [[12, 'Tn'], [9, 'Mr'], [6, 'Mn'], [3, 'B']],
        'vi' => [[12, 'NT'], [9, 'T'], [6, 'Tr'], [3, 'N']],
        'zh' => [[12, '万亿'], [8, '亿'], [4, '万']],
    ];

    /** Languages that write the unit right after the number, without a space ("1.9万"). */
    private const NO_SPACE = ['ja', 'ko', 'zh'];

    /**
     * The decimal and thousands separators of each language (CLDR, Western digits) for hosts without the intl
     * extension. Keep in step with what intl prints for the same languages.
     */
    private const SEPARATORS = [
        'de' => [',', '.'], 'es' => [',', '.'], 'fr' => [',', "\u{202F}"], 'id' => [',', '.'], 'pt' => [',', '.'],
        'ru' => [',', "\u{00A0}"], 'tr' => [',', '.'], 'vi' => [',', '.'],
    ];

    public static function format(int|float|string|null $value): string
    {
        return self::decimal((int) round((float) $value), 0);
    }

    /**
     * The site's short count ("1.2 k", "41"), as the cards print it (App\Helpers\Number::humanize), without the space it
     * leaves after a number under a thousand.
     */
    public static function short(int|float|string|null $value): string
    {
        return trim(\App\Helpers\Number::humanize(max(0, (int) $value)));
    }

    /**
     * The short count in the reader's language: one decimal under 100 of the unit, rounded half up ("1.0 k", "99.4 k",
     * "100 k", "1,000 k" for 999,999: the same rounding as before 1.2.3), then the language's separators and word.
     */
    public static function compact(int|float|string|null $value, ?string $locale = null): string
    {
        $number = max(0, (int) $value);
        $language = self::language($locale);

        foreach (self::UNITS[$language] ?? self::UNITS['en'] as [$exponent, $unit]) {
            $size = 10 ** $exponent;

            if ($number >= $size) {
                $display = $number / $size;
                $decimals = round($display) < 100 ? 1 : 0;

                return self::decimal(round($display, $decimals), $decimals, $language)
                    .(in_array($language, self::NO_SPACE, true) ? '' : ' ')
                    .$unit;
            }
        }

        return self::decimal($number, 0, $language);
    }

    /** The two- or three-letter language of a locale ("pt_BR" -> "pt"); the app's locale when none is given. */
    public static function language(?string $locale = null): string
    {
        $locale = strtolower(trim((string) ($locale ?? app()->getLocale())));

        return preg_split('/[-_@]/', $locale)[0] ?: 'en';
    }

    /** A number with a fixed count of decimals (already rounded by the caller) in the language's separators. */
    private static function decimal(int|float $number, int $decimals, ?string $language = null): string
    {
        $language ??= self::language();

        if (Intl::available()) {
            try {
                // Western digits in every language, as the cards, the short counts and the studio print them (Arabic and
                // Persian would otherwise get their own digits next to Western ones on the same page)
                $formatter = new \NumberFormatter($language.'@numbers=latn', \NumberFormatter::DECIMAL);
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
                $formatted = $formatter->format($number);

                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (\Throwable) {
                // an ICU without that language's data: the plain form below, never an error on a page
            }
        }

        [$point, $thousands] = self::SEPARATORS[$language] ?? ['.', ','];

        return number_format($number, $decimals, $point, $thousands);
    }
}
