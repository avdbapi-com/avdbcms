<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Security plan S27: the Stripe webhook fails closed. Without a signing secret every event is refused (Cashier would
 * check a signature made with an empty key, which anybody can compute), with a secret only events signed with it pass,
 * an id that is not a non-empty string never reaches a query (a number matches any row in MySQL/MariaDB, a missing id
 * must not become "stripe_id IS NULL"), and Cashier's own unused webhook route is gone.
 */
class StripeWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getUserCard')->andReturn(null);
            $mock->shouldReceive('getTransactionFee')->andReturn(0);
        });
        $this->mock(InvoiceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generate')->never();
        });
    }

    public function test_without_a_signing_secret_every_event_is_refused(): void
    {
        $row = $this->stripePremium();

        foreach (['', null, '   '] as $secret) {
            config(['cashier.webhook.secret' => $secret]);

            $this->signedPost($this->deletedEvent($row->stripe_id), '')->assertForbidden();
            $this->assertSame('active', $row->fresh()->stripe_status, 'secret '.var_export($secret, true));
        }
    }

    public function test_with_a_secret_only_events_signed_with_it_pass(): void
    {
        config(['cashier.webhook.secret' => self::SECRET]);
        $row = $this->stripePremium();

        $this->signedPost($this->deletedEvent($row->stripe_id), '')->assertForbidden();
        $this->assertSame('active', $row->fresh()->stripe_status);

        $this->signedPost($this->deletedEvent($row->stripe_id), self::SECRET)->assertNoContent();
        $this->assertSame('canceled', $row->fresh()->stripe_status);
    }

    public function test_an_id_that_is_not_a_string_changes_nothing(): void
    {
        config(['cashier.webhook.secret' => self::SECRET]);
        $row = $this->stripePremium();
        // A premium row without a Stripe id (given by an administrator): an event without an id must not reach it.
        $granted = Subscription::factory()->for(User::factory()->create())->for(Plan::factory()->create())
            ->create(['payment_provider' => 'stripe', 'stripe_id' => null, 'stripe_status' => 'active']);

        $events = [
            ['type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 0, 'status' => 'canceled']]],
            ['type' => 'customer.subscription.deleted', 'data' => ['object' => ['status' => 'canceled']]],
            ['type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 0, 'status' => 'past_due', 'cancel_at_period_end' => true, 'current_period_end' => now()->addDay()->getTimestamp()]]],
            ['type' => 'customer.subscription.updated', 'data' => ['object' => ['status' => 'past_due']]],
            ['type' => 'invoice.payment_succeeded', 'data' => ['object' => ['id' => 'in_x', 'customer' => 0, 'subscription' => 0, 'amount_paid' => 999, 'charge' => 'ch_x']]],
            ['type' => 'invoice.payment_succeeded', 'data' => ['object' => ['id' => 0, 'customer' => $row->user->stripe_id, 'subscription' => $row->stripe_id, 'amount_paid' => 999]]],
            ['type' => 'customer.updated', 'data' => ['object' => ['id' => 0, 'invoice_settings' => ['default_payment_method' => 'pm_x']]]],
        ];

        DB::enableQueryLog();

        foreach ($events as $event) {
            $this->signedPost($event + ['id' => 'evt_'.md5(json_encode($event))], self::SECRET)->assertNoContent();
        }

        $this->assertSame('active', $row->fresh()->stripe_status);
        $this->assertNull($row->fresh()->ends_at);
        $this->assertSame('active', $granted->fresh()->stripe_status);
        $this->assertNull($granted->fresh()->ends_at);
        $this->assertDatabaseCount('transactions', 0);
        Notification::assertNothingSent();

        // No query ever compared a Stripe id with a number or with NULL.
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'stripe_id')) {
                $this->assertStringNotContainsString('"stripe_id" is null', $query['query']);
                $this->assertNotContains(0, $query['bindings'], $query['query']);
            }
        }
    }

    public function test_cashiers_own_webhook_route_is_gone(): void
    {
        $this->assertFalse(Route::has('cashier.webhook'));
        $this->assertFalse(Route::has('cashier.payment'));
        $this->assertSame(url('stripe-webhook'), route('stripe_webhook'));

        $status = $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.deleted'])->getStatusCode();
        $this->assertContains($status, [404, 405]);
    }

    private function stripePremium(): Subscription
    {
        $user = User::factory()->stripeUser()->create();

        return Subscription::factory()->for($user)->for(Plan::factory()->create())->create([
            'payment_provider' => 'stripe',
            'stripe_id' => 'sub_'.md5((string) $user->id),
            'stripe_status' => 'active',
            'ends_at' => null,
        ]);
    }

    private function deletedEvent(string $subscriptionId): array
    {
        return [
            'id' => 'evt_'.md5($subscriptionId),
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => $subscriptionId, 'status' => 'canceled', 'canceled_at' => now()->getTimestamp()]],
        ];
    }

    /** POST the event with a Stripe-Signature made with this key ('' = the empty key anybody can use). */
    private function signedPost(array $event, string $key): \Illuminate\Testing\TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $key);

        return $this->call('POST', '/stripe-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ], $payload);
    }
}
