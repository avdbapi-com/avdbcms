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
     * Supply a callback for incomplete installations while preserving an
     * explicit absolute callback registered by the administrator/provider.
     */
    public static function configureRedirect(string $service): void
    {
        $service = self::normalize($service);

        if (! self::isSupported($service)) {
            return;
        }

        $configuredRedirect = trim((string) config("services.{$service}.redirect", ''));
        $configuredParts = $configuredRedirect !== '' ? parse_url($configuredRedirect) : false;

        // Keep a valid provider callback configured by the installer or an
        // administrator. Only synthesize one when the package has no absolute
        // callback URL at all.
        if (is_array($configuredParts)
            && isset($configuredParts['scheme'], $configuredParts['host'])) {
            return;
        }

        Config::set("services.{$service}.redirect", route('oauth.callback', [
            'service' => $service,
        ]));
    }
}
