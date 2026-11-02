<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Money › Subscribers (Subscriptions and Premium records) and Money › Payments & invoices write the payment services
 * as their brands: "PayPal", never "Paypal" (Str::headline of the stored "paypal"), in the new and the old admin; the
 * Card column shows "•••• 4242" for a card and "PayPal" (without dots) for a PayPal subscription.
 */
class AdminPaymentProviderLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function admins(): array
    {
        return ['new admin' => ['v2'], 'old admin' => ['v1']];
    }

    #[DataProvider('admins')]
    public function test_the_money_lists_write_paypal_and_stripe_like_their_brands(string $ui): void
    {
        config(['admin.ui' => $ui]);
        $admin = User::factory()->admin()->create();
        $this->seedPayments();

        foreach (['admin.subscriptions.index', 'admin.premiums.index', 'admin.transactions.index'] as $route) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
            $cells = $ui === 'v2' ? ['<small>PayPal', '<small>Stripe'] : ['>PayPal</span>', '>Stripe</span>'];

            foreach ($cells as $cell) {
                $this->assertStringContainsString($cell, $html, "{$route} ({$ui}) shows {$cell}");
            }
            $this->assertStringNotContainsString('Paypal', $html, "{$route} ({$ui}) never writes Paypal");
        }
    }

    public function test_the_new_subscriptions_list_shows_card_digits_with_dots_and_paypal_without(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->seedPayments();

        $html = $this->actingAs($admin)->get(route('admin.subscriptions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td class="hide-tab">•••• 4242</td>', $html);
        $this->assertStringContainsString('<td class="hide-tab">PayPal</td>', $html);
        $this->assertStringContainsString('<td class="hide-tab">-</td>', $html, 'a Stripe subscription without a card shows -');
        $this->assertStringNotContainsString('•••• PayPal', $html);
    }

    public function test_the_payments_list_names_the_service_country_and_payment_id(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $this->seedPayments();

        $html = $this->actingAs($admin)->get(route('admin.transactions.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<small>PayPal &middot; GB\s+&middot; <code>EXAMPLE-SALE-1</code></small>~', $html);
        $this->assertMatchesRegularExpression('~<small>Stripe &middot; DE\s+&middot; <code>pi_example_1</code></small>~', $html);
    }

    private function seedPayments(): void
    {
        $plan = Plan::query()->create(['name' => 'Gold monthly', 'price' => 999, 'duration' => 1]);
        $paypal = User::factory()->create(['username' => 'pays_with_paypal']);
        $card = User::factory()->create(['username' => 'pays_by_card']);
        $noCard = User::factory()->create(['username' => 'pays_without_card']);
        $row = fn (User $user, string $provider, string $id, string $last4): array => [
            'payment_provider' => $provider, 'stripe_status' => 'active', 'user_id' => $user->id, 'plan_id' => $plan->id,
            'stripe_id' => $id, 'card_last4' => $last4, 'card_expired_at' => now()->addYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ];

        // As the app stores them: PayPalSubscriptionService writes card_last4 "PayPal", the Stripe webhook the
        // card's last 4 digits (or "" for a payment method without a card).
        DB::table('premiums')->insert([
            $row($paypal, 'paypal', 'I-EXAMPLE1', 'PayPal'),
            $row($card, 'stripe', 'sub_example_1', '4242'),
            $row($noCard, 'stripe', 'sub_example_2', ''),
        ]);
        $payment = fn (User $user, string $provider, string $id, string $country): array => [
            'payment_provider' => $provider, 'stripe_id' => $id, 'amount' => 999, 'tax' => 0, 'fee' => 30, 'date' => now(),
            'user_id' => $user->id, 'name' => $user->username, 'address' => '', 'city' => '', 'postal_code' => '', 'country' => $country,
        ];
        DB::table('transactions')->insert([
            $payment($paypal, 'paypal', 'EXAMPLE-SALE-1', 'gb'),
            $payment($card, 'stripe', 'pi_example_1', 'de'),
        ]);
    }
}
