<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Whether Premium can be bought on this site, with what, and how its prices read (member design 6.16, 9.3).
 *
 * One answer for every member page: the Premium page, the plan card in Account settings, the invoices and
 * the account panel's "Premium" link, so no page offers "Become premium" while nothing can be bought (account-32).
 * Premium can be sold when it is switched on, a payment service is set up (card: the three Stripe keys; PayPal: the
 * client id, secret and webhook id) and at least one plan has the price id of a service that is set up.
 *
 * Prices are stored in cents (plans.price, transactions.amount) and shown with the currency symbol of the site's
 * Stripe currency (CASHIER_CURRENCY, "€5", "€4.17"; account-51), on the member pages and (1.2.3) on the admin's money
 * pages too (Money, Home's Earnings, Invoices, Payments, Premium plans), never MoneyCast's "EUR 5". money() writes the
 * admin's form; the member pages write the same amounts in the member's language with App\Support\BillingFormat
 * ("5,00 €", "€5,00"; R2A-038).
 */
final class PremiumAvailability
{
    /** The container key of the plans read once per request (a fresh container per request and per test). */
    private const PLANS = 'avd.premium-availability.plans';

    /** @var array<string, string> currency code => symbol, written before the amount */
    private const SYMBOLS = [
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹', 'KRW' => '₩',
        'RUB' => '₽', 'TRY' => '₺', 'VND' => '₫', 'THB' => '฿', 'PHP' => '₱', 'BRL' => 'R$', 'IDR' => 'Rp ',
        'MYR' => 'RM ', 'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'CHF' => 'CHF ', 'MXN' => 'MX$',
        'SGD' => 'S$', 'HKD' => 'HK$', 'PLN' => 'zł ', 'SEK' => 'kr ', 'NOK' => 'kr ', 'DKK' => 'kr ',
    ];

    /** Currencies without cents (Stripe's zero-decimal currencies that the site may use). */
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'PYG', 'UGX', 'XAF', 'XOF'];

    public static function enabled(): bool
    {
        return (bool) config('site.premium_enabled', true);
    }

    public static function stripeReady(): bool
    {
        return (bool) config('site.stripe_enabled', true)
            && filled(config('cashier.key'))
            && filled(config('cashier.secret'))
            && filled(config('cashier.webhook.secret'));
    }

    public static function paypalReady(): bool
    {
        return (bool) config('site.paypal_enabled', true)
            && filled(config('services.paypal.client_id'))
            && filled(config('services.paypal.client_secret'))
            && filled(config('services.paypal.webhook_id'));
    }

    /**
     * The payment services that are switched on and have their keys (Stripe: publishable key and secret key; PayPal:
     * client id and secret) but not the webhook value their checkout needs (Stripe: the webhook signing secret, PayPal:
     * the webhook id). Checkout stays closed for them although the keys are there, so Money > Earnings, the callout of
     * Money > Payment setup and Home's "Needs you" name them (an updated site with Stripe on and no signing secret would
     * otherwise stop selling Premium without a word).
     *
     * @return list<'stripe'|'paypal'>
     */
    public static function missingWebhooks(): array
    {
        $missing = [];

        if ((bool) config('site.stripe_enabled', true) && filled(config('cashier.key')) && filled(config('cashier.secret'))
            && blank(config('cashier.webhook.secret'))) {
            $missing[] = 'stripe';
        }

        if ((bool) config('site.paypal_enabled', true) && filled(config('services.paypal.client_id'))
            && filled(config('services.paypal.client_secret')) && blank(config('services.paypal.webhook_id'))) {
            $missing[] = 'paypal';
        }

        return $missing;
    }

    /**
     * Premium is on, a payment service is ready and plans exist, but no plan has the id of a ready service (Stripe price
     * id / PayPal plan id): nothing can be bought, although every key is in place.
     */
    public static function plansUnlinked(): bool
    {
        return self::enabled()
            && (self::stripeReady() || self::paypalReady())
            && self::plans()->isNotEmpty()
            && ! self::canSell();
    }

    /**
     * What keeps checkout closed although the owner set payments up, in plain words for the admin (empty: nothing).
     * A site that never entered a payment key gets nothing: nothing was set up, nothing is broken.
     *
     * @return list<string>
     */
    public static function setupProblems(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $problems = [];

        foreach (self::missingWebhooks() as $service) {
            $problems[] = $service === 'stripe'
                ? __('Card payments (Stripe): the webhook signing secret is missing, so members cannot pay by card.')
                : __('PayPal: the webhook ID is missing, so members cannot pay with PayPal.');
        }

        if (self::plansUnlinked()) {
            $problems[] = __('No plan has the Stripe price ID or the PayPal plan ID of a connected payment service, so no plan can be bought.');
        }

        return $problems;
    }

    /** @return Collection<int, Plan> the site's plans, shortest first, read once per request */
    public static function plans(): Collection
    {
        $app = app();

        if ($app->bound(self::PLANS)) {
            return $app->make(self::PLANS);
        }

        try {
            $plans = Plan::query()->orderBy('duration')->orderBy('id')->get();
        } catch (Throwable) {
            // A site in the middle of an update (no plans table yet) sells nothing.
            $plans = new Collection();
        }

        $app->instance(self::PLANS, $plans);

        return $plans;
    }

    /** Forget the plans read in this request (after an admin changed one, in the same request). */
    public static function forget(): void
    {
        app()->forgetInstance(self::PLANS);
    }

    public static function planStripeReady(Plan $plan): bool
    {
        return self::enabled() && self::stripeReady() && filled($plan->stripe_id);
    }

    public static function planPaypalReady(Plan $plan): bool
    {
        return self::enabled() && self::paypalReady() && filled($plan->paypal_plan_id);
    }

    public static function planReady(Plan $plan): bool
    {
        return self::planStripeReady($plan) || self::planPaypalReady($plan);
    }

    /** Premium can be bought: switched on, a payment service set up, and at least one plan that service can sell. */
    public static function canSell(): bool
    {
        if (! self::enabled() || (! self::stripeReady() && ! self::paypalReady())) {
            return false;
        }

        return self::plans()->contains(fn (Plan $plan): bool => self::planReady($plan));
    }

    /** The site's currency code ("EUR"): the Stripe currency of the site, the one its plans are sold in. */
    public static function currency(): string
    {
        $currency = strtoupper(trim((string) (config('cashier.currency') ?: 'eur')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'EUR';
    }

    /**
     * An amount in cents with the currency symbol: "€5", "€4.17", "€5.00" ($precision 2), "CHF 12.50".
     * $precision null: whole amounts without decimals ("€5", "€50"), others with two ("€4.17").
     */
    public static function money(int|float $cents, ?int $precision = null, ?string $currency = null): string
    {
        $currency = $currency !== null ? strtoupper($currency) : self::currency();
        $zeroDecimal = in_array($currency, self::ZERO_DECIMAL, true);
        $amount = self::units($cents, $currency);

        if ($precision === null) {
            $precision = $zeroDecimal || abs($amount - round($amount)) < 0.005 ? 0 : 2;
        }

        $number = number_format($amount, $zeroDecimal ? 0 : $precision, '.', ',');
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return ($amount < 0 ? '-' : '').$symbol.ltrim($number, '-');
    }

    /** An amount in cents as a number of the currency's own units: 9.99 for 999 (EUR), 500 for 500 (JPY has no cents). */
    public static function units(int|float $cents, ?string $currency = null): float
    {
        $currency = $currency !== null ? strtoupper($currency) : self::currency();

        return in_array($currency, self::ZERO_DECIMAL, true) ? (float) $cents : ((float) $cents) / 100;
    }

    /** A plan's price: "€5", "€50". */
    public static function price(Plan $plan, ?int $precision = null): string
    {
        return self::money((int) $plan->getRawOriginal('price'), $precision);
    }

    /** The months a plan pays for (1 for a monthly plan, 12 for a yearly one; at least 1). */
    public static function months(Plan $plan): int
    {
        return max(1, (int) $plan->duration);
    }

    /** "a month", "a year", "every 3 months": what one payment pays for. */
    public static function per(Plan $plan): string
    {
        return match (self::months($plan)) {
            1 => __('a month'),
            12 => __('a year'),
            default => trans_choice('{1} every month|[2,*] every :count months', self::months($plan), ['count' => self::months($plan)]),
        };
    }

    /**
     * "€4.17 a month" for a plan that pays for more than one month, else null. Shown to members (the Premium page): the
     * amount is written the reader's way ("4,17 € pro Monat"; BillingFormat, R2A-038).
     */
    public static function monthlyEquivalent(Plan $plan): ?string
    {
        $months = self::months($plan);

        if ($months <= 1) {
            return null;
        }

        return __(':price a month', ['price' => BillingFormat::money(((int) $plan->getRawOriginal('price')) / $months, 2)]);
    }

    /** The plan's own name for members ("Monthly", "Annually"): the admin's name, translated. */
    public static function name(Plan $plan): string
    {
        $name = trim((string) $plan->name);

        if ($name === '') {
            return self::months($plan) === 12 ? __('Yearly') : __('Monthly');
        }

        return __(ucfirst($name));
    }
}
