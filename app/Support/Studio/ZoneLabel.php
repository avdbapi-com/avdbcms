<?php

namespace App\Support\Studio;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;
use App\Support\Intl;

/**
 * The site's time zone as members read it next to a scheduled time (member design 6.4, OD-M12; 9.3:
 * the schedule zone label is reused in the "goes public" notification and e-mail).
 *
 * Scheduled times are entered and shown in the site's zone (config('app.timezone'), the admin's "Time zone"), and the
 * zone is always written next to them with the UTC offset OF THE CHOSEN DATE (daylight saving time: Paris is GMT+2
 * until the last Sunday of October at 03:00, GMT+1 after), e.g. "20:00 GMT+1".
 */
final class ZoneLabel
{
    /** The site's zone (a valid IANA name, else UTC). */
    public static function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) config('app.timezone', 'UTC') ?: 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** @var array<string, string|false> the city of a zone per language (ICU), false where ICU has none */
    private static array $cities = [];

    /**
     * The zone's city in the member's language: "Paris" for Europe/Paris, "باريس" in Arabic, "Париж" in Russian, "New
     * York" / "Nueva York" for America/New_York (ICU's exemplar city, the IntlDateFormatter pattern "VVV"; 1.2.3 round
     * 2, R2A-037: it was the zone id's English last part in every language). A zone without a city ("UTC", "Etc/GMT+5")
     * and a site without intl keep the zone id's last part ("UTC", "GMT+5"; "Sao Paulo" for America/Sao_Paulo).
     */
    public static function city(): string
    {
        $name = self::zone()->getName();
        $plain = str_contains($name, '/') ? str_replace('_', ' ', (string) substr($name, (int) strrpos($name, '/') + 1)) : $name;

        if (! str_contains($name, '/') || str_starts_with($name, 'Etc/')) {
            return $plain;
        }

        $local = self::exemplarCity($name, (string) app()->getLocale());

        return $local ?? $plain;
    }

    /** ICU's name of the zone's city in that language, or null (no intl, no city for that zone). */
    private static function exemplarCity(string $zone, string $locale): ?string
    {
        if (! Intl::available()) {
            return null;
        }

        $key = $locale.'|'.$zone;

        if (! array_key_exists($key, self::$cities)) {
            self::$cities[$key] = false;

            try {
                $city = self::formatCity($zone, $locale);
                // ICU answers a localised "Unknown City" for a zone it has no city for: the same words it gives a zone
                // that does not exist
                $unknown = self::formatCity('Etc/Unknown', $locale);

                if ($city !== null && $city !== $unknown && trim($city) !== '') {
                    self::$cities[$key] = trim($city);
                }
            } catch (Throwable) {
                // the zone id's own last part
            }
        }

        return self::$cities[$key] === false ? null : self::$cities[$key];
    }

    private static function formatCity(string $zone, string $locale): ?string
    {
        $formatter = \IntlDateFormatter::create($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, \IntlDateFormatter::GREGORIAN, 'VVV');
        $out = $formatter instanceof \IntlDateFormatter ? $formatter->format(0) : false;

        return is_string($out) && $out !== '' ? $out : null;
    }

    /** "GMT+1", "GMT+5:30", "GMT-3", "GMT" for the offset the site's zone has at that moment. */
    public static function offset(CarbonInterface $at): string
    {
        $seconds = CarbonImmutable::instance($at)->setTimezone(self::zone())->getOffset();

        if ($seconds === 0) {
            return 'GMT';
        }

        $sign = $seconds > 0 ? '+' : '-';
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return 'GMT'.$sign.$hours.($minutes > 0 ? ':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');
    }

    /** "20:00 GMT+1": the time in the site's zone with the zone's offset on that date. */
    public static function for(CarbonInterface $at): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone(self::zone());

        return $local->format('H:i').' '.self::offset($local);
    }

    /**
     * "Paris time (GMT+1 on 1 Nov).": the sentence under the date and time fields, with the full stop of the member's
     * language ("パリ時間（11月1日 は GMT+1）。" in Japanese and Chinese, "।" in Hindi, none in Thai; 1.2.3 round 2: a Latin
     * "." was added after it in every language).
     */
    public static function zoneLine(CarbonInterface $date): string
    {
        return __(':city time (:offset on :date).', [
            'city' => self::city(),
            'offset' => self::offset($date),
            'date' => StudioFormat::shortDate($date),
        ]);
    }

    /**
     * What goes between a sentence and the next one in the member's language (1.2.3 round 3, R3-046): nothing after a
     * full-width stop ("。", "！", "？": Japanese and Chinese put no space there), else a space ("।" in Hindi, "." in the
     * Latin-script languages; Thai's sentence has no stop and takes the space too).
     */
    public static function gapAfter(string $sentence): string
    {
        return preg_match('/[\x{3002}\x{FF01}\x{FF1F}\x{FF0E}]\s*$/u', $sentence) === 1 ? '' : ' ';
    }

    /** "It is 14:32 there now." */
    public static function nowLine(?CarbonInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? now())->setTimezone(self::zone());

        return __('It is :time there now.', ['time' => $now->format('H:i')]);
    }

    /** "Goes public 1 Nov 2026, 20:00 GMT+1". */
    public static function goesPublic(CarbonInterface $at, bool $withYear = true): string
    {
        $local = CarbonImmutable::instance($at)->setTimezone(self::zone());

        return __('Goes public :date, :time', [
            'date' => $withYear ? StudioFormat::date($local) : StudioFormat::shortDate($local),
            'time' => self::for($local),
        ]);
    }
}
