<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * How the admin writes the payment services of subscriptions, premium records and payments.
 *
 * The rows store the service in lower case (payment_provider: "stripe" or "paypal"; a row from before PayPal support
 * has the column's default "stripe"). Str::headline() turned "paypal" into "Paypal", but the brand is written PayPal
 * (scripts/i18n/brands.json). Brand names are never translated.
 */
final class PaymentProviders
{
    /** @var array<string, string> */
    private const NAMES = [
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
    ];

    /** The name of a payment service: "Stripe", "PayPal"; no value means Stripe; any other service gets a headline. */
    public static function label(?string $provider): string
    {
        $value = trim((string) $provider);

        if ($value === '') {
            return self::NAMES['stripe'];
        }

        return self::NAMES[strtolower($value)] ?? Str::headline($value);
    }

    /**
     * The Card column: the last digits of a card as "•••• 4242". A subscription paid another way stores the name of
     * its service there instead (a PayPal subscription stores "PayPal"): that name is shown as it is, without the
     * dots. Nothing stored: null, so each view shows its own "-" or "N/A".
     */
    public static function card(?string $last4): ?string
    {
        $value = trim((string) $last4);

        if ($value === '') {
            return null;
        }

        return preg_match('/^\d{1,4}$/', $value) === 1 ? '•••• '.$value : $value;
    }
}
