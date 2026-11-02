<?php

namespace App\Support;

/**
 * Whether the site can send text messages, so it may ask members for a phone number (member design 6.17,
 * account-11).
 *
 * Phone codes go out through Brevo (App\Channels\SmsChannel -> App\Services\BrevoService), whose key is only in the
 * .env file (BREVO_API_KEY): a new site has none. Without it the phone field is hidden and no code is ever promised;
 * a number a member saved earlier can still be removed. On a local development install the code is e-mailed instead
 * (App\Notifications\Account\VerifyPhone), so the field shows there too.
 *
 * The phone verification controller and its queued VerifyPhone notification read the same answer (9.3).
 */
final class SmsAvailability
{
    public static function canSend(): bool
    {
        return trim((string) config('services.brevo.api_key', '')) !== '' || app()->isLocal();
    }
}
