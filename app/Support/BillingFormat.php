<?php

namespace App\Support;

use App\Models\Plan;
use App\Support\Studio\StudioFormat;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use NumberFormatter;
use Throwable;

/**
 * Dates and amounts of Premium and its invoices, wherever a member reads them: the Premium page, Account settings >
 * Premium (the plan card and the latest invoices), the Invoices list and the invoice PDF (R2A-038). One invoice reads one
 * way on every page, in the member's language, the way the studio writes them (1.2.3 round 1, R1-112):
 *  - a date is StudioFormat::date(): the admin's "Date format"; the written-month default follows the reader's language
 *    ("Nov 26, 2026", "26 nov. 2026", "26 Kas 2026", "26 نوفمبر 2026"), a numeric format stays the admin's order, in the
 *    site's time zone. Before, these pages wrote the English order with translated months ("Kas 26, 2026");
 *  - an amount is the site's currency written the way the language writes money (NumberFormat::money: "€5.00",
 *    "5,00 €", "€5,00", "‏5.00 €"), as the member e-mails do (R1-133). Before, "€5.00" in every language. The admin's
 *    money pages keep PremiumAvailability::money() (the owner's own bookkeeping form, outside this change).
 */
final class BillingFormat
{
    /** @var array<string, \IntlDateFormatter|false> month + year formatters per language and time zone */
    private static array $monthFormatters = [];

    /** A date the studio's way: "Nov 26, 2026" / "26 nov. 2026" / "05/10/2026" ('' for no date). */
    public static function date(?CarbonInterface $date): string
    {
        return StudioFormat::date($date);
    }

    /**
     * A month and its year in the reader's language and its own order, in the site's time zone: "October 2026",
     * "octubre de 2026", "2026年10月", "2026년 10월", "tháng 10 năm 2026" (the invoice titles, "Joined ..."). The English
     * order with a translated month ("10月 2026", Carbon's "F Y") is the fallback without the intl extension.
     */
    public static function month(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $local = StudioFormat::local($date);
        $locale = (string) App::getLocale();
        $zone = $local->getTimezone()->getName();
        $key = $locale.'|'.$zone;

        if (! array_key_exists($key, self::$monthFormatters)) {
            self::$monthFormatters[$key] = false;

            if (class_exists(\IntlDatePatternGenerator::class) && class_exists(\IntlDateFormatter::class)) {
                try {
                    $generator = \IntlDatePatternGenerator::create($locale);
                    $pattern = $generator instanceof \IntlDatePatternGenerator ? $generator->getBestPattern('yMMMM') : false;
                    $formatter = is_string($pattern) && $pattern !== ''
                        ? \IntlDateFormatter::create($locale.'@numbers=latn;calendar=gregorian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, \IntlDateFormatter::GREGORIAN, $pattern)
                        : null;
                    self::$monthFormatters[$key] = $formatter instanceof \IntlDateFormatter ? $formatter : false;
                } catch (Throwable) {
                    self::$monthFormatters[$key] = false;
                }
            }
        }

        $formatter = self::$monthFormatters[$key];
        $out = $formatter !== false ? $formatter->format($local->getTimestamp()) : false;

        return is_string($out) && $out !== '' ? $out : $local->translatedFormat('F Y');
    }

    /**
     * An amount in the currency's smallest unit (cents; whole units for zero-decimal currencies such as JPY) with the
     * site's currency, in the reader's language ($locale, else the page's). $precision null: whole amounts without
     * decimals ("€5", "5 €"), others with two; 2: always two ("€5.00", the invoices).
     */
    public static function money(int|float $cents, ?int $precision = null, ?string $locale = null): string
    {
        return NumberFormat::money($cents, PremiumAvailability::currency(), $locale ?? (string) App::getLocale(), $precision);
    }

    /** A plan's price for a member: "€5", "5 €", "‏5 €". */
    public static function price(Plan $plan, ?int $precision = null): string
    {
        return self::money((int) $plan->getRawOriginal('price'), $precision);
    }

    /**
     * A tax rate as the language writes a percentage: "19%", "19 %" (French, German), "%19" (Turkish), "7,5 %". Western
     * digits; at most two decimals, none for a whole rate.
     */
    public static function percent(float $rate, ?string $locale = null): string
    {
        $language = LocalNumber::language($locale);

        if (class_exists(NumberFormatter::class)) {
            try {
                $formatter = new NumberFormatter($language.'@numbers=latn', NumberFormatter::PERCENT);
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
                $formatted = $formatter->format($rate / 100);

                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (Throwable) {
                // the plain form below
            }
        }

        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * The replacements of a translated sentence with a date (or any value) in it. The caller translates the sentence
     * WITHOUT replacements (so the translation tools still see its key) and passes the result here with the replacements,
     * as the invoice PDF does with its "Paid on :date." line (resources/views/users/invoice.blade.php). A value
     * that ends with its own full stop ("26 нояб. 2026 г.", a German "Nov.") keeps only that one where the sentence puts
     * its full stop right after it (":date." in the translated line), never "г..". Laravel's own replacements otherwise
     * (":Date" capitalised, ":DATE" in capitals).
     *
     * @param  array<string, mixed>  $replace
     */
    public static function fill(string $line, array $replace = []): string
    {
        $tokens = [];

        foreach ($replace as $name => $value) {
            $value = (string) $value;
            $tokens[':'.Str::ucfirst((string) $name)] = Str::ucfirst($value);
            $tokens[':'.Str::upper((string) $name)] = Str::upper($value);
            $tokens[':'.$name] = $value;

            if (str_ends_with($value, '.')) {
                // strtr() takes the longest match first: ":date." before ":date"
                $tokens[':'.$name.'.'] = $value;
            }
        }

        return strtr($line, $tokens);
    }

    /**
     * Run $callback with the app in $locale (the invoice PDF is written in the member's language whatever the page or
     * process that makes it), then put the previous language back.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inLocale(string $locale, Closure $callback): mixed
    {
        $previous = (string) App::getLocale();

        if ($locale === '' || $locale === $previous) {
            return $callback();
        }

        try {
            App::setLocale($locale);

            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
