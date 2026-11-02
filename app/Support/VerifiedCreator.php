<?php

namespace App\Support;

use App\Models\User;

/**
 * The check mark next to a channel name ("Verified check mark", creator_verified_badge_enabled: "Shows a check mark next
 * to verified creators"). It used to be printed for every channel while the switch was on, so a brand-new member looked
 * verified too, and the watch page's "Up next" card printed it even with the switch off (watch-24). A channel counts as
 * verified when the site has checked who runs it: its owner confirmed a phone number (phone verification) or is one of
 * the site's administrators.
 */
final class VerifiedCreator
{
    public static function enabled(): bool
    {
        return filter_var(config('site.creator_verified_badge_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function is(?User $user): bool
    {
        if ($user === null || ! self::enabled()) {
            return false;
        }

        return (bool) $user->is_admin || $user->hasVerifiedPhone();
    }
}
