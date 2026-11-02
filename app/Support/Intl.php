<?php

namespace App\Support;

/**
 * Whether PHP's intl extension is really loaded.
 *
 * class_exists(\NumberFormatter::class) is not enough: on a host without intl the bundled symfony/polyfill-intl-icu
 * defines NumberFormatter and IntlDateFormatter, but they support only the "en" locale and throw for every other one
 * ("en@numbers=latn" included), so the pages that print a number answered 500 on such a host (2 Nov 2026: a demo site
 * without intl right after the update to 1.2.3). Every intl formatter of the site asks this first and otherwise uses
 * its own fallback (CLDR tables, number_format()).
 */
final class Intl
{
    /** Tests only: force the answer (null = ask PHP). */
    public static ?bool $forced = null;

    public static function available(): bool
    {
        return self::$forced ?? extension_loaded('intl');
    }
}
