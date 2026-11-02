<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * The OAuth providers exposed by the web application.
 *
 * config/services.php also contains non-OAuth integrations (mail, imports,
 * payments, and so on). Those keys must never be accepted as Socialite
 * drivers. Keeping the allowlist and readiness check here lets routes,
 * controllers, and Blade use one contract.
 */
final class OAuthProviders
{
    /** @var list<string> */
    private const SUPPORTED = [
        'google',
        'facebook',
    ];

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    public static function normalize(string $service): string
    {
        return strtolower(trim($service));
    }

    public static function isSupported(string $service): bool
    {
        return in_array(self::normalize($service), self::SUPPORTED, true);
    }

    /**
     * A provider is ready only when both credentials required by Socialite
     * are present. The redirect URL is derived per request by the controller.
     */
    public static function isConfigured(string $service): bool
    {
        $service = self::normalize($service);

        if (! self::isSupported($service)) {
            return false;
        }

        $config = (array) config("services.{$service}", []);

        return trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== '';
    }

    public static function isAvailable(string $service): bool
    {
        return self::isSupported($service) && self::isConfigured($service);
    }

    /**
     * The callback Socialite sends to the provider: the site's own address (callbackUrl()), or an absolute address the
     * owner set for it (GOOGLE_REDIRECT_URI / FACEBOOK_REDIRECT_URI in .env: config/services.php).
     */
    public static function configureRedirect(string $service): void
    {
        $service = self::normalize($service);

        if (! self::isSupported($service)) {
            return;
        }

        $configuredRedirect = trim((string) config("services.{$service}.redirect", ''));
        $configuredParts = $configuredRedirect !== '' ? parse_url($configuredRedirect) : false;

        // Keep a valid provider callback the owner configured. Only build one
        // when there is no absolute callback URL.
        if (is_array($configuredParts)
            && isset($configuredParts['scheme'], $configuredParts['host'])) {
            return;
        }

        Config::set("services.{$service}.redirect", self::callbackUrl($service));
    }

    /**
     * The address the provider sends the member back to, letter for letter what its settings must list: APP_URL (with
     * the folder of a site installed in one), "/index.php" after it where the site's links carry it, then
     * "/oauth/callback/<provider>". Up to 1.2.3 RC2 it was always the clean address, which a server without rewrite
     * rules answers with its own 404 page (round 3 review SB-RV-05); a site with clean links gets the same address as
     * before. The same address on every request (APP_URL's own scheme and host, the SITE's form of its links, never the
     * form one request came by): the provider compares it with the one its settings list.
     * Without a usable APP_URL (an installation in progress) the route's address.
     */
    public static function callbackUrl(string $service): string
    {
        $service = self::normalize($service);
        $appUrl = (string) preg_replace('#/index\.php$#i', '', rtrim(trim((string) config('app.url')), '/'));

        if (preg_match('#^https?://[^/?\#\s]+(?:/[^?\#\s]*)?$#i', $appUrl) !== 1) {
            return route('oauth.callback', ['service' => $service]);
        }

        $indexPhp = self::siteLinksCarryIndexPhp() ? '/index.php' : '';

        return rtrim($appUrl, '/').$indexPhp.'/oauth/callback/'.rawurlencode($service);
    }

    /**
     * Whether the site's links carry /index.php, whatever form this request came by (CleanUrls::decide(false)): a visitor
     * who opened /index.php/login on a site whose links are clean (an old bookmark; the page then keeps /index.php in its
     * own links) would otherwise send another callback than the one the provider's settings list.
     */
    private static function siteLinksCarryIndexPhp(): bool
    {
        try {
            return (bool) (CleanUrls::decide(false)[0] ?? false);
        } catch (\Throwable) {
            return function_exists('index_php_links') && index_php_links();
        }
    }
}
