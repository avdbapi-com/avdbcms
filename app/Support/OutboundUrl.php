<?php

namespace App\Support;

use RuntimeException;

/**
 * Addresses the admin's own tools fetch (the AVDB / XVIDAPI connection tests and import bases, the video stream test):
 * only http(s) addresses on the internet. Every address the host resolves to must be a public one, so these tools
 * cannot be pointed at this server, its private network or a cloud metadata address (127.0.0.1:6379,
 * 169.254.169.254 ...). Redirects are followed only to public addresses too, and the checked address is the one the
 * request connects to (no second DNS answer in between).
 */
final class OutboundUrl
{
    /** The answer the tools give for an address that is not on the internet. */
    public static function refusal(): string
    {
        return __('This address is not reachable from the internet.');
    }

    /** Whether the URL is http(s) to a host whose every address is a public internet address. */
    public static function isPublic(string $url): bool
    {
        return self::publicAddresses($url) !== null;
    }

    /**
     * The public addresses of the URL's host, or null when the URL is not http(s), has no host, the host has no
     * address, or any of its addresses is not public (loopback, private, link-local, shared, reserved, documentation,
     * multicast; IPv4-mapped IPv6 addresses count as their IPv4 address).
     *
     * @return list<string>|null
     */
    public static function publicAddresses(string $url): ?array
    {
        $host = self::host($url);

        if ($host === null) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return null;
            }
        }

        return $addresses;
    }

    /**
     * Without asking DNS: whether the URL names this machine or a private address directly (a literal address that is
     * not public, or localhost). For values saved before 1.2.3 that a background import reads again.
     */
    public static function namesLocalHost(string $url): bool
    {
        $host = self::host($url);

        if ($host === null) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! self::isPublicAddress($host);
        }

        return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'localhost.localdomain';
    }

    /**
     * Guzzle options for a request to the URL: redirects only to public addresses (at most 5, http and https), and the
     * first connection pinned to the address that was checked (curl's resolve list), so a host cannot answer the check
     * with a public address and the request with a private one.
     *
     * @return array<string, mixed>
     */
    public static function options(string $url): array
    {
        $options = ['allow_redirects' => self::redirects()];
        $addresses = self::publicAddresses($url);
        $host = self::host($url);

        if ($addresses !== null && $host !== null && filter_var($host, FILTER_VALIDATE_IP) === false && defined('CURLOPT_RESOLVE')) {
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80));
            $address = $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]];
        }

        return $options;
    }

    /**
     * Guzzle's allow_redirects option: a redirect to an address that is not public stops the request.
     *
     * @return array<string, mixed>
     */
    public static function redirects(): array
    {
        return [
            'max' => 5,
            'protocols' => ['http', 'https'],
            'on_redirect' => static function ($request, $response, $uri): void {
                if (! self::isPublic((string) $uri)) {
                    throw new RuntimeException(self::refusal());
                }
            },
        ];
    }

    /** Whether one address is a public internet address. */
    public static function isPublicAddress(string $address): bool
    {
        $address = trim($address, '[] ');

        // ::ffff:10.0.0.5 is 10.0.0.5.
        if (str_starts_with(strtolower($address), '::ffff:') && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $address = substr($address, 7);
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Shared address space (carrier-grade NAT) and the IPv6 unique-local, link-local and discard ranges, in case the
        // PHP build's global-range list leaves one out.
        foreach (['100.64.0.0/10', '0.0.0.0/8', 'fc00::/7', 'fe80::/10', '100::/64', '::/128', '::1/128'] as $range) {
            if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($address, $range)) {
                return false;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** The lower-case host of an http(s) URL (brackets of an IPv6 address removed), or null. */
    private static function host(string $url): ?string
    {
        $url = trim($url);

        // A backslash or whitespace: browsers and clients read such an address differently from parse_url().
        if ($url === '' || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        return $host === '' ? null : rtrim($host, '.');
    }

    /** @return list<string> the A and AAAA addresses of a host name ([] when it has none or DNS cannot answer) */
    private static function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            $addresses = is_array($ipv4) ? $ipv4 : [];
        }

        return array_values(array_unique($addresses));
    }
}
