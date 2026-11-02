<?php

namespace App\Notifications\Support;

use App\Models\User;

/**
 * The live push of bell notifications (the bell shakes without a reload). It needs a real broadcaster: Pusher, Ably,
 * Redis/Reverb. With the installer's default ("log") or "null", a push only wrote one log line and one queued job per
 * notification, so the site sends none then: the bell shows new notifications on the next page or when it is opened.
 */
final class Realtime
{
    /** Whether the site has a live broadcaster. */
    public static function enabled(): bool
    {
        $default = (string) config('broadcasting.default', 'null');
        $driver = (string) config('broadcasting.connections.'.$default.'.driver', $default);

        return $driver !== '' && ! in_array($driver, ['log', 'null'], true);
    }

    /**
     * The channels of a bell notification: $channels, plus the live push when the site has one.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    public static function channels(array $channels = ['database']): array
    {
        return self::enabled() ? [...$channels, 'broadcast'] : $channels;
    }

    /** The language a member reads the site in (their saved choice when the site offers it), else the site's. */
    public static function localeOf(?User $user): string
    {
        $language = $user?->language;
        $languages = (array) config('languages', []);

        return is_string($language) && $language !== '' && array_key_exists($language, $languages)
            ? $language
            : (string) app()->getLocale();
    }
}
