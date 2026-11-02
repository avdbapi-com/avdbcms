<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\PremiumAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin's money pages (R1B-024): the old admin's Invoices page had its totals as NULL without any payment (SUM()
 * of no rows) and printed them through number_format(null) - a deprecation notice each, an error in PHP 9 - and the
 * totals said "$" whatever the site sells in. Every amount of Money > Invoices, Payments and Plans, in the old and the
 * new admin, is now written in the site's currency (CASHIER_CURRENCY, as the member's invoices and the Premium page
 * write it): totals, rows, the best customers and the months, and the plans' prices (they said "EUR 10" for a
 * 9.99 plan on every site). The same for the earnings of Money (this month, all time) and Home's "Earnings" tile (they
 * said "5.00 JPY" for a payment of 500 yen: every currency divided by 100), and the old admin's "Premium Gains" chart
 * counts whole yen too (PremiumAvailability::units(); its SQL needs MySQL or MariaDB: checked there by the lane).
 */
class AdminMoneyCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_old_invoices_page_without_any_payment_has_totals_of_zero_and_no_deprecation(): void
    {
        config(['admin.ui' => 'v1', 'cashier.currency' => 'usd']);
        $deprecations = [];
        set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $response = $this->actingAs($this->admin)->get(route('admin.invoices.index'))->assertOk();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], array_values(array_filter($deprecations, fn (string $message): bool => str_contains($message, 'number_format'))),
            'no number_format(null): '.implode(' | ', $deprecations));
        $response->assertViewHas('stats', fn (object $stats): bool => [$stats->total_count, $stats->total_amount, $stats->total_tax, $stats->total_fee] === [0, 0, 0, 0]);
        $this->assertSame(3, substr_count((string) $response->getContent(), '<h3>$0.00</h3>'), 'revenue, tax and fees: $0.00');
    }

    /** @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}> */
    public static function currencies(): array
    {
        return [
            'euro' => ['eur', ['€9.99', '€1.00', '€0.30'], ['$9.99', 'EUR 9.99', 'EUR 10']],
            'dollar' => ['usd', ['$9.99', '$1.00', '$0.30'], ['€9.99', 'EUR 9.99', 'EUR 10']],
            'pound' => ['gbp', ['£9.99', '£1.00', '£0.30'], ['$9.99', '€9.99', 'EUR 9.99']],
        ];
    }

    #[DataProvider('currencies')]
    public function test_the_invoices_pages_write_every_amount_in_the_sites_currency(string $currency, array $shown, array $never): void
    {
        config(['cashier.currency' => $currency]);
        $this->payment();

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($this->admin)->get(route('admin.invoices.index'))->assertOk()->getContent();

            foreach ($shown as $amount) {
                $this->assertStringContainsString($amount, $html, "invoices ({$ui}) show {$amount}");
            }
            foreach ($never as $amount) {
                $this->assertStringNotContainsString($amount, $html, "invoices ({$ui}) never show {$amount}");
            }
        }

        // the new admin's summary line under the title
        $this->assertStringContainsString(e('Total '.$shown[0].', of which tax '.$shown[1].' and fees '.$shown[2].'.'), (string) $this->actingAs($this->admin)->get(route('admin.invoices.index'))->getContent());
    }

    #[DataProvider('currencies')]
    public function test_the_payments_pages_write_every_amount_in_the_sites_currency(string $currency, array $shown, array $never): void
    {
        config(['cashier.currency' => $currency]);
        $this->payment();

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($this->admin)->get(route('admin.transactions.index'))->assertOk()->getContent();

            foreach ($shown as $amount) {
                $this->assertStringContainsString($amount, $html, "payments ({$ui}) show {$amount}");
            }
            // the amounts of this payment in any other currency (the page's scripts may use "$" for other things)
            foreach (['$', '€', '£', 'EUR '] as $symbol) {
                if (! str_starts_with($shown[0], $symbol)) {
                    foreach (['9.99', '1.00', '0.30'] as $number) {
                        $this->assertStringNotContainsString($symbol.$number, $html, "payments ({$ui}) never show {$symbol}{$number}");
                    }
                }
            }
        }
    }

    #[DataProvider('currencies')]
    public function test_the_plans_pages_write_the_price_in_the_sites_currency(string $currency, array $shown, array $never): void
    {
        config(['cashier.currency' => $currency]);
        $plan = Plan::query()->create(['name' => 'Gold monthly', 'price' => 999, 'duration' => 1]);

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($this->admin)->get(route('admin.plans.index'))->assertOk()->getContent();
            $this->assertStringContainsString($shown[0], $html, "plans ({$ui}) show {$shown[0]}");
            $this->assertStringNotContainsString('EUR 10', $html, "plans ({$ui}): not the old cast, rounded to the euro");
        }

        config(['admin.ui' => 'v2']);
        $this->assertStringContainsString(e($shown[0].' per month'), (string) $this->actingAs($this->admin)->get(route('admin.plans.edit', $plan))->assertOk()->getContent());
    }

    #[DataProvider('currencies')]
    public function test_money_and_home_write_the_earnings_in_the_sites_currency(string $currency, array $shown, array $never): void
    {
        config(['cashier.currency' => $currency, 'admin.ui' => 'v2', 'site.premium_enabled' => true]);
        $this->payment();

        // Money: this month and all time, each 9.99 (one payment of 999 cents).
        $money = (string) $this->actingAs($this->admin)->get(route('admin.money.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($money, '<b>'.$shown[0].'</b>'), "Money: this month and all time {$shown[0]}");
        // Home: the earnings of the last 7 days.
        $home = (string) $this->actingAs($this->admin)->get(route('admin.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e($shown[0]), $home, "Home's Earnings: {$shown[0]}");

        foreach (['money' => $money, 'home' => $home] as $page => $html) {
            foreach (['EUR', 'USD', 'GBP'] as $code) {
                $this->assertStringNotContainsString('9.99 '.$code, $html, "{$page}: not the currency code after the number");
            }
        }
    }

    public function test_a_currency_without_cents_is_written_in_whole_units(): void
    {
        // Stripe stores yen as whole yen: 500 is ¥500, never ¥5.00
        config(['cashier.currency' => 'jpy', 'site.premium_enabled' => true]);
        $this->payment(500, 0, 0);

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            foreach (['admin.invoices.index', 'admin.transactions.index'] as $route) {
                $html = (string) $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();
                $this->assertStringContainsString('¥500', $html, "{$route} ({$ui})");
                $this->assertStringNotContainsString('¥5.00', $html, "{$route} ({$ui})");
            }
        }

        // The new admin's Money and Home (both said "5.00 JPY").
        config(['admin.ui' => 'v2']);
        foreach (['admin.money.index', 'admin.index'] as $route) {
            $html = (string) $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString(e('¥500'), $html, $route);
            $this->assertStringNotContainsString('5.00 JPY', $html, $route);
            $this->assertStringNotContainsString('¥5', str_replace('¥500', '', $html), $route);
        }

        // The old admin's chart counts in the currency's own units: 500 yen is 500, 999 euro cents 9.99.
        $this->assertSame(500.0, PremiumAvailability::units(500));
        $this->assertSame(9.99, PremiumAvailability::units(999, 'eur'));
        $this->assertSame(0.0, PremiumAvailability::units(0, 'usd'));
        $this->assertSame(1234.0, PremiumAvailability::units(1234, 'KRW'));
    }

    private function payment(int $amount = 999, int $tax = 100, int $fee = 30): void
    {
        $member = User::factory()->create(['username' => 'paying_member']);
        DB::table('transactions')->insert([
            'payment_provider' => 'stripe', 'stripe_id' => 'pi_currency_1', 'amount' => $amount, 'tax' => $tax, 'fee' => $fee,
            'date' => now(), 'user_id' => $member->id, 'name' => 'Paying Member', 'address' => 'Street 1', 'city' => 'Town',
            'postal_code' => '1000', 'country' => 'de',
        ]);
    }
}
