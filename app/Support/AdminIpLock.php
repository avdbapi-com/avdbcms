<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The admin IP lock (Settings > Security: "Only these internet addresses can open the admin").
 *
 * The address it checks is the visitor's real one. Forwarded headers (X-Forwarded-For, CF-Connecting-IP) are believed
 * without any setting only from hops nobody on the internet can be (owner decision OD2): a proxy on this machine
 * (loopback), on a private or link-local network (the reverse proxy of a panel, a container bridge) or Cloudflare's
 * published ranges, plus proxies the owner listed in SITE_TRUSTED_PROXIES / TRUSTED_PROXIES. So Docker panels,
 * aaPanel/OpenLiteSpeed, HestiaCP, Virtualmin, CyberPanel and cPanel work out of the box. Anyone else can type those
 * headers, so for them the connection address counts: a forged "CF-Ray + X-Forwarded-For" sent from a public address
 * does not open a locked admin.
 *
 * Entries are IPv4 or IPv6 addresses or CIDR ranges, one per line (commas and spaces also separate entries).
 * A locked-out owner gets back in with `php artisan avd:admin-ip-lock off` (App\Console\Commands\AdminIpLockCommand).
 */
final class AdminIpLock
{
    /** Cloudflare's published edge ranges (https://www.cloudflare.com/ips/). */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** Addresses only a proxy on this machine can connect from. */
    public const LOOPBACK_RANGES = [
        '127.0.0.0/8',
        '::1/128',
    ];

    /**
     * Private and link-local networks: the internet cannot connect from them. Narrower than IpUtils::PRIVATE_SUBNETS on
     * purpose: that list includes ::ffff:0:0/96, so every IPv4 client seen through a dual-stack socket would count as
     * private.
     */
    public const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
        '169.254.0.0/16',
        'fe80::/10',
    ];

    public static function isCloudflare(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip !== '' && IpUtils::checkIp($ip, self::CLOUDFLARE_RANGES);
    }

    public static function isLoopback(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip !== '' && IpUtils::checkIp($ip, self::LOOPBACK_RANGES);
    }

    /** Loopback, a private or a link-local network: nobody on the internet connects from there. */
    public static function isLocalProxy(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip !== '' && IpUtils::checkIp($ip, [...self::LOOPBACK_RANGES, ...self::PRIVATE_RANGES]);
    }

    /**
     * The visitor address the lock compares with the list (never taken from a header a visitor can forge).
     *
     * Walks back from the connection through the hops we trust: each one wrote the address it saw on the right of
     * X-Forwarded-For. The first address we do not trust is the visitor; everything left of it was written by the
     * visitor and proves nothing. When the walk reaches Cloudflare, the visitor is the CF-Connecting-IP that Cloudflare
     * wrote (it overwrites a value the visitor sent). When every hop is ours, the left-most one is the visitor.
     */
    public static function clientIp(Request $request): string
    {
        $hop = self::normalize((string) $request->server->get('REMOTE_ADDR', ''));
        $forwarded = array_reverse(explode(',', (string) $request->headers->get('X-Forwarded-For', '')));

        while ($hop !== '' && self::isTrustedHop($hop)) {
            if (self::isCloudflare($hop)) {
                $connecting = self::normalize((string) $request->headers->get('CF-Connecting-IP', ''));

                if ($connecting !== '') {
                    return $connecting;
                }
            }

            $next = self::normalize((string) array_shift($forwarded));

            if ($next === '') {
                break;
            }

            $hop = $next;
        }

        return $hop;
    }

    /** Whether the request may open the admin under this list (an empty list allows everyone). */
    public static function allows(Request $request, string $list): bool
    {
        $entries = self::entries($list);

        return $entries === [] || self::matches(self::clientIp($request), $entries);
    }

    /** @param list<string> $entries */
    public static function matches(string $ip, array $entries): bool
    {
        $ip = self::normalize($ip);

        if ($ip === '') {
            return false;
        }

        foreach ($entries as $entry) {
            if (self::isValidEntry($entry) && IpUtils::checkIp($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the non-empty entries of a saved list */
    public static function entries(string $list): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', trim($list)) ?: [],
            static fn (string $entry): bool => $entry !== ''
        ));
    }

    public static function isValidEntry(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /** @return list<string> entries that are not an address or a range */
    public static function invalidEntries(string $list): array
    {
        return array_values(array_filter(self::entries($list), static fn (string $entry): bool => ! self::isValidEntry($entry)));
    }

    /** The list with this address added on its own line when no entry covers it yet. */
    public static function withAddress(string $list, string $ip): string
    {
        $ip = self::normalize($ip);
        $entries = self::entries($list);

        if ($ip === '' || $entries === [] || self::matches($ip, $entries)) {
            return $list;
        }

        return rtrim($list)."\n".$ip;
    }

    /** "::ffff:203.0.113.5" (an IPv4 visitor on a dual-stack socket) -> "203.0.113.5"; '' when not an address. */
    public static function normalize(string $ip): string
    {
        $ip = trim($ip);

        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /** A hop whose forwarded headers count: Cloudflare, this machine, a private network or a proxy the owner listed. */
    private static function isTrustedHop(string $ip): bool
    {
        $ip = self::normalize($ip);

        return $ip !== '' && (self::isCloudflare($ip)
            || self::isLocalProxy($ip)
            || (($proxies = self::configuredProxies()) !== [] && IpUtils::checkIp($ip, $proxies)));
    }

    /** @return list<string> proxies the owner listed (a bare '*' trusts whoever connects, so it is not a list) */
    private static function configuredProxies(): array
    {
        $raw = implode(',', [
            (string) config('site.trusted_proxies', ''),
            (string) RuntimeEnv::get('SITE_TRUSTED_PROXIES', ''),
            (string) RuntimeEnv::get('TRUSTED_PROXIES', ''),
        ]);

        return array_values(array_filter(
            preg_split('/[\s,]+/', $raw) ?: [],
            static fn (string $entry): bool => $entry !== '' && self::isValidEntry($entry)
        ));
    }
}
