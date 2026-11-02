<?php

namespace Tests\Feature\Premium;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Security plan S08: the PayPal return page never moves another member's PayPal subscription to the signed-in member.
 * The member the checkout made the subscription for (custom_id) wins over whoever opens the return address, and a
 * row that already belongs to another member is never changed. (PayPalService is mocked: no call reaches PayPal.)
 */
class PayPalReturnOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_members_subscription_id_changes_nothing(): void
    {
        [$owner, $plan, $row] = $this->ownersPayPalSubscription();
        $attacker = User::factory()->create();
        $this->paypalAnswers($row->stripe_id, $owner, $plan);

        $this->actingAs($attacker)->get(route('premium.paypal.return', ['subscription_id' => $row->stripe_id]))
            ->assertRedirect(route('pages.premium'))
            ->assertSessionHas('info');

        $this->assertSame($owner->id, (int) $row->fresh()->user_id, 'the row stays with its owner');
        $this->assertSame(0, Subscription::query()->where('user_id', $attacker->id)->count(), 'the visitor got no subscription');
    }

    public function test_a_subscription_paypal_names_nobody_for_never_moves_either(): void
    {
        [$owner, $plan, $row] = $this->ownersPayPalSubscription();
        $attacker = User::factory()->create();
        // A subscription made outside the site's checkout: no custom_id, another PayPal address.
        $this->paypalAnswers($row->stripe_id, null, $plan);

        $this->actingAs($attacker)->get(route('premium.paypal.return', ['subscription_id' => $row->stripe_id]))
            ->assertRedirect(route('pages.premium'));

        $this->assertSame($owner->id, (int) $row->fresh()->user_id);
    }

    /** The welcome is on the Premium page itself (member design 6.16, OD-M10: never the home page with its ads). */
    public function test_the_owner_still_gets_the_welcome(): void
    {
        [$owner, $plan, $row] = $this->ownersPayPalSubscription();
        $this->paypalAnswers($row->stripe_id, $owner, $plan);

        $this->actingAs($owner)->get(route('premium.paypal.return', ['subscription_id' => $row->stripe_id]))
            ->assertRedirect(route('pages.premium', ['checkout' => 'success']));

        $this->assertSame('active', $row->fresh()->stripe_status);
    }

    public function test_a_new_subscription_is_made_for_the_member_who_checked_out(): void
    {
        $member = User::factory()->create();
        $plan = Plan::factory()->create(['paypal_plan_id' => 'P-PLAN-NEW']);
        $this->paypalAnswers('I-NEWSUBSCRIPTION', $member, $plan);

        $this->actingAs($member)->get(route('premium.paypal.return', ['subscription_id' => 'I-NEWSUBSCRIPTION']))
            ->assertRedirect(route('pages.premium', ['checkout' => 'success']));

        $this->assertSame(1, Subscription::query()->where('user_id', $member->id)->where('stripe_id', 'I-NEWSUBSCRIPTION')->count());
    }

    /** @return array{0: User, 1: Plan, 2: Subscription} */
    private function ownersPayPalSubscription(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::factory()->create(['paypal_plan_id' => 'P-PLAN-'.$owner->id]);
        $row = Subscription::factory()->for($owner)->for($plan)->create([
            'payment_provider' => 'paypal',
            'stripe_id' => 'I-OWNER'.$owner->id,
            'stripe_status' => 'active',
            'card_last4' => 'PayPal',
        ]);

        return [$owner, $plan, $row];
    }

    private function paypalAnswers(string $subscriptionId, ?User $for, Plan $plan): void
    {
        $resource = [
            'id' => $subscriptionId,
            'plan_id' => $plan->paypal_plan_id,
            'status' => 'ACTIVE',
            'subscriber' => ['email_address' => 'payer-'.$subscriptionId.'@example.test'],
            'billing_info' => ['next_billing_time' => now()->addMonth()->toIso8601String()],
        ];

        if ($for !== null) {
            $resource['custom_id'] = 'avdbclip:user:'.$for->id.':plan:'.$plan->id;
        }

        $this->mock(PayPalService::class, function (MockInterface $mock) use ($subscriptionId, $resource) {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('showSubscription')->with($subscriptionId)->andReturn($resource);
        });
    }
}
