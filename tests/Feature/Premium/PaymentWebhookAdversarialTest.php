<?php

namespace Tests\Feature\Premium;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Premium\Invoice;
use App\Services\InvoiceService;
use App\Services\PayPalService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Mockery\MockInterface;
use Tests\Fixtures\StripeApiFixtures;
use Tests\TestCase;

class PaymentWebhookAdversarialTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_zero_amount_invoice_is_ignored(): void
    {
        Notification::fake();

        $subscription = $this->stripeSubscription();
        $payload = StripeApiFixtures::invoicePaymentSucceeded($subscription);
        $payload['data']['object']['amount_paid'] = 0;

        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->never();
        });

        $this->withoutMiddleware(VerifyWebhookSignature::class)
            ->postJson(route('stripe_webhook'), $payload)
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_stripe_duplicate_invoice_payment_is_idempotent(): void
    {
        Notification::fake();

        $subscription = $this->stripeSubscription();
        $payload = StripeApiFixtures::invoicePaymentSucceeded($subscription);
        $payload['data']['object']['payment_intent'] = 'pi_replayed_payment';
        $payload['data']['object']['charge'] = 'ch_replayed_payment';
        $payload['data']['object']['amount_paid'] = 1299;

        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTransactionFee')
                ->once()
                ->with('ch_replayed_payment')
                ->andReturn(33);
        });
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->once();
        });

        $this->withoutMiddleware(VerifyWebhookSignature::class)
            ->postJson(route('stripe_webhook'), $payload)
            ->assertNoContent();

        $this->withoutMiddleware(VerifyWebhookSignature::class)
            ->postJson(route('stripe_webhook'), $payload)
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 1);
        Notification::assertSentToTimes($subscription->user, Invoice::class, 1);
    }

    public function test_stripe_invoice_with_mismatched_customer_and_subscription_is_ignored(): void
    {
        Notification::fake();

        $subscription = $this->stripeSubscription();
        $otherUser = User::factory()->stripeUser()->create();
        $payload = StripeApiFixtures::invoicePaymentSucceeded($subscription);
        $payload['data']['object']['customer'] = $otherUser->stripe_id;

        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->never();
        });

        $this->withoutMiddleware(VerifyWebhookSignature::class)
            ->postJson(route('stripe_webhook'), $payload)
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_paypal_sale_for_unknown_subscription_is_ignored(): void
    {
        Notification::fake();

        $this->mock(PayPalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('amountToCents')->never();
            $mock->shouldReceive('feeToCents')->never();
        });
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->never();
        });

        $this->postJson(route('paypal_webhook'), $this->paypalSalePayload('PAY-UNKNOWN', 'I-NOT-OURS'))
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_paypal_duplicate_sale_is_idempotent(): void
    {
        Notification::fake();

        $subscription = $this->paypalSubscription();

        $this->mock(PayPalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verifyWebhookSignature')->twice()->andReturnTrue();
            $mock->shouldReceive('amountToCents')->once()->andReturn(1299);
            $mock->shouldReceive('feeToCents')->once()->andReturn(59);
        });
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->once();
        });

        $payload = $this->paypalSalePayload('PAY-REPLAYED', $subscription->stripe_id);

        $this->postJson(route('paypal_webhook'), $payload)
            ->assertNoContent();

        $this->postJson(route('paypal_webhook'), $payload)
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 1);
        Notification::assertSentToTimes($subscription->user, Invoice::class, 1);
    }

    public function test_paypal_zero_amount_sale_is_ignored(): void
    {
        Notification::fake();

        $subscription = $this->paypalSubscription();

        $this->mock(PayPalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('amountToCents')->once()->andReturn(0);
            $mock->shouldReceive('feeToCents')->never();
        });
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->never();
        });

        $this->postJson(route('paypal_webhook'), $this->paypalSalePayload('PAY-ZERO', $subscription->stripe_id, '0.00'))
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();
    }

    private function stripeSubscription(): Subscription
    {
        $user = User::factory()->stripeUser()->create();
        $plan = Plan::factory()->create();

        return Subscription::factory()
            ->for($user)
            ->for($plan)
            ->create([
                'payment_provider' => 'stripe',
            ]);
    }

    private function paypalSubscription(): Subscription
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();

        return Subscription::factory()
            ->for($user)
            ->for($plan)
            ->create([
                'payment_provider' => 'paypal',
                'stripe_id' => 'I-'.fake()->regexify('[A-Z0-9]{12}'),
                'card_last4' => 'PayPal',
            ]);
    }

    private function paypalSalePayload(string $saleId, string $subscriptionId, string $amount = '12.99'): array
    {
        return [
            'id' => 'WH-'.fake()->regexify('[A-Z0-9]{12}'),
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => $saleId,
                'billing_agreement_id' => $subscriptionId,
                'amount' => [
                    'total' => $amount,
                    'currency' => 'USD',
                ],
                'transaction_fee' => [
                    'value' => '0.59',
                    'currency' => 'USD',
                ],
                'billing_address' => [
                    'country_code' => 'US',
                ],
                'create_time' => now()->toIso8601String(),
            ],
        ];
    }
}
