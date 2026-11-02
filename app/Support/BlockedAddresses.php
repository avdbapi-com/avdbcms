<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Who may not open the site (AVDBCMS 1.2.3): the addresses blocked on Members > Blocked addresses (the ip_bans table,
 * each until its end) and the list of its card "Blocked ranges" (the setting security.global_ip_blacklist: one address
 * or CIDR range per line, IPv4 and IPv6, such as 203.0.113.7 or 203.0.113.0/24). App\Http\Middleware\Security asks
 * refuses() on every page and answers 403 "Access denied.".
 *
 * Up to 1.2.2 the ip_bans table was never checked, so "Block an address" blocked nobody, and the list matched exact
 * addresses only, so a range blocked nobody either.
 *
 * The visitor is AdminIpLock::clientIp() (as for the admin lock): forwarded headers count only from hops nobody on the
 * internet can be, so nobody steps around a block with an X-Forwarded-For header of their own.
 *
 * An owner can never lock themselves out: the sign-in page (and signing out) stays open to a blocked address, and a
 * signed-in administrator (not a view-only demo account) is never blocked. The admin also refuses to block the address
 * of the administrator who saves the block (App\Rules\BlockedAddressList, IpBanController).
 *
 * The active blocks are kept in one cache entry (CACHE_KEY, emptied by every change on Members > Blocked addresses
 * and renewed every CACHE_SECONDS); a site with more than CACHE_MAX of them looks each visitor up in the table instead
 * (its unique index on ip_address).
 */
final class BlockedAddresses
{
    public const CACHE_KEY = 'avdbcms-blocked-addresses:v1';

    public const CACHE_SECONDS = 300;

    public const CACHE_MAX = 5000;

    /**
     * The routes a blocked address can still open: signing in (password, Google, Facebook) and out, so an owner can
     * always get back in.
     */
    public const OPEN_ROUTES = ['login', 'login.perform', 'logout', 'oauth.connect', 'oauth.callback'];

    /** The same for an app: the API's sign-in (POST /api/login). */
    public const OPEN_PATHS = ['api/login'];

    /**
     * Whether this request gets "Access denied." (see the class comment for who never does). The pages ask it through
     * App\Http\Middleware\Security, the API (/api/*) through App\Http\Middleware\RefuseBlockedAddresses.
     */
    public static function refuses(Request $request): bool
    {
        $ip = AdminIpLock::clientIp($request);

        if ($ip === '' || ! self::blocks($ip)) {
            return false;
        }

        if (($request->route() !== null && $request->routeIs(...self::OPEN_ROUTES)) || $request->is(...self::OPEN_PATHS)) {
            return false;
        }

        $user = self::signedIn($request);

        return ! ($user !== null && (bool) $user->is_admin && ! (bool) ($user->is_view_only ?? false));
    }

    /** The signed-in account: the session's, or for the API also the account of its token. */
    private static function signedIn(Request $request): mixed
    {
        try {
            $user = $request->user();

            if ($user === null && $request->is('api/*')) {
                $user = auth('sanctum')->user();
            }

            return $user;
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether the address is blocked: by the list of "Blocked ranges" or by an active block of Blocked addresses. */
    public static function blocks(string $ip): bool
    {
        $ip = AdminIpLock::normalize($ip);

        if ($ip === '') {
            return false;
        }

        return self::listBlocks((string) config('site.global_ip_blacklist', ''), $ip) || self::banned($ip);
    }

    /** Whether a list of addresses and ranges (one per line; commas and spaces also separate) covers the address. */
    public static function listBlocks(string $list, string $ip): bool
    {
        $ip = AdminIpLock::normalize($ip);
        $entries = AdminIpLock::entries($list);

        return $ip !== '' && $entries !== [] && AdminIpLock::matches($ip, $entries);
    }

    /** @return list<string> the entries of a list that are neither an address nor a range */
    public static function invalidEntries(string $list): array
    {
        return AdminIpLock::invalidEntries($list);
    }

    /** Whether an active block of Members > Blocked addresses names the address. */
    public static function banned(string $ip): bool
    {
        $canonical = self::canonical($ip);

        if ($canonical === '') {
            return false;
        }

        $bans = self::activeBans();

        if ($bans !== null) {
            if (! array_key_exists($canonical, $bans)) {
                return false;
            }

            return $bans[$canonical] === null || $bans[$canonical] > time();
        }

        // Too many blocks to keep in the cache: ask the table (the address as typed and in its short form).
        try {
            return DB::table('ip_bans')
                ->whereIn('ip_address', array_values(array_unique([AdminIpLock::normalize($ip), $canonical])))
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Empties the cache of active blocks (after a block is added, lifted or cleared). */
    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // No cache: the blocks are read again within CACHE_SECONDS anyway.
        }
    }

    /**
     * The short form of an address, as blocks are compared: "2001:DB8:0:0::1" -> "2001:db8::1", "::ffff:203.0.113.7"
     * -> "203.0.113.7"; '' when it is no address.
     */
    public static function canonical(string $ip): string
    {
        $ip = AdminIpLock::normalize($ip);

        if ($ip === '') {
            return '';
        }

        $packed = @inet_pton($ip);
        $short = $packed === false ? false : @inet_ntop($packed);

        return is_string($short) ? $short : strtolower($ip);
    }

    /**
     * The active blocks: short address => end (Unix time) or null (for good); null when there are more than CACHE_MAX.
     *
     * @return array<string, ?int>|null
     */
    private static function activeBans(): ?array
    {
        $cached = SafeCache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static function (): array {
            try {
                $rows = DB::table('ip_bans')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->limit(self::CACHE_MAX + 1)
                    ->get(['ip_address', 'expires_at']);
            } catch (Throwable) {
                // No table yet (an install in progress) or no database: nobody is blocked by it.
                return ['bans' => []];
            }

            if ($rows->count() > self::CACHE_MAX) {
                return ['many' => true];
            }

            $bans = [];

            foreach ($rows as $row) {
                $address = self::canonical((string) $row->ip_address);

                if ($address === '') {
                    continue;
                }

                $until = $row->expires_at === null ? null : Carbon::parse((string) $row->expires_at)->getTimestamp();

                // Two rows for one address (typed in two forms): the longer block counts.
                if (! array_key_exists($address, $bans)) {
                    $bans[$address] = $until;
                } elseif ($bans[$address] !== null) {
                    $bans[$address] = $until === null ? null : max($bans[$address], $until);
                }
            }

            return ['bans' => $bans];
        });

        if (! is_array($cached) || ! empty($cached['many'])) {
            return is_array($cached) ? null : [];
        }

        return is_array($cached['bans'] ?? null) ? $cached['bans'] : [];
    }
}
