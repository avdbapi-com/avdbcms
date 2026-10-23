<?php

namespace Tests\Feature\Premium;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Plan;
use Laravel\Cashier\SubscriptionBuilder;
use Mockery\MockInterface;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Mockery;
class PremiumPageTest extends TestCase
{
    use RefreshDatabase;

    public User $user;
    public Plan $plan;

    public function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->stripeUser()->create();
        $this->plan = Plan::factory()->create();

        Notification::fake();
    }

    public function test_premium_page_view(): void
    {
        $this
            ->get(route('pages.premium'))
            ->assertOk();
    }

    public function test_premium_page_uses_admin_configured_copy_and_rows(): void
    {
        $this->withoutVite();

        config([
            'site.premium_page_kicker' => 'VIP access',
            'site.premium_page_title' => ':site Studio Plus',
            'site.premium_page_subtitle' => 'Admin editable premium subtitle.',
            'site.premium_checkout_unavailable_message' => 'Admin checkout unavailable.',
            'site.premium_billing_heading' => 'Admin billing',
            'site.premium_annual_savings_text' => 'Admin annual savings text.',
            'site.premium_feature_uploads_title' => 'Admin upload power',
            'site.premium_feature_downloads_enabled' => false,
            'site.premium_feature_ad_free_icon' => 'fa-ban',
            'site.premium_comparison_title' => 'Admin plan matrix',
            'site.premium_comparison_downloads_row_enabled' => false,
            'site.premium_comparison_downloads_label' => 'Admin offline saves',
            'site.premium_comparison_ads_row_enabled' => false,
            'site.premium_unlimited_label' => 'No limit',
            'site.premium_comparison_enabled' => true,
        ]);

        $html = $this->get(route('pages.premium'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('VIP access', $html);
        $this->assertStringContainsString(config('app.name') . ' Studio Plus', $html);
        $this->assertStringContainsString('Admin editable premium subtitle.', $html);
        $this->assertStringContainsString('Admin checkout unavailable.', $html);
        $this->assertStringContainsString('Admin billing', $html);
        $this->assertStringContainsString('Admin annual savings text.', $html);
        $this->assertStringContainsString('Admin upload power', $html);
        $this->assertStringContainsString('fa-ban', $html);
        $this->assertStringContainsString('Admin plan matrix', $html);
        $this->assertStringContainsString('No limit', $html);
        $this->assertStringNotContainsString('Save videos for when you really need them', $html);
        $this->assertStringNotContainsString('Admin offline saves', $html);
        $this->assertStringNotContainsString('Ads on videos', $html);
    }

    public function test_premium_subscribe(): void
    {
        config([
            'cashier.key' => 'pk_test_checkout',
            'cashier.secret' => 'sk_test_checkout',
            'cashier.webhook.secret' => 'whsec_test_checkout',
            'site.premium_enabled' => true,
            'site.stripe_enabled' => true,
        ]);

        $user = Mockery::mock($this->user)->makePartial();

        $user->shouldReceive('newSubscription')
            ->once()
            ->with($this->plan->id, $this->plan->stripe_id)
            ->andReturn(
                Mockery::mock(SubscriptionBuilder::class, function (MockInterface $mock) {
                    $mock
                        ->shouldReceive('checkout')
                        ->once()
                        ->andReturn(redirect('https://checkout.stripe.com'));
                })
            );

        $this
            ->actingAs($user)
            ->get(route('premium.subscribe', $this->plan))
            ->assertRedirect('https://checkout.stripe.com');
    }

}
