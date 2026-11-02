<?php

namespace App\Support\Studio;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use NumberFormatter;
use Throwable;

/**
 * Numbers and dates on studio pages (member design section 7, perf-19).
 *
 * - Numbers are plain figures in the member's language with Western digits ("14,260", "14 260" in French), never raw
 *   ("2000605") and never abbreviated in the studio (the abbreviated "14K" belongs to public cards).
 * - Dates follow the admin's "Date format" (config site.date_format: M d, Y | d/m/Y | Y-m-d | d.m.Y | m/d/Y), month
 *   names in the member's language; a short date without the year ("Oct 5" / "5 Oct") follows the same order. The
 *   written-month default follows the member's language order ("5 oct. 2026" in French; 1.2.3 round 1, R1-112).
 * - Every date is shown in the site's time zone (config app.timezone).
 */
final class StudioFormat
{
    /** @var array<string, NumberFormatter|false> */
    private static array $formatters = [];

    /** @var array<string, \IntlDateFormatter|false> */
    private static array $dateFormatters = [];

    public static function number(int|float|null $value, int $decimals = 0): string
    {
        $value = (float) ($value ?? 0);
        $formatter = self::formatter($decimals);

        if ($formatter !== null) {
            $out = $formatter->format($decimals === 0 ? round($value) : $value);

            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return number_format($value, $decimals, '.', ',');
    }

    /** "96%" (a whole percentage of a part of a total; null when the total is 0). */
    public static function percent(int|float $part, int|float $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        return (int) round(100 * $part / $total);
    }

    /** "+209%" / "-30%" change between two periods (null when the earlier one is 0). */
    public static function delta(int|float $now, int|float $before): ?int
    {
        if ($before <= 0) {
            return null;
        }

        return (int) round(100 * ($now - $before) / $before);
    }

    public static function local(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(ZoneLabel::zone())->locale(app()->getLocale());
    }

    /**
     * The admin's date format: "Oct 5, 2026" (M d, Y, the default), "05/10/2026", "2026-10-05" ...
     * 1.2.3 round 1 (R1-112): the default is the WRITTEN-MONTH format, and a written month follows the member's language
     * ("Oct 5, 2026", "5 oct. 2026", "5. Okt. 2026", "2026年10月5日": the language's own medium date, as the browser
     * writes it on the same pages); it was the English order in every language ("le oct. 29"). The numeric formats are
     * the admin's explicit choice of order and stay as they are in every language.
     */
    public static function date(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $format = self::adminFormat();

        if ($format === 'M d, Y') {
            // the language's order; without intl, "Oct 5, 2026" (the day without its leading zero)
            return self::pattern($date, 'yMMMd') ?? self::local($date)->translatedFormat('M j, Y');
        }

        return self::local($date)->translatedFormat($format);
    }

    /**
     * A date without its year: in the member's language order with the written-month format ("Oct 5", "5 oct.",
     * "10月5日"), else in the order of the admin's numeric format ("Oct 5" for the formats that start with the month,
     * "5 Oct" for the others).
     */
    public static function shortDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $format = self::adminFormat();

        if ($format === 'M d, Y') {
            return self::pattern($date, 'MMMd') ?? self::local($date)->translatedFormat('M j');
        }

        return self::local($date)->translatedFormat(str_starts_with($format, 'm') ? 'M j' : 'j M');
    }

    /**
     * A translated sentence with a date in it, with one full stop where the date ends the sentence. A short date can end
     * with its month's abbreviation point ("22 oct." in French, "22 de out." in Portuguese, "22. Okt." in German,
     * "22 окт." in Russian) and the sentence adds its own ("... le 22 oct.."): as in print, the two are one (1.2.3 round
     * 2, R2A-023). Only that point is touched: the sentence and the date are otherwise as they were.
     */
    public static function endingWith(string $sentence, string $date): string
    {
        if ($date === '' || ! str_ends_with($date, '.')) {
            return $sentence;
        }

        return str_replace($date.'.', $date, $sentence);
    }

    /** The admin's "Date format" (config site.date_format), one of the five the admin offers (else the default). */
    private static function adminFormat(): string
    {
        $format = (string) config('site.date_format', 'M d, Y');

        return in_array($format, ['M d, Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'], true) ? $format : 'M d, Y';
    }

    /**
     * $date in the site's time zone, written with the member's language's best pattern for an ICU skeleton ("yMMMd": a
     * medium date, "MMMd": day and short month), Western digits and the Gregorian calendar (as every studio number and
     * date); null without intl.
     */
    private static function pattern(CarbonInterface $date, string $skeleton): ?string
    {
        if (! class_exists(\IntlDatePatternGenerator::class) || ! class_exists(\IntlDateFormatter::class)) {
            return null;
        }

        $locale = (string) app()->getLocale();
        $zone = ZoneLabel::zone()->getName();
        $key = $locale.'|'.$zone.'|'.$skeleton;

        if (! array_key_exists($key, self::$dateFormatters)) {
            try {
                $generator = \IntlDatePatternGenerator::create($locale);
                $best = $generator instanceof \IntlDatePatternGenerator ? $generator->getBestPattern($skeleton) : false;
                $formatter = is_string($best) && $best !== ''
                    ? \IntlDateFormatter::create($locale.'@numbers=latn;calendar=gregorian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, \IntlDateFormatter::GREGORIAN, $best)
                    : null;
                self::$dateFormatters[$key] = $formatter instanceof \IntlDateFormatter ? $formatter : false;
            } catch (Throwable) {
                self::$dateFormatters[$key] = false;
            }
        }

        $formatter = self::$dateFormatters[$key];

        if ($formatter === false) {
            return null;
        }

        $out = $formatter->format($date->getTimestamp());

        return is_string($out) && $out !== '' ? $out : null;
    }

    /** The short date, with the year when it is not the current one. */
    public static function dayLabel(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $local = self::local($date);

        return $local->year === CarbonImmutable::now(ZoneLabel::zone())->year ? self::shortDate($local) : self::date($local);
    }

    /** "Today, 09:04" / "Oct 24" / "Oct 5, 2025": a list's date column. */
    public static function listDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $local = self::local($date);

        if ($local->isSameDay(CarbonImmutable::now(ZoneLabel::zone()))) {
            return __('Today, :time', ['time' => $local->format('H:i')]);
        }

        return self::date($local);
    }

    /** "5 hours ago", in the member's language (activity, comments, subscribers). */
    public static function ago(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return self::local($date)->diffForHumans();
    }

    /** Hours and minutes in the site's zone: "13:05". */
    public static function time(?CarbonInterface $date): string
    {
        return $date === null ? '' : self::local($date)->format('H:i');
    }

    /**
     * An amount of money from its cents, with the currency's symbol where the member's language puts it ("€5.00",
     * "5,00 €"), Western digits. Without intl: "EUR 5.00".
     */
    public static function money(int|float|null $cents, string $currency = 'EUR'): string
    {
        $amount = ((float) ($cents ?? 0)) / 100;
        $currency = strtoupper(trim($currency)) ?: 'EUR';

        if (class_exists(NumberFormatter::class)) {
            try {
                $formatter = NumberFormatter::create(app()->getLocale().'@numbers=latn', NumberFormatter::CURRENCY);
                $out = $formatter instanceof NumberFormatter ? $formatter->formatCurrency($amount, $currency) : false;

                if (is_string($out) && $out !== '') {
                    return $out;
                }
            } catch (Throwable) {
                // the plain form below
            }
        }

        return $currency.' '.number_format($amount, 2, '.', ',');
    }

    /** A month and its year in the member's language, in the site's time zone: "September 2026". */
    public static function month(?CarbonInterface $date): string
    {
        return $date === null ? '' : self::local($date)->locale(app()->getLocale())->translatedFormat('F Y');
    }

    /**
     * A signed figure ("+18", "-5", "+209%", "-30%") as HTML that keeps its sign in front in every direction: in an
     * Arabic sentence "+12" otherwise reads "12+" and "-30%" "30%-" (the sign and the percent sign take the
     * paragraph's direction). The figure is escaped and isolated left to right; print it with {!! !!}.
     */
    public static function signedHtml(int|float $value, bool $percent = false): string
    {
        $text = ($value > 0 ? '+' : '').self::number($value).($percent ? '%' : '');

        return '<bdi dir="ltr">'.e($text).'</bdi>';
    }

    /**
     * Line breaks as one "\n" each. A browser posts a textarea's line breaks as CRLF, while its value, its maxlength
     * and the page's counter count each one as a single character; stored as CRLF, a no-op save changed the text and a
     * description the browser allowed could fail the server's max length.
     */
    public static function lineBreaks(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * A video's status as the kit shows it (member design 5.8): [kind, label]. The Upload18 processing state
     * ("Processing on Upload18"), any other upload still being processed ("Processing"), a scheduled video whose time
     * has come is public (as Video::real_status says), failed and banned keep their own kinds (the kit draws a
     * triangle). Used by <x-mk.status :video> and by the cached dashboard lists (plain values).
     *
     * @return array{0: string, 1: string}
     */
    public static function status(\App\Models\Video $video): array
    {
        $raw = $video->status;
        $value = $raw instanceof \BackedEnum ? (int) $raw->value : (int) $raw;
        $failed = $value === \App\Enums\VideoStatus::FAILED->value;
        $upload18Processing = ! $failed && class_exists(\App\Support\Upload18\UploadClaims::class) && \App\Support\Upload18\UploadClaims::isProcessing($video);
        $processing = ! $failed && ($upload18Processing || (bool) ($video->is_uploading ?? false));
        $realValue = $value === \App\Enums\VideoStatus::PLANNED->value && $video->scheduled_at !== null && $video->scheduled_at->lte(now())
            ? \App\Enums\VideoStatus::PUBLIC->value
            : $value;

        return match (true) {
            $upload18Processing => ['processing', __('Processing on Upload18')],
            $processing => ['processing', __('Processing')],
            $realValue === \App\Enums\VideoStatus::PUBLIC->value => ['public', __('Public')],
            $realValue === \App\Enums\VideoStatus::PRIVATE->value => ['private', __('Private')],
            $realValue === \App\Enums\VideoStatus::PLANNED->value => ['scheduled', __('Scheduled')],
            $realValue === \App\Enums\VideoStatus::UNLISTED->value => ['unlisted', __('Unlisted')],
            $realValue === \App\Enums\VideoStatus::DRAFT->value => ['draft', __('Draft')],
            $realValue === \App\Enums\VideoStatus::BANNED->value => ['banned', __('Banned')],
            $realValue === \App\Enums\VideoStatus::FAILED->value => ['failed', __('Upload failed')],
            default => ['private', __('Private')],
        };
    }

    private static function formatter(int $decimals): ?NumberFormatter
    {
        if (! class_exists(NumberFormatter::class)) {
            return null;
        }

        $locale = (string) app()->getLocale();
        $key = $locale.'|'.$decimals;

        if (! array_key_exists($key, self::$formatters)) {
            try {
                $formatter = NumberFormatter::create($locale.'@numbers=latn', NumberFormatter::DECIMAL);

                if ($formatter instanceof NumberFormatter) {
                    $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
                    $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
                }

                self::$formatters[$key] = $formatter ?: false;
            } catch (Throwable) {
                self::$formatters[$key] = false;
            }
        }

        return self::$formatters[$key] ?: null;
    }
}
