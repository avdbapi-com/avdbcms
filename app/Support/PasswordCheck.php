<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The password check of the two sign-ins: the sign-in page (Auth\LoginController) and the apps' /api/login
 * (Api\LoginController), round 3 R3-021.
 *
 * A name or an address without an account has always got the answer of a wrong password, but only an existing
 * account's password was checked: the answer came one password check later when the account existed (the timing runs
 * measured +66 ms at the shipped cost, 20 known against 20 unknown addresses), so the time told whether an address has an
 * account - the oracle round 1b took out of Forgot password (R1R-14).
 *
 * Now every sign-in does exactly ONE password check of the site's cost: against the account's own hash, or - no account,
 * or an account without a password (social sign-in only) - against a fixed hash that no password matches, written with
 * the site's hashing driver and cost (config/hashing.php: what every new account and every password change gets). The
 * fixed hash is built from constants (no hashing to make it), so both ways cost the same one check.
 */
final class PasswordCheck
{
    /**
     * A salt, then a value that no hashing produced ("R3svFixedValueNeverAPassword..."): with the cost or parameters of
     * the site in front of them the check does all its work, and matches no password (it would take a preimage).
     */
    private const BCRYPT_TAIL = 't7Ojx3.VElPNNSNN1Z3QbuR3sv.FixedValueNeverAPassword00';

    private const ARGON2I_TAIL = 'ck1JRW5kZlpRYUc2NVdudQ$UjNzdkZpeGVkVmFsdWVOZXZlckFQYXNzd29yZDAwMDA';

    private const ARGON2ID_TAIL = 'Z1dhV3RRUXl5eVpUZHowSQ$UjNzdkZpeGVkVmFsdWVOZXZlckFQYXNzd29yZDAwMDA';

    /** Whether $password is the password of $user (null: no account has the name or address). One check either way. */
    public static function matches(?Authenticatable $user, string $password): bool
    {
        $stored = $user?->getAuthPassword();
        $own = is_string($stored) && $stored !== '';

        $checked = Hash::check($password, $own ? $stored : self::dummyHash());

        return $own && $checked;
    }

    /**
     * After a sign-in: a password hashed with another cost or driver than the site's now is hashed again with the site's,
     * as Laravel's own sign-in does (hashing.rehash_on_login, on by default; App\Models\User stores the fresh hash as it
     * is). The two sign-ins check the password themselves, so they never did; an account then costs the same one check as
     * every other one, and as an address without an account.
     */
    public static function rehashAfterSignIn(Authenticatable $user, string $password): void
    {
        if (! config('hashing.rehash_on_login', true)) {
            return;
        }

        $provider = Auth::guard('web')->getProvider();

        if (method_exists($provider, 'rehashPasswordIfRequired')) {
            $provider->rehashPasswordIfRequired($user, ['password' => $password]);
        }
    }

    /** A hash that matches no password, with the hashing driver and cost of the site. */
    public static function dummyHash(): string
    {
        $driver = (string) config('hashing.driver', 'bcrypt');

        if ($driver === 'argon' || $driver === 'argon2id') {
            // the options as Laravel's ArgonHasher reads them (its own defaults when a value is not set)
            $memory = max(8, (int) (config('hashing.argon.memory') ?? 1024));
            $time = max(1, (int) (config('hashing.argon.time') ?? 2));
            $threads = max(1, (int) (config('hashing.argon.threads') ?? 2));

            return $driver === 'argon'
                ? sprintf('$argon2i$v=19$m=%d,t=%d,p=%d$%s', $memory, $time, $threads, self::ARGON2I_TAIL)
                : sprintf('$argon2id$v=19$m=%d,t=%d,p=%d$%s', $memory, $time, $threads, self::ARGON2ID_TAIL);
        }

        // as Laravel's BcryptHasher reads it (its own default 12 when the setting is missing); bcrypt allows 4 to 31
        $cost = min(31, max(4, (int) (config('hashing.bcrypt.rounds') ?? 12)));

        return sprintf('$2y$%02d$%s', $cost, self::BCRYPT_TAIL);
    }
}
