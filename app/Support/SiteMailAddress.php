<?php

namespace App\Support;

/**
 * The site's Alerts e-mail (where failed background jobs and failed scheduled tasks are mailed:
 * App\Listeners\BackgroundEventSubscriber, config('mail.server_mail')) and Support e-mail (config('mail.support_mail')),
 * as AppServiceProvider::applySettings() sets them for every web request and console process.
 *
 * The admin's address from Settings > Email is used as it is. Only a setting that is empty, or still EXACTLY one of the
 * example addresses AVDBCMS shipped for its e-mail settings (the bundled SQL backup's noreply@example.com,
 * support@example.com and admin@example.com, the server@example.com of .env.example that the migrations copied), is
 * taken as not set (round 3 R3-006; never an owner's choice: mail to an example address never arrives, so the owner
 * learnt nothing of a failed import or job): then the address in .env (SERVER_EMAIL / SUPPORT_EMAIL, which the installer
 * writes with the administrator's e-mail) is used, else the site's contact e-mail (the administrator's since the
 * installation). Nothing is stored or changed.
 */
final class SiteMailAddress
{
    /** The example addresses the package shipped for its e-mail settings (example.sql, .env.example; 1.2.0 to 1.2.3). */
    public const array SHIPPED_EXAMPLES = ['noreply@example.com', 'support@example.com', 'server@example.com', 'admin@example.com'];

    /** The settings resolved here (Settings > Email's Support e-mail and Alerts e-mail) and the .env key of each one. */
    public const array ENV_KEYS = ['support_email' => 'SUPPORT_EMAIL', 'server_email' => 'SERVER_EMAIL'];

    /**
     * What Settings > Email shows in the field of $key (support_email / server_email): the stored value when it is the
     * admin's own address; while it is empty or still a shipped example, the address the site really uses instead
     * (resolve()), so the page never shows an address the site does not use (round 3 IN-RV-03). Nothing stored changes
     * until the admin saves the page. Any other key: $stored as it is.
     */
    public static function shownValue(string $key, mixed $stored, mixed $contact): string
    {
        $value = is_scalar($stored) ? (string) $stored : '';
        $envKey = self::ENV_KEYS[$key] ?? null;

        return $envKey === null ? $value : (string) (self::resolve($value, $envKey, $contact) ?? $value);
    }

    /**
     * The address the site uses for $key while the admin has none of his own there (Settings > Email's "Default"): the
     * real one of .env and the contact e-mail; null when neither is real, or for any other key.
     */
    public static function fallback(string $key, mixed $contact): ?string
    {
        $envKey = self::ENV_KEYS[$key] ?? null;

        return $envKey === null ? null : self::resolve(null, $envKey, $contact);
    }

    /**
     * The address to use for a setting: $stored (the settings row, null without one) unless it is empty or a shipped
     * example; then the first real one of the .env value $envKey and $contact (the contact e-mail setting). With none of
     * them real: $stored as it is (null without a row), as before.
     */
    public static function resolve(mixed $stored, string $envKey, mixed $contact = null): ?string
    {
        $value = is_scalar($stored) ? trim((string) $stored) : '';

        if ($value !== '' && ! self::isShippedExample($value)) {
            return $value;
        }

        foreach ([static fn (): mixed => RuntimeEnv::get($envKey), static fn (): mixed => $contact] as $candidate) {
            $address = trim((string) (is_scalar($found = $candidate()) ? $found : ''));

            if ($address !== '' && ! self::isShippedExample($address)) {
                return $address;
            }
        }

        return $value !== '' ? $value : null;
    }

    /** Whether $address is one of the example addresses the package shipped (any case, spaces around ignored). */
    public static function isShippedExample(string $address): bool
    {
        return in_array(strtolower(trim($address)), self::SHIPPED_EXAMPLES, true);
    }
}
