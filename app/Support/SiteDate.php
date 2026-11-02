<?php

namespace App\Support;

use App\Support\Studio\StudioFormat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Dates on the channel, library and playlist pages, written in the admin's "Date format" (member design 6.0 and
 * section 7; config site.date_format: M d, Y | d/m/Y | Y-m-d | d.m.Y | m/d/Y), with month and weekday names in the
 * reader's language and in the site's time zone (config app.timezone).
 *
 * The rules are the studio's (App\Support\Studio\StudioFormat::date / shortDate), so a site writes a date the
 * same way on every member page: the default "M d, Y" prints "Oct 5, 2026" (the day without its leading zero), a date
 * without its year follows the order of the admin's format ("Oct 5" for the formats that start with the month, "5 Oct"
 * for the others), and a format the site does not offer falls back to the default.
 *
 * 1.2.3 round 3 (R3-062): the written-month default follows the reader's language, as the studio's dates have since
 * round 1 (R1-112): "Oct 28" / "28. Okt." / "28 oct." / "28 окт." / "28 أكتوبر" (the English order with translated
 * month names in every language before: "Aktualisiert am Okt 28", "Mise à jour le oct. 26, 2026"). The numeric formats
 * are the admin's explicit choice of order and stay as they are in every language.
 */
final class SiteDate
{
    /** The formats the admin can choose ("Date format", Settings > Language and region). */
    public const FORMATS = ['M d, Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'];

    public const DEFAULT_FORMAT = 'M d, Y';

    /** $date in the site's time zone, with the reader's language for month and weekday names. */
    public static function local(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->locale((string) app()->getLocale());
    }

    /** The admin's format, as PHP date() letters ("M j, Y" for the default: "Oct 5, 2026"). */
    public static function format(): string
    {
        $format = (string) config('site.date_format', self::DEFAULT_FORMAT);
        $format = in_array($format, self::FORMATS, true) ? $format : self::DEFAULT_FORMAT;

        return $format === 'M d, Y' ? 'M j, Y' : $format;
    }

    /** A full date: "Oct 5, 2026" ("5 oct. 2026" in French), "05/10/2026", "2026-10-05" ... ('' for no date). */
    public static function date(?CarbonInterface $date): string
    {
        return StudioFormat::date($date);
    }

    /** A date without its year: "Oct 5" ("5. Okt." in German) with the default, else in the order of the admin's format. */
    public static function shortDate(?CarbonInterface $date): string
    {
        return StudioFormat::shortDate($date);
    }

    /**
     * The month and the year in the reader's language and order: "October 2026", "octobre 2026", "2026年10月" (a channel's
     * "Joined"; it was the English order in every language, "10月 2026"). Without intl: the month's name, then the year.
     */
    public static function monthYear(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $local = self::local($date);

        if (class_exists(\IntlDatePatternGenerator::class) && class_exists(\IntlDateFormatter::class)) {
            try {
                $locale = (string) app()->getLocale();
                $pattern = \IntlDatePatternGenerator::create($locale)?->getBestPattern('yMMMM');
                $formatter = is_string($pattern) && $pattern !== ''
                    ? \IntlDateFormatter::create($locale.'@numbers=latn;calendar=gregorian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $local->getTimezone(), \IntlDateFormatter::GREGORIAN, $pattern)
                    : null;
                $out = $formatter instanceof \IntlDateFormatter ? $formatter->format($local->getTimestamp()) : false;

                if (is_string($out) && $out !== '') {
                    return $out;
                }
            } catch (Throwable) {
                // the English order below
            }
        }

        return $local->translatedFormat('F Y');
    }

    /** The short date in the current year, the full date in any other year. */
    public static function dayDate(?CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        if ($date === null) {
            return '';
        }

        $local = self::local($date);
        $today = self::local($now ?? now());

        return $local->year === $today->year ? self::shortDate($local) : self::date($local);
    }
}
