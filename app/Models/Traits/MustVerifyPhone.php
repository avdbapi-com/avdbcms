<?php

namespace App\Models\Traits;

use App\Notifications\Account\VerifyPhone;
use App\Support\SerialAttempts;
use App\Support\SmsAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * A member's phone number and its text-message code (member design 6.17).
 *
 * The number is optional and can always be removed (account-11). A code is only ever sent when the site can send text
 * messages (App\Support\SmsAvailability). It has 5 digits and works for 10 minutes; after 5 wrong tries it stops
 * working (the right code too) and the page offers a new one when it would have expired. New codes: one a minute (the
 * "Send a new code" route allows one a minute) and at most PHONE_CODES_PER_HOUR an hour per member, also when the
 * number is changed back and forth (text messages cost the site money); a new code starts with all its tries.
 *
 * ONE state for the whole flow (9.3), shared with the phone verification controller
 * (App\Http\Controllers\Auth\PhoneVerification): the code itself in users.phone_confirmation_code, and in the cache
 *   - phone-code.sent.<id>: when the code was sent (it expires with the code, after 10 minutes),
 *   - phone-code.tries.<id>: the wrong tries of that code.
 * A code sent by a Profile save is the same as one sent by "Send a new code", so the Profile's Verify form works with
 * either controller; checkPhoneCode() and phoneCodeMessage() give the same answers as that controller.
 */
trait MustVerifyPhone
{
    /** How long a code works, in minutes. */
    public const PHONE_CODE_MINUTES = 10;

    /** Wrong tries a code allows. */
    public const PHONE_CODE_TRIES = 5;

    /** Codes a member can get in one hour (Profile saves and "Send a new code" together). */
    public const PHONE_CODES_PER_HOUR = 5;

    /** The wait between two codes, in seconds (the "Send a new code" route's own limit is one a minute). */
    public const PHONE_CODE_RESEND_WAIT = 60;

    /**
     * Determine if the user has verified their phone number.
     *
     * @return bool
     */
    public function hasVerifiedPhone(): bool
    {
        return ! is_null($this->phone_verified_at);
    }

    /**
     * Mark the given user's phone as verified.
     *
     * @return bool
     */
    public function markPhoneAsVerified(): bool
    {
        $this->forgetPhoneCodeState();

        return $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
            'phone_confirmation_code' => null,
        ])->save();
    }

    /**
     * Send a new phone verification code: false (nothing sent, the code waiting before keeps working) when the site
     * cannot send text messages, the member has no number, or the member already got PHONE_CODES_PER_HOUR codes this
     * hour (phoneCodeSendBlockedSeconds() says for how long). A new code replaces the old one and starts with all its
     * tries. The notification is queued, so a save never waits for the text-message service.
     *
     * @return bool whether a code was sent
     */
    public function sendPhoneVerificationNotification(): bool
    {
        if (! SmsAvailability::canSend() || ! $this->getPhone()) {
            return false;
        }

        $budget = $this->phoneCodeBudgetKey();

        if (RateLimiter::tooManyAttempts($budget, self::PHONE_CODES_PER_HOUR)) {
            return false;
        }

        RateLimiter::hit($budget, 3600);

        $this->forceFill([
            'phone_confirmation_code' => str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
            'phone_verified_at' => null,
        ])->save();

        try {
            Cache::put($this->phoneCodeSentKey(), now()->getTimestamp(), now()->addMinutes(self::PHONE_CODE_MINUTES));
            Cache::forget($this->phoneCodeTriesKey());
        } catch (Throwable) {
            // A cache that cannot be reached: the code is still sent; checking it then says it has expired.
        }

        $this->notify(new VerifyPhone);

        return true;
    }

    /**
     * Remove the member's number (always possible: account-11).
     */
    public function clearPhone(): void
    {
        $this->forgetPhoneCodeState();

        $this->forceFill([
            'phone' => null,
            'phone_verified_at' => null,
            'phone_confirmation_code' => null,
        ])->save();
    }

    /**
     * Check a code the member typed: 'verified', 'wrong' (a try is used), 'locked' (5 wrong tries: this code no longer
     * works, send a new one), 'expired' (older than 10 minutes), 'none' (no code was sent) or 'busy' (another check of
     * this member is running and did not end in time: nothing was compared).
     *
     * One check at a time per member, shared with the phone verification controller (R1-164): the wrong tries are read,
     * compared and written under the member's lock (App\Support\SerialAttempts), so codes sent side by side cannot all
     * pass the count of 5.
     */
    public function checkPhoneCode(mixed $code): string
    {
        return SerialAttempts::run($this->phoneCodeCheckKey(), fn (): string => $this->checkPhoneCodeNow($code), fn (): string => 'busy');
    }

    /** The lock key of this member's code checks (the controller and checkPhoneCode() share it). */
    public function phoneCodeCheckKey(): string
    {
        return 'phone-code-check:'.$this->getKey();
    }

    private function checkPhoneCodeNow(mixed $code): string
    {
        if (! $this->waitForPhoneCode()) {
            return 'none';
        }

        if ($this->phoneCodeExpired()) {
            return 'expired';
        }

        if ($this->phoneCodeTriesLeft() <= 0) {
            return 'locked';
        }

        if (is_string($code) && hash_equals((string) $this->getPhoneCodeVerification(), trim($code))) {
            $this->markPhoneAsVerified();

            return 'verified';
        }

        $tries = $this->phoneCodeWrongTries() + 1;

        try {
            Cache::put($this->phoneCodeTriesKey(), $tries, now()->addSeconds(max(1, $this->phoneCodeExpiresIn())));
        } catch (Throwable) {
            // without a cache every code has expired anyway (phoneCodeExpired())
        }

        return $tries >= self::PHONE_CODE_TRIES ? 'locked' : 'wrong';
    }

    /**
     * The member-facing sentence for a result of checkPhoneCode() (translated), word for word what the phone verification controller
     * answers: after 5 wrong tries, a new code when the used-up one would have expired.
     */
    public function phoneCodeMessage(string $status): string
    {
        $left = $this->phoneCodeTriesLeft();
        $minutes = max(1, (int) ceil($this->phoneCodeExpiresIn() / 60));

        return match ($status) {
            'verified' => __('Your phone number is verified.'),
            'wrong' => trans_choice(
                '{1} That code is wrong. Check the message and try again. :count try left.|[2,*] That code is wrong. Check the message and try again. :count tries left.',
                $left,
                ['count' => $left]
            ),
            'expired' => __('This code has expired. Send a new one.'),
            'busy' => __('Too many tries. Wait a minute and try again.'),
            'locked' => trans_choice(
                '{1} Too many tries. Send a new code in :count minute.|[2,*] Too many tries. Send a new code in :count minutes.',
                $minutes,
                ['count' => $minutes]
            ),
            default => __('Send a code to your phone first.'),
        };
    }

    /** The sentence for a code that could not be sent because of the hourly limit (translated). */
    public function phoneCodeLimitMessage(): string
    {
        $minutes = max(1, (int) ceil($this->phoneCodeSendBlockedSeconds() / 60));

        return trans_choice(
            '{1} No code was sent: you asked for too many codes. Try again in :count minute.|[2,*] No code was sent: you asked for too many codes. Try again in :count minutes.',
            $minutes,
            ['count' => $minutes]
        );
    }

    /** When the current code was sent, or null (no code, it has expired, or it was sent before 1.2.3). */
    public function phoneCodeSentAt(): ?Carbon
    {
        try {
            $sentAt = Cache::get($this->phoneCodeSentKey());
        } catch (Throwable) {
            $sentAt = null;
        }

        return is_numeric($sentAt) ? Carbon::createFromTimestamp((int) $sentAt) : null;
    }

    /** A code works for 10 minutes; a code without a known send time (sent before 1.2.3, a lost cache) has expired. */
    public function phoneCodeExpired(): bool
    {
        return $this->phoneCodeExpiresIn() <= 0;
    }

    /** Seconds the current code still works (0: none or expired). */
    public function phoneCodeExpiresIn(): int
    {
        $sentAt = $this->phoneCodeSentAt();

        return $sentAt === null ? 0 : max(0, $sentAt->getTimestamp() + self::PHONE_CODE_MINUTES * 60 - now()->getTimestamp());
    }

    public function phoneCodeTriesLeft(): int
    {
        return max(0, self::PHONE_CODE_TRIES - $this->phoneCodeWrongTries());
    }

    /**
     * Seconds the page waits before it offers "Send a new code" (0: now): one code a minute, the hourly limit, and after
     * 5 wrong tries the rest of the used-up code's 10 minutes (what "Too many tries. Send a new code in 9 minutes."
     * says).
     */
    public function phoneCodeResendSeconds(): int
    {
        $sentAt = $this->phoneCodeSentAt();
        $minute = $sentAt === null ? 0 : max(0, self::PHONE_CODE_RESEND_WAIT - (now()->getTimestamp() - $sentAt->getTimestamp()));
        $usedUp = $this->waitForPhoneCode() && $this->phoneCodeTriesLeft() <= 0 ? $this->phoneCodeExpiresIn() : 0;

        return max($minute, $usedUp, $this->phoneCodeSendBlockedSeconds());
    }

    /** Seconds before the hourly limit lets another code go out (0: a code can be sent). */
    public function phoneCodeSendBlockedSeconds(): int
    {
        try {
            $budget = $this->phoneCodeBudgetKey();

            return RateLimiter::tooManyAttempts($budget, self::PHONE_CODES_PER_HOUR) ? max(1, RateLimiter::availableIn($budget)) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    public static function phoneCodeMinutes(): int
    {
        return self::PHONE_CODE_MINUTES;
    }

    public static function phoneCodeTries(): int
    {
        return self::PHONE_CODE_TRIES;
    }

    /** The cache key the phone verification controller keeps the send time under (shared state, see the class comment). */
    public function phoneCodeSentKey(): string
    {
        return 'phone-code.sent.'.$this->getKey();
    }

    /** The cache key the phone verification controller counts the wrong tries under (shared state, see the class comment). */
    public function phoneCodeTriesKey(): string
    {
        return 'phone-code.tries.'.$this->getKey();
    }

    private function phoneCodeBudgetKey(): string
    {
        return 'phone-code.hourly.'.$this->getKey();
    }

    private function phoneCodeWrongTries(): int
    {
        try {
            $tries = Cache::get($this->phoneCodeTriesKey(), 0);
        } catch (Throwable) {
            $tries = 0;
        }

        return is_numeric($tries) ? max(0, (int) $tries) : 0;
    }

    private function forgetPhoneCodeState(): void
    {
        try {
            Cache::forget($this->phoneCodeSentKey());
            Cache::forget($this->phoneCodeTriesKey());
        } catch (Throwable) {
            // A cache that cannot be reached keeps nothing to forget.
        }
    }

    /**
     * Get the phone number that should be used for verification.
     *
     * @return PhoneNumber|null
     */
    public function getPhone(): ?PhoneNumber
    {
        return $this->phone;
    }

    /**
     * Get the phone code that should be used for verification.
     *
     * @return string|null
     */
    public function getPhoneCodeVerification(): ?string
    {
        return $this->phone_confirmation_code;
    }

    /**
     * Get the phone number without prefix.
     *
     * @return string|null
     */
    public function getPhoneWithoutPrefix(): ?string
    {
        return Str::replace(' ', '', $this->getPhone()?->formatNational());
    }

    /** The number as people write it in its country ("06 12 34 56 78"), or null. */
    public function phoneNational(): ?string
    {
        try {
            return $this->getPhone()?->formatNational();
        } catch (Throwable) {
            return null;
        }
    }

    /** The number with its country code ("+33 6 12 34 56 78"), or null. */
    public function phoneInternational(): ?string
    {
        try {
            return $this->getPhone()?->formatInternational();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The country of the phone number (ISO code), else the member's own country when phones exist there, else null:
     * no country is chosen for the member (account-28 gave everybody "FR +33").
     */
    public function phoneCountry(): ?string
    {
        try {
            $country = $this->getPhone()?->getCountry();
        } catch (Throwable) {
            $country = null;
        }

        $codes = array_column((array) config('phone.countries', []), 'code');

        foreach ([$country, $this->country] as $candidate) {
            if (is_string($candidate) && in_array(strtoupper($candidate), $codes, true)) {
                return strtoupper($candidate);
            }
        }

        return null;
    }

    /**
     * Check if the user should be prompted for phone verification.
     *
     * @return bool
     */
    public function waitForPhoneCode(): bool
    {
        return ! is_null($this->getPhoneCodeVerification());
    }

    /** The phone country as JSON for older views: its flag, prefix and code (nothing chosen: empty values). */
    public function jsonPhone(): false|string
    {
        $countries = collect(config('phone.countries'));
        $country = ($code = $this->phoneCountry()) !== null ? $countries->firstWhere('code', $code) : null;

        return json_encode([
            'flag' => $country['flag'] ?? '',
            'prefix' => $country['prefix'] ?? '',
            'code' => $country['code'] ?? '',
        ], JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
    }
}
