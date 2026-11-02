<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\PremiumAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R1-007: 1.2.3 closes Stripe checkout without the webhook signing secret and PayPal checkout without the webhook id
 * (PremiumAvailability::stripeReady() / paypalReady()). The admin says the same as the Premium page:
 *  - Money > Earnings: "Connect card (Stripe) or PayPal payments" is done only when a checkout can open (keys AND the
 *    webhook value), names the missing webhook value, and a plan counts only with the id of a connected service;
 *  - Money > Payment setup: a warning callout names what is missing, with the webhook address and a link to the field;
 *  - Home: "Needs you" says members cannot buy premium right now.
 * A site that never entered a payment key gets none of these (nothing was set up, nothing is broken).
 */
class AdminCheckoutStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.ui' => 'v2',
            'app.locale' => 'en',
            'site.premium_enabled' => true,
            'site.stripe_enabled' => true,
            'site.paypal_enabled' => true,
            'cashier.key' => '',
            'cashier.secret' => '',
            'cashier.webhook.secret' => '',
            'services.paypal.client_id' => '',
            'services.paypal.client_secret' => '',
            'services.paypal.webhook_id' => '',
        ]);
    }

    public function test_stripe_keys_without_the_signing_secret_are_not_connected(): void
    {
        Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month']);
        PremiumAvailability::forget();
        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout']);

        $this->assertSame(['stripe'], PremiumAvailability::missingWebhooks());
        $this->assertFalse(PremiumAvailability::canSell());

        $html = $this->actingAs($this->admin())->get('/admin/money')->assertOk()->getContent();
        $connect = $this->step($html, 'Connect card (Stripe) or PayPal payments');

        $this->assertStringContainsString('To do', $connect, 'the checklist no longer says Done with the keys alone');
        $this->assertStringContainsString('the webhook signing secret is missing', $connect);
        $this->assertStringContainsString('#pay-STRIPE_WEBHOOK_SECRET', $connect, 'the step opens the missing field');
        $this->assertStringContainsString('data-money-checkout="closed"', $html);
        $this->assertStringContainsString('Members cannot buy premium yet', $html);

        // The Premium page agrees: checkout is closed.
        $this->assertStringContainsString('To do', $this->step($html, 'Give a plan the price ID of a connected payment service'));
    }

    public function test_every_value_of_a_service_makes_it_connected_and_premium_can_be_bought(): void
    {
        Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month']);
        PremiumAvailability::forget();
        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout', 'cashier.webhook.secret' => 'whsec_checkout']);

        $html = $this->actingAs($this->admin())->get('/admin/money')->assertOk()->getContent();

        $this->assertStringContainsString('Done', $this->step($html, 'Connect card (Stripe) or PayPal payments'));
        $this->assertStringContainsString('Done', $this->step($html, 'Give a plan the price ID of a connected payment service'));
        $this->assertStringContainsString('data-money-checkout="open"', $html);
        $this->assertStringNotContainsString('data-step-note', $html);
        $this->assertSame([], PremiumAvailability::setupProblems());
    }

    public function test_paypal_keys_without_the_webhook_id_are_named(): void
    {
        config(['services.paypal.client_id' => 'client-id-12345', 'services.paypal.client_secret' => 'client-secret-12345']);

        $html = $this->actingAs($this->admin())->get('/admin/money')->assertOk()->getContent();
        $connect = $this->step($html, 'Connect card (Stripe) or PayPal payments');

        $this->assertStringContainsString('To do', $connect);
        $this->assertStringContainsString('the webhook ID is missing', $connect);
        $this->assertStringContainsString('#pay-PAYPAL_WEBHOOK_ID', $connect);
    }

    public function test_payment_setup_says_why_checkout_is_closed(): void
    {
        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout']);

        $this->actingAs($this->admin())->get('/admin/money/setup')
            ->assertOk()
            ->assertSee('data-checkout-status', false)
            ->assertSee('Members cannot buy premium right now')
            ->assertSee('the webhook signing secret is missing, so members cannot pay by card')
            ->assertSee(route('stripe_webhook'))
            ->assertSee('href="#pay-STRIPE_WEBHOOK_SECRET"', false)
            ->assertSee('Add the webhook signing secret')
            // the field it links to, and the address to give Stripe next to it
            ->assertSee('id="STRIPE_WEBHOOK_SECRET-row"', false)
            ->assertSee('data-webhook-url', false);
    }

    public function test_the_webhook_address_of_the_callout_is_copied_without_invisible_characters(): void
    {
        // The address the owner copies into the Stripe / PayPal dashboard: in a <code dir="ltr"> of its own (left to
        // right on any page), never between the isolate characters U+2066 / U+2069 that a selection copies along.
        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout',
            'services.paypal.client_id' => 'paypal-client-id-checkout', 'services.paypal.client_secret' => 'paypal-client-secret-checkout']);
        $admin = $this->admin();

        foreach (['en', 'ar'] as $locale) {
            $html = (string) $this->actingAs($admin)->withSession(['app_locale' => $locale])->get('/admin/money/setup')->assertOk()->getContent();
            $callout = $this->callout($html);

            foreach (['stripe_webhook', 'paypal_webhook'] as $route) {
                $this->assertStringContainsString('<code class="ad-checkout-url" dir="ltr" translate="no">'.e(route($route)).'</code>', $callout, $locale.' '.$route);
            }
            $this->assertStringNotContainsString("\u{2066}", $callout, $locale);
            $this->assertStringNotContainsString("\u{2069}", $callout, $locale);
        }

        // Right to left, the key prefix keeps its order with markup too (the English sentence until lang/ar.json has it).
        $this->assertStringContainsString('<bdi dir="ltr">whsec_</bdi>', $callout);
    }

    public function test_payment_setup_is_quiet_when_nothing_or_everything_is_set_up(): void
    {
        $admin = $this->admin();

        // A new site: no key at all.
        $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertDontSee('data-checkout-status', false);

        // Everything there: a plan with the Stripe price id and every Stripe value.
        Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month']);
        PremiumAvailability::forget();
        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout', 'cashier.webhook.secret' => 'whsec_checkout']);
        $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertDontSee('data-checkout-status', false);

        // Premium switched off: nothing is for sale on purpose.
        config(['cashier.webhook.secret' => '', 'site.premium_enabled' => false]);
        $this->actingAs($admin)->get('/admin/money/setup')->assertOk()->assertDontSee('data-checkout-status', false);
    }

    public function test_home_needs_you_names_a_closed_checkout(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Members cannot buy premium right now');

        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout']);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Members cannot buy premium right now')
            ->assertSee('the webhook signing secret is missing')
            ->assertSee('/admin/money/setup#pay-STRIPE_WEBHOOK_SECRET', false);
    }

    public function test_plans_without_the_id_of_a_connected_service_are_named(): void
    {
        // Every Stripe value, but the only plan has a PayPal plan id only.
        Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => null, 'paypal_plan_id' => 'P-ONLYPAYPAL']);
        PremiumAvailability::forget();
        config(['cashier.key' => 'pk_test_checkout', 'cashier.secret' => 'sk_test_checkout', 'cashier.webhook.secret' => 'whsec_checkout']);

        $this->assertTrue(PremiumAvailability::plansUnlinked());

        $admin = $this->admin();
        $money = $this->actingAs($admin)->get('/admin/money')->assertOk()->getContent();
        $this->assertStringContainsString('Done', $this->step($money, 'Connect card (Stripe) or PayPal payments'));
        $this->assertStringContainsString('To do', $this->step($money, 'Give a plan the price ID of a connected payment service'));

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('No plan has the Stripe price ID or the PayPal plan ID of a connected payment service')
            ->assertSee(route('admin.plans.index', [], false), false);
    }

    public function test_a_refused_key_says_what_it_should_look_like(): void
    {
        $this->actingAs($this->admin())
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_WEBHOOK_SECRET' => 'not-a-secret', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.STRIPE_WEBHOOK_SECRET.0', 'A webhook signing secret starts with whsec_.');

        $this->actingAs($this->admin())
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_KEY' => 'sk_test_wrong_field', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.STRIPE_KEY.0', 'A publishable key starts with pk_test_ or pk_live_.');
    }

    public function test_a_refused_key_keeps_its_prefix_in_order_on_a_right_to_left_page(): void
    {
        // The page script shows these messages as plain text (the field's error and a toast): on a right-to-left page
        // the prefix is isolated in the message itself, or "whsec_." reads "_whsec.".
        $answer = $this->actingAs($this->admin())->withSession(['app_locale' => 'ar'])
            ->putJson(route('admin.money.setup.keys'), ['STRIPE_WEBHOOK_SECRET' => 'not-a-secret', 'STRIPE_KEY' => 'sk_test_wrong_field', 'password' => 'x'])
            ->assertStatus(422);

        $secret = (string) $answer->json('errors.STRIPE_WEBHOOK_SECRET.0');
        $this->assertStringContainsString("\u{2066}whsec_\u{2069}", $secret);
        $this->assertStringContainsString("\u{2066}pk_test_\u{2069}", (string) $answer->json('errors.STRIPE_KEY.0'));
        $this->assertStringContainsString("\u{2066}pk_live_\u{2069}", (string) $answer->json('errors.STRIPE_KEY.0'));
        $this->assertSame((string) $answer->json('errors.'.array_key_first((array) $answer->json('errors')).'.0'), (string) $answer->json('message'));

        // A form posted without the script: the page shows the error with markup instead.
        $this->actingAs($this->admin())->withSession(['app_locale' => 'ar'])
            ->from('/admin/money/setup')
            ->put(route('admin.money.setup.keys'), ['STRIPE_WEBHOOK_SECRET' => 'not-a-secret', 'password' => 'x'])
            ->assertRedirect(route('admin.money.setup'));
        $page = (string) $this->actingAs($this->admin())->withSession(['app_locale' => 'ar'])->get('/admin/money/setup')->getContent();
        $this->assertMatchesRegularExpression('#data-error>[^<]*<bdi dir="ltr">whsec_</bdi>#u', $page);
    }

    /** The Payment setup callout (data-checkout-status) of a page. */
    private function callout(string $html): string
    {
        $start = strpos($html, 'data-checkout-status');
        $this->assertNotFalse($start, 'no checkout callout');

        return substr($html, $start, 4000);
    }

    /** The <li> of one checklist step (by its label). */
    private function step(string $html, string $label): string
    {
        foreach (explode('<li class="ad-sec-pad', $html) as $part) {
            $item = explode('</li>', $part)[0];

            if (str_contains($item, e($label))) {
                return $item;
            }
        }

        $this->fail('no checklist step "'.$label.'"');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
