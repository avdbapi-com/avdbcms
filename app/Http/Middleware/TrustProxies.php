<?php

namespace App\Http\Middleware;

use App\Support\AdminIpLock;
use App\Support\RuntimeEnv;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Which proxies in front of the site are believed when they say who the visitor is (X-Forwarded-For) and whether the
 * visitor used https (X-Forwarded-Proto).
 *
 * The list is the admin setting `site.trusted_proxies` (addresses or CIDR ranges, one per line or comma separated, or
 * `*`). A site that never set it (every install: the admin has no field for it and example.sql no row) trusts only hops
 * nobody on the internet can be, the rule the admin IP lock uses (owner decision OD2): a proxy on this machine
 * (loopback), on a private or link-local network (a panel's reverse proxy, a container bridge) and Cloudflare's
 * published ranges, plus the proxies listed in SITE_TRUSTED_PROXIES / TRUSTED_PROXIES in .env. So Cloudflare, Docker
 * panels, aaPanel/OpenLiteSpeed, HestiaCP, Virtualmin, CyberPanel and cPanel keep working with no setting, and a
 * visitor who types those headers is not believed. A `*` (in the setting or in .env) is the owner's explicit choice:
 * whoever connects is then trusted, as before 1.2.3.
 *
 * Only X-Forwarded-For and X-Forwarded-Proto are ever trusted. X-Forwarded-Host, X-Forwarded-Prefix and
 * X-Forwarded-Port (also inside HEADER_X_FORWARDED_AWS_ELB) never are: the site's links are built from APP_URL
 * (EnvironmentServiceProvider pins them), so no proxy needs to rename the host, and a visitor who sends them could
 * otherwise put another host into a password-reset link or a cached page.
 *
 * Laravel resets the trusted proxies at the start of every request (parent::handle()), so the list is built here, per
 * request, from the current setting; EnvironmentServiceProvider applies the same list at boot for the code that runs
 * before this middleware.
 */
class TrustProxies extends Middleware
{
    /** The forwarded headers that are believed from a trusted proxy. */
    public const TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @var array<int, string>|string|null
     */
    protected $proxies = null;

    /**
     * @var int
     */
    protected $headers = self::TRUSTED_HEADERS;

    public function handle($request, \Closure $next)
    {
        $this->proxies = self::trustedProxies();

        return parent::handle($request, $next);
    }

    /**
     * The proxies to trust now: '*' (whoever connects; only when the owner wrote it) or a list of addresses and ranges.
     * Never empty, so Laravel never falls back to its own defaults.
     *
     * @return list<string>|string
     */
    public static function trustedProxies(): array|string
    {
        $listed = self::entries(config('site.trusted_proxies', ''));

        if ($listed === []) {
            // Never set (every install): this machine, a private network, Cloudflare, plus the proxies listed in .env.
            $env = self::envEntries();

            if (self::trustsEveryone($env)) {
                return '*';
            }

            return self::withMappedForms(array_values(array_unique([
                ...AdminIpLock::LOOPBACK_RANGES,
                ...AdminIpLock::PRIVATE_RANGES,
                ...AdminIpLock::CLOUDFLARE_RANGES,
                ...array_filter($env, static fn (string $entry): bool => AdminIpLock::isValidEntry($entry)),
            ])));
        }

        // The owner's own list (or '*') stays exactly as it was before 1.2.3.
        return self::trustsEveryone($listed) ? '*' : $listed;
    }

    /**
     * Each IPv4 address or range also in its IPv4-mapped IPv6 form (a.b.c.d/n -> ::ffff:a.b.c.d/96+n): a server whose
     * socket takes IPv4 and IPv6 at once (nginx "listen [::]:80 ipv6only=off") reports an IPv4 hop as ::ffff:a.b.c.d.
     * That is the same address, so it is trusted the same way (AdminIpLock::normalize() reads it so too).
     *
     * @param  list<string>  $ranges
     * @return list<string>
     */
    private static function withMappedForms(array $ranges): array
    {
        $all = $ranges;

        foreach ($ranges as $range) {
            [$address, $bits] = array_pad(explode('/', $range, 2), 2, '32');

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! ctype_digit($bits) || (int) $bits > 32) {
                continue;
            }

            $all[] = '::ffff:'.$address.'/'.(96 + (int) $bits);
        }

        return array_values(array_unique($all));
    }

    /** Whether the trusted proxies are the built-in list (nothing set in the admin setting or in .env). */
    public static function usesDefaultList(): bool
    {
        return self::entries(config('site.trusted_proxies', '')) === [] && self::envEntries() === [];
    }

    /** @param  list<string>  $entries */
    private static function trustsEveryone(array $entries): bool
    {
        return in_array('*', $entries, true) || in_array('**', $entries, true);
    }

    /** @return list<string> the entries of SITE_TRUSTED_PROXIES and TRUSTED_PROXIES in .env */
    private static function envEntries(): array
    {
        return self::entries(implode(',', [
            (string) RuntimeEnv::get('SITE_TRUSTED_PROXIES', ''),
            (string) RuntimeEnv::get('TRUSTED_PROXIES', ''),
        ]));
    }

    /**
     * The non-empty entries of a saved list, read as the admin IP lock reads it: separated by new lines, commas or
     * spaces (a list kept as an array also works).
     *
     * @return list<string>
     */
    private static function entries(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', array_filter($value, 'is_string'));
        }

        return is_scalar($value) ? AdminIpLock::entries((string) $value) : [];
    }
}
