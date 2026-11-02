<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeService;
use App\Services\SubscriptionCanceller;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Security plan S31: deleting an account and the admin's cancel / delete stop the billing at Stripe or PayPal first, so
 * a member is not charged again. The site's own change goes on whatever the provider answers; when the provider does not
 * confirm, the admin sees a warning and the log names the subscription to cancel by hand. (No real provider is called:
 * StripeService is mocked, PayPal answers through Http::fake.)
 */
class SubscriptionCancellerTest extends TestCase
{
    use RefreshDatabase;

    private const PAYPAL = 'https://api-m.sandbox.paypal.com';

    private const WARNING = 'The payment provider did not confirm the cancellation. Cancel this subscription in the Stripe or PayPal dashboard too.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.paypal.client_id' => 'client-id-test', 'services.paypal.client_secret' => 'client-secret-test', 'services.paypal.mode' => 'sandbox']);
    }

    public function test_a_member_who_deletes_the_account_stops_the_stripe_billing_first(): void
    {
        $member = User::factory()->create(['password' => 'correct-password']);
        $premium = $this->premium($member, 'stripe', 'sub_member_delete');
        $this->mock(StripeService::class, fn (MockInterface $mock) => $mock->shouldReceive('cancelSubscription')->once()->with('sub_member_delete'));

        $this->actingAs($member)->delete(route('user.delete'), ['current_password' => 'correct-password'])->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('premiums', ['id' => $premium->id]);
    }

    /** A member with more than one subscription: one the provider does not confirm never keeps the others billing. */
    public function test_a_deletion_tries_every_subscription_also_after_one_fails(): void
    {
        $member = User::factory()->create(['password' => 'correct-password']);
        $first = $this->premium($member, 'stripe', 'sub_first_fails');
        $second = $this->premium($member, 'stripe', 'sub_second');
        $paypal = $this->premium($member, 'paypal', 'I-THIRD');
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'token-test', 'expires_in' => 3600]),
            self::PAYPAL.'/v1/billing/subscriptions/*' => Http::response(null, 204),
        ]);
        $called = [];
        $this->mock(StripeService::class, function (MockInterface $mock) use (&$called): void {
            $mock->shouldReceive('cancelSubscription')->twice()->andReturnUsing(function (string $id) use (&$called): void {
                $called[] = $id;

                if ($id === 'sub_first_fails') {
                    throw new RuntimeException('Stripe is down');
                }
            });
        });
        Log::spy();

        $this->actingAs($member)->delete(route('user.delete'), ['current_password' => 'correct-password'])->assertRedirect();

        $this->assertSame(['sub_first_fails', 'sub_second'], $called);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::PAYPAL.'/v1/billing/subscriptions/I-THIRD/cancel');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'could not be cancelled')
            && ($context['provider_id'] ?? null) === 'sub_first_fails');
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        foreach ([$first, $second, $paypal] as $row) {
            $this->assertDatabaseMissing('premiums', ['id' => $row->id]);
        }
    }

    public function test_an_admin_delete_of_a_member_stops_the_billing_too(): void
    {
        $member = User::factory()->create();
        $this->premium($member, 'stripe', 'sub_admin_delete');
        $this->mock(StripeService::class, fn (MockInterface $mock) => $mock->shouldReceive('cancelSubscription')->once()->with('sub_admin_delete'));

        $this->actingAs(User::factory()->admin()->create())->delete(route('admin.users.delete', $member))->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    /** @return array<string, array{0: int, 1: bool}> PayPal's answer to the cancel and whether the admin is warned */
    public static function paypalAnswers(): array
    {
        return [
            'cancelled (204)' => [204, false],
            'no longer active (422)' => [422, false],
            'PayPal fails (500)' => [500, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paypalAnswers')]
    public function test_the_admin_cancel_of_a_paypal_subscription_reaches_paypal(int $status, bool $warns): void
    {
        $premium = $this->premium(User::factory()->create(), 'paypal', 'I-ADMIN'.$status);
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'token-test', 'expires_in' => 3600]),
            self::PAYPAL.'/v1/billing/subscriptions/*' => Http::response($status === 204 ? null : ['name' => 'X'], $status),
        ]);
        Log::spy();

        $response = $this->actingAs(User::factory()->admin()->create())->post(route('admin.subscriptions.cancel', $premium));

        $response->assertRedirect(route('admin.subscriptions.index'))->assertSessionHas('success');
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::PAYPAL.'/v1/billing/subscriptions/I-ADMIN'.$status.'/cancel'
            && $request->method() === 'POST' && filled($request['reason'] ?? null));
        $this->assertSame('canceled', $premium->fresh()->stripe_status, 'canceled here in every case');

        if ($warns) {
            $response->assertSessionHasErrors(['payment_provider' => self::WARNING]);
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'could not be cancelled')
                && ($context['provider_id'] ?? null) === 'I-ADMIN'.$status);
        } else {
            $response->assertSessionDoesntHaveErrors();
        }
    }

    public function test_the_admin_delete_of_a_subscription_and_of_a_premium_record_stop_the_billing(): void
    {
        $subscription = $this->premium(User::factory()->create(), 'stripe', 'sub_admin_destroy');
        $record = $this->premium(User::factory()->create(), 'stripe', 'sub_admin_record');
        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_admin_destroy');
            $mock->shouldReceive('cancelSubscription')->once()->with('sub_admin_record');
        });
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete(route('admin.subscriptions.delete', $subscription))->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->actingAs($admin)->delete(route('admin.premiums.destroy', $record->id))->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertDatabaseMissing('premiums', ['id' => $subscription->id]);
        $this->assertDatabaseMissing('premiums', ['id' => $record->id]);
    }

    public function test_without_provider_keys_everything_still_finishes_and_warns(): void
    {
        config(['services.paypal.client_id' => null, 'services.paypal.client_secret' => null]);
        $this->mock(StripeService::class, fn (MockInterface $mock) => $mock->shouldReceive('cancelSubscription')->andThrow(new RuntimeException('No API key provided.')));
        Log::spy();
        $admin = User::factory()->admin()->create();

        $stripe = $this->premium(User::factory()->create(), 'stripe', 'sub_no_keys');
        $this->actingAs($admin)->post(route('admin.subscriptions.cancel', $stripe))->assertRedirect()
            ->assertSessionHasErrors(['payment_provider' => self::WARNING]);
        $this->assertSame('canceled', $stripe->fresh()->stripe_status);

        $paypal = $this->premium(User::factory()->create(), 'paypal', 'I-NOKEYS');
        $this->actingAs($admin)->delete(route('admin.premiums.destroy', $paypal->id))->assertRedirect()
            ->assertSessionHasErrors(['payment_provider' => self::WARNING]);
        $this->assertDatabaseMissing('premiums', ['id' => $paypal->id]);

        $member = User::factory()->create();
        $this->premium($member, 'stripe', 'sub_no_keys_member');
        $this->actingAs($admin)->delete(route('admin.users.delete', $member))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $member->id]);

        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_subscription_that_is_already_over_or_has_no_provider_id_calls_nobody(): void
    {
        Http::fake();
        $this->mock(StripeService::class, fn (MockInterface $mock) => $mock->shouldReceive('cancelSubscription')->never());
        $canceller = app(SubscriptionCanceller::class);

        $this->assertTrue($canceller->cancelAtProvider($this->premium(User::factory()->create(), 'stripe', 'sub_over', 'canceled'), 'x'));
        $this->assertTrue($canceller->cancelAtProvider($this->premium(User::factory()->create(), 'stripe', 'sub_expired', 'incomplete_expired'), 'x'));
        $this->assertTrue($canceller->cancelAtProvider($this->premium(User::factory()->create(), 'paypal', null), 'x'));
        $this->assertTrue($canceller->cancelAtProvider($this->premium(User::factory()->create(), 'stripe', ''), 'x'));
        Http::assertNothingSent();
    }

    private function premium(User $member, string $provider, ?string $providerId, string $status = 'active'): Subscription
    {
        return Subscription::factory()->for($member)->for(Plan::factory()->create())->create([
            'payment_provider' => $provider,
            'stripe_id' => $providerId,
            'stripe_status' => $status,
            'ends_at' => null,
        ]);
    }
}
