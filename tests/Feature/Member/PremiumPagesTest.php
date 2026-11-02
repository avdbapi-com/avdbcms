<?php

namespace Tests\Feature\Member;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PayPalService;
use App\Support\PremiumAvailability;
use App\Support\PremiumPageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\SubscriptionBuilder;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The Premium page and its states (member design 6.16): one plain message per state, never a payment
 * service's name in an error (account-32), prices with the currency symbol (account-51), "You already have Premium"
 * as a real sentence (account-2), the PayPal return message (account-16), the checkouts back to the Premium page
 * itself (OD-M10), the welcome sheet, Free and Premium as a table and as stacked rows (account-5), Downloads off by
 * default (OD-M7).
 */
class PremiumPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'site.default_locale' => 'en',
            'app.locale' => 'en',
            'cashier.currency' => 'eur',
            'plans.trial_period.period' => 0,
            'site.premium_enabled' => true,
            'site.stripe_enabled' => true,
            'site.paypal_enabled' => false,
            'cashier.key' => '',
            'cashier.secret' => '',
            'cashier.webhook.secret' => '',
        ]);
        $this->withoutVite();
    }

    private function stripeKeys(): void
    {
        config([
            'cashier.key' => 'pk_test_page',
            'cashier.secret' => 'sk_test_page',
            'cashier.webhook.secret' => 'whsec_test_page',
        ]);
    }

    /** @return array{0: Plan, 1: Plan} */
    private function plans(): array
    {
        return [
            Plan::factory()->create(['name' => 'monthly', 'duration' => 1, 'price' => 500, 'stripe_id' => 'price_month']),
            Plan::factory()->create(['name' => 'yearly', 'duration' => 12, 'price' => 5000, 'stripe_id' => 'price_year']),
        ];
    }

    public function test_nothing_to_buy_shows_one_plain_message_and_no_buttons(): void
    {
        [$monthly] = $this->plans();
        config(['site.premium_checkout_unavailable_message' => 'Premium opens soon.']);

        $html = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('Premium is not available yet')), $html);
        $this->assertStringContainsString('Premium opens soon.', $html);
        $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $html, 'no checkout button while nothing can be bought');
        $this->assertStringNotContainsString('name="billing"', $html, 'no Monthly / Yearly choice either');
        $this->assertStringNotContainsString('Stripe', strip_tags($html));
        $this->assertFalse(PremiumAvailability::canSell());
    }

    public function test_prices_carry_the_currency_symbol_and_the_yearly_plan_its_saving(): void
    {
        $this->stripeKeys();
        [$monthly, $yearly] = $this->plans();

        $html = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertTrue(PremiumAvailability::canSell());
        $this->assertStringContainsString('<bdi>€5</bdi>', $html, 'account-51');
        $this->assertStringContainsString('<bdi>€50</bdi>', $html);
        $this->assertStringContainsString(e(__(':price a month', ['price' => '€4.17'])), $html);
        $this->assertStringContainsString(e(trans_choice('{1} Save :count month|[2,*] Save :count months', 2, ['count' => 2])), $html);
        $this->assertStringContainsString(route('premium.subscribe', $monthly), $html);
        $this->assertStringContainsString(route('premium.subscribe', $yearly), $html);
        $this->assertStringContainsString('name="billing"', $html, 'the Monthly / Yearly choice when both can be bought');
        $this->assertStringNotContainsString('EUR 5', $html);

        // with a free trial the yearly card still says what it saves (mockup premium); the monthly card shows the trial
        config(['plans.trial_period.period' => 30, 'site.premium_trial_badge_template' => ':days days free']);
        $trial = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($trial, '<span class="mk-badge mk-badge--premium">'.e(trans_choice('{1} Save :count month|[2,*] Save :count months', 2, ['count' => 2])).'</span>'));
        $this->assertSame(1, substr_count($trial, '<span class="mk-badge mk-badge--premium">30 days free</span>'));
    }

    public function test_a_plan_without_its_price_id_is_marked_and_offers_no_button(): void
    {
        $this->stripeKeys();
        [$monthly, $yearly] = $this->plans();
        $yearly->forceFill(['stripe_id' => null, 'paypal_plan_id' => null])->save();
        config(['site.premium_plan_unavailable_label' => 'Not on sale yet']);

        $html = $this->actingAs(User::factory()->create())->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertStringContainsString(route('premium.subscribe', $monthly), $html);
        $this->assertStringNotContainsString(route('premium.subscribe', $yearly), $html);
        $this->assertStringContainsString('Not on sale yet', $html);
        $this->assertStringNotContainsString('name="billing"', $html, 'one plan to buy: no choice to make');

        // the checkout of that plan answers with a plain message
        $this->actingAs(User::factory()->create())->get(route('premium.subscribe', $yearly))
            ->assertRedirect(locale_url(route('pages.premium')))
            ->assertSessionHas('info', __('This plan cannot be bought yet. Please choose another plan or check back later.'));
    }

    public function test_a_member_with_premium_reads_a_real_sentence_and_a_link_to_the_plan(): void
    {
        $this->stripeKeys();
        [$monthly] = $this->plans();
        $member = User::factory()->create();
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null, 'trial_ends_at' => null]);

        $html = $this->actingAs($member->fresh())->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('You already have Premium.')), $html, 'account-2');
        $this->assertStringNotContainsString('premium.exist', $html);
        $this->assertStringContainsString('href="'.e(\App\Http\Controllers\User\ProfileController::sectionUrl('premium')).'"', $html, 'See your plan');
        $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $html, 'no second subscription offered');
    }

    public function test_back_from_a_cancelled_checkout_nobody_was_charged(): void
    {
        $this->stripeKeys();
        $this->plans();

        $this->actingAs(User::factory()->create())->get(route('pages.premium', ['checkout' => 'cancelled']))
            ->assertOk()
            ->assertSee(__('Checkout cancelled. You were not charged.'));
    }

    public function test_back_from_a_paid_checkout_the_welcome_sheet_opens_once_premium_is_on(): void
    {
        $this->stripeKeys();
        [$monthly] = $this->plans();
        $member = User::factory()->create(['email' => 'payer@example.test']);

        // not confirmed yet: "Almost done", the page looks again by itself
        $waiting = $this->actingAs($member)->get(route('pages.premium', ['checkout' => 'success']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Premium is being confirmed. It starts in a minute.')), $waiting);
        $this->assertStringContainsString("url.searchParams.set('wait', '1')", $waiting);
        $this->assertStringNotContainsString('id="premium"', $waiting);

        // after a few rounds it says so plainly and stops looking
        $late = $this->actingAs($member)->get(route('pages.premium', ['checkout' => 'success', 'wait' => 8]))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Premium is still being confirmed. Look again in a few minutes: you were only charged if Premium starts.')), $late);
        $this->assertStringNotContainsString("searchParams.set('wait'", $late);

        // confirmed: the welcome sheet, the address printed as text
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null]);
        $welcome = $this->actingAs($member->fresh())->get(route('pages.premium', ['checkout' => 'success']))->assertOk()->getContent();
        $this->assertStringContainsString('id="premium"', $welcome);
        $this->assertStringContainsString('mk-dialog mk-prompt', $welcome);
        $this->assertStringContainsString('<span class="fw-bold">payer@example.test</span>', $welcome);
        $this->assertStringContainsString(e(__('Welcome to Premium')), $welcome);
    }

    /**
     * Right after a paid checkout, before the payment service tells the site (Stripe's webhook, PayPal's confirmation),
     * the member has no Premium yet: the page waits and offers nothing to buy, or the member could pay twice.
     */
    public function test_while_a_payment_is_being_confirmed_nothing_can_be_bought_again(): void
    {
        $this->stripeKeys();
        config(['plans.trial_period.period' => 30, 'site.premium_timeline_enabled' => true, 'site.premium_plan_switcher_enabled' => true]);
        [$monthly, $yearly] = $this->plans();
        $member = User::factory()->create();

        // the page as it is before the checkout: both plans, the switch and the trial timeline
        $before = $this->actingAs($member)->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString(route('premium.subscribe', $monthly), $before);
        $this->assertStringContainsString('name="billing"', $before);
        $this->assertStringContainsString(e(__('How the free trial works')), $before);

        // back from the card checkout, Premium not on yet
        $waiting = $this->actingAs($member)->get(route('pages.premium', ['checkout' => 'success']))->assertOk()->getContent();
        $this->assertStringContainsString('data-premium-waiting', $waiting);
        $this->assertStringContainsString(e(__('Premium is being confirmed. It starts in a minute.')), $waiting);
        $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $waiting, 'no second checkout');
        $this->assertStringNotContainsString(route('premium.subscribe', $yearly), $waiting);
        $this->assertStringNotContainsString('name="billing"', $waiting, 'no Monthly / Yearly switch');
        $this->assertStringNotContainsString(e(__('How the free trial works')), $waiting, 'no trial timeline');
        $this->assertStringNotContainsString('is-chosen', $waiting, 'no plan marked as the one to buy');
        $this->assertStringContainsString('href="'.e(\App\Http\Controllers\User\ProfileController::sectionUrl('premium')).'"', $waiting, 'a link to the plan');

        // the same after the rounds of looking again
        $late = $this->actingAs($member)->get(route('pages.premium', ['checkout' => 'success', 'wait' => 8]))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $late);

        // back from PayPal before it confirmed ("Almost done"): the same, and the page then waits on ?checkout=success
        config(['site.paypal_enabled' => true, 'services.paypal.client_id' => 'id', 'services.paypal.client_secret' => 'secret', 'services.paypal.webhook_id' => 'WH-1']);
        $monthly->forceFill(['paypal_plan_id' => 'P-MONTH'])->save();
        PremiumAvailability::forget();
        $this->assertTrue(PremiumAvailability::planPaypalReady($monthly->fresh()));
        $paypal = $this->actingAs($member)
            ->withSession(['info' => __('PayPal is confirming your payment. Premium starts as soon as it does, usually within a minute. You can keep watching.'), 'info_title' => __('Almost done')])
            ->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString('data-premium-waiting', $paypal);
        $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $paypal);
        $this->assertStringNotContainsString(route('premium.subscribe.paypal', $monthly), $paypal);
        $this->assertStringNotContainsString('name="billing"', $paypal);
        $this->assertStringContainsString("url.searchParams.set('checkout', 'success')", $paypal);
        $this->assertSame(1, substr_count($paypal, e(__('Almost done'))), 'one waiting message');
    }

    /**
     * A member whose last payment failed (past due, unpaid, PayPal "suspended") has no Premium while the payment service
     * retries: a warning that says so, with a title of its own, never "Premium is on", and nothing to buy again.
     */
    public function test_a_member_whose_last_payment_failed_reads_a_warning_not_that_premium_is_on(): void
    {
        $this->stripeKeys();
        [$monthly] = $this->plans();
        $member = User::factory()->create();
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'past_due', 'ends_at' => null, 'trial_ends_at' => null, 'payment_provider' => 'stripe']);
        $this->assertFalse($member->fresh()->is_premium);
        $this->assertTrue($member->fresh()->has_current_subscription);

        $html = $this->actingAs($member->fresh())->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="mk-alert mk-alert--warning" role="alert">.*?'.preg_quote(e(__('Payment problem')), '/').'/s', $html);
        $this->assertStringContainsString(e(__('Your last payment did not go through. Update your card to keep Premium.')), $html);
        $this->assertStringNotContainsString(e(__('You already have Premium.')), $html);
        $this->assertStringNotContainsString('mk-alert--success', $html);
        $this->assertStringNotContainsString(route('premium.subscribe', $monthly), $html, 'the card is updated, not a second plan bought');

        // PayPal: the same, with the place to look
        $paypalMember = User::factory()->create();
        Subscription::factory()->for($paypalMember)->for($monthly)->create(['stripe_status' => 'unpaid', 'ends_at' => null, 'trial_ends_at' => null, 'next_payment' => now()->subDays(2),
            'payment_provider' => 'paypal', 'card_last4' => 'PayPal']);
        $paypal = $this->actingAs($paypalMember->fresh())->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Your last payment did not go through. Check your PayPal account to keep Premium.')), $paypal);

        // and Account settings > Premium says it the same way (with the day of the payment that failed)
        $subscription = $paypalMember->fresh()->premium_subscription;
        $day = \App\Support\BillingFormat::date(\Illuminate\Support\Carbon::instance($subscription->next_payment));
        $section = $this->actingAs($paypalMember->fresh())->get(route('user.edit', ['section' => 'premium']))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Your last payment on :date did not go through. Check your PayPal account to keep Premium.', ['date' => $day])), $section);
        $this->assertStringNotContainsString(e(__('Update card')), $section);
    }

    /** A member who has Premium and comes back from PayPal is shown the page, never "Almost done" next to "Premium is on". */
    public function test_back_from_paypal_a_member_who_has_premium_reads_no_almost_done(): void
    {
        [$monthly] = $this->plans();
        $member = User::factory()->create();
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null, 'trial_ends_at' => null]);
        $this->mock(PayPalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
        });

        $this->actingAs($member->fresh())->get(route('premium.paypal.return'))
            ->assertRedirect(locale_url(route('pages.premium')))
            ->assertSessionMissing('info')
            ->assertSessionMissing('info_title');

        // a message left over from an earlier visit is not printed next to the member's Premium either
        $html = $this->actingAs($member->fresh())
            ->withSession(['info' => 'PayPal is confirming your payment.', 'info_title' => __('Almost done')])
            ->get(route('pages.premium'))->assertOk()->getContent();
        $this->assertStringContainsString(e(__('You already have Premium.')), $html);
        $this->assertStringNotContainsString(e(__('Almost done')), $html);
        $this->assertStringNotContainsString('data-premium-waiting', $html);

        // the PayPal return address with a list in it is not an error page
        $this->actingAs($member->fresh())->get(route('premium.paypal.return', ['subscription_id' => ['I-A', 'I-B']]))
            ->assertRedirect(locale_url(route('pages.premium')));
    }

    public function test_the_welcome_sheet_escapes_the_members_address(): void
    {
        $this->stripeKeys();
        [$monthly] = $this->plans();
        $member = User::factory()->create();
        $member->forceFill(['email' => 'x"><img src=x onerror=alert(1)>@example.test'])->saveQuietly();
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null]);

        $html = $this->actingAs($member->fresh())->get(route('pages.premium', ['checkout' => 'success']))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_the_card_checkout_comes_back_to_the_premium_page(): void
    {
        $this->stripeKeys();
        [$monthly] = $this->plans();
        $member = User::factory()->stripeUser()->create();
        $options = null;

        $user = Mockery::mock($member)->makePartial();
        $user->shouldReceive('newSubscription')->once()->with($monthly->id, 'price_month')->andReturn(
            Mockery::mock(SubscriptionBuilder::class, function (MockInterface $mock) use (&$options) {
                $mock->shouldReceive('checkout')->once()->andReturnUsing(function (array $given) use (&$options) {
                    $options = $given;

                    return redirect('https://checkout.stripe.com/pay/test');
                });
            })
        );

        $this->actingAs($user)->get(route('premium.subscribe', $monthly))->assertRedirect('https://checkout.stripe.com/pay/test');

        $this->assertSame(route('pages.premium', ['checkout' => 'success']), $options['success_url'], 'OD-M10: never the home page');
        $this->assertSame(route('pages.premium', ['checkout' => 'cancelled']), $options['cancel_url']);
    }

    public function test_a_checkout_that_fails_says_so_without_naming_the_service(): void
    {
        $this->stripeKeys();
        [$monthly] = $this->plans();
        $member = User::factory()->stripeUser()->create();

        $user = Mockery::mock($member)->makePartial();
        $user->shouldReceive('newSubscription')->once()->andThrow(new \RuntimeException('Stripe API key invalid (sk_test_page)'));

        $this->actingAs($user)->get(route('premium.subscribe', $monthly))
            ->assertRedirect(locale_url(route('pages.premium')))
            ->assertSessionHas('error', __('The payment page did not open. You were not charged. Please try again in a few minutes.'));

        $this->assertStringNotContainsString('Stripe', (string) session('error'));
        $this->assertStringNotContainsString('sk_test', (string) session('error'));

        // no keys at all: back to the Premium page, whose own message says it (one message: account-32)
        config(['cashier.secret' => '']);
        $this->actingAs(User::factory()->create())->get(route('premium.subscribe', $monthly))
            ->assertRedirect(locale_url(route('pages.premium')))
            ->assertSessionMissing('info')
            ->assertSessionMissing('error');
    }

    /**
     * account-32: a checkout link opened while that checkout can not start (an old link, a link in an e-mail) lands on the
     * Premium page with ONE message: the page's own when nothing can be bought (it was shown twice, a flashed sentence
     * above the page's "Premium is not available yet"), else one line that says what is left to choose. No payment
     * service is named.
     */
    public function test_a_checkout_link_that_can_not_start_lands_on_the_premium_page_with_one_message(): void
    {
        [$monthly, $yearly] = $this->plans();
        $member = User::factory()->create();
        config(['site.premium_checkout_unavailable_message' => 'Premium opens soon.']);

        // nothing can be bought (no payment keys): the card link and the PayPal link both land on the page's own message
        foreach (['premium.subscribe', 'premium.subscribe.paypal'] as $route) {
            $html = $this->actingAs($member)->followingRedirects()->get(route($route, $monthly))->assertOk()->getContent();
            $this->assertSame([e(__('Premium is not available yet')).' Premium opens soon.'], $this->messages($html), $route.': one message');

            $this->actingAs($member)->get(route($route, $monthly))
                ->assertRedirect(locale_url(route('pages.premium')))
                ->assertSessionMissing('info');
        }

        // Premium switched off (card keys set): the owner's "switched off" text, once
        $this->stripeKeys();
        config(['site.premium_enabled' => false, 'site.premium_disabled_message' => 'Premium is resting.']);
        PremiumAvailability::forget();
        $off = $this->actingAs($member)->followingRedirects()->get(route('premium.subscribe', $monthly))->assertOk()->getContent();
        $this->assertSame([e(__('Premium is not available yet')).' Premium is resting.'], $this->messages($off));

        // card keys, but no plan has a card price: nothing can be bought, once
        config(['site.premium_enabled' => true]);
        $monthly->forceFill(['stripe_id' => null])->save();
        $yearly->forceFill(['stripe_id' => null])->save();
        PremiumAvailability::forget();
        $noPrice = $this->actingAs($member)->followingRedirects()->get(route('premium.subscribe', $monthly))->assertOk()->getContent();
        $this->assertSame([e(__('Premium is not available yet')).' Premium opens soon.'], $this->messages($noPrice));

        // PayPal sells the monthly plan, the card nothing: the card link says to choose the other way, shown on the page
        config(['site.paypal_enabled' => true, 'services.paypal.client_id' => 'id', 'services.paypal.client_secret' => 'secret',
            'services.paypal.webhook_id' => 'WH-1']);
        $monthly->forceFill(['paypal_plan_id' => 'P-MONTH'])->save();
        $yearly->forceFill(['paypal_plan_id' => null])->save();
        PremiumAvailability::forget();
        $this->assertTrue(PremiumAvailability::canSell());
        $this->actingAs($member)->get(route('premium.subscribe', $monthly))
            ->assertRedirect(locale_url(route('pages.premium')))
            ->assertSessionHas('info', __('This way of paying is not available right now. Please choose another one.'));
        $other = $this->actingAs($member)->followingRedirects()->get(route('premium.subscribe', $monthly))->assertOk()->getContent();
        $this->assertSame([e(__('This way of paying is not available right now. Please choose another one.'))], $this->messages($other));
        $this->assertStringContainsString(route('premium.subscribe.paypal', $monthly), $other, 'the way that works is on the page');

        // the yearly plan has no price at all: one line to choose another plan
        $yearlyHtml = $this->actingAs($member)->followingRedirects()->get(route('premium.subscribe.paypal', $yearly))->assertOk()->getContent();
        $this->assertSame([e(__('This plan cannot be bought yet. Please choose another plan or check back later.'))], $this->messages($yearlyHtml));

        foreach ([$html, $off, $noPrice, $other, $yearlyHtml] as $page) {
            $this->assertStringNotContainsString('Stripe', strip_tags($page), 'no payment service named');
        }
    }

    /** The messages of the Premium page (its .mk-alert boxes, above and in the page root), as text, in order. */
    private function messages(string $html): array
    {
        $start = strpos($html, 'avd-premium-page');
        $end = strpos($html, '</main>', (int) $start);
        $this->assertNotFalse($start, 'the Premium page root');
        $root = substr($html, (int) $start, $end === false ? null : $end - (int) $start);
        preg_match_all('#<div class="mk-alert(?: [^"]*)?"[^>]*>(.*?)<(?:button|span)(?: [^>]*)?>\s*(?:<i [^>]*></i>\s*)?</(?:button|span)>\s*</div>#s', $root, $alerts);

        return array_map(fn (string $inner): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($inner))), $alerts[1]);
    }

    public function test_back_from_paypal_before_it_confirmed_the_member_reads_an_info_message(): void
    {
        $this->mock(PayPalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
        });

        $message = __('PayPal is confirming your payment. Premium starts as soon as it does, usually within a minute. You can keep watching.');

        // account-16: no subscription id in the return address (PayPal still confirming)
        $this->actingAs(User::factory()->create())->get(route('premium.paypal.return'))
            ->assertRedirect(locale_url(route('pages.premium')))
            ->assertSessionHas('info', $message)
            ->assertSessionHas('info_title', __('Almost done'));

        // the Premium page shows it as the waiting state, once, with its title
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['info' => $message, 'info_title' => __('Almost done')])
            ->get(route('pages.premium'))->getContent();
        $this->assertSame(1, substr_count($html, e($message)));
        $this->assertMatchesRegularExpression('/fa-hourglass-half[^<]*<\/i>\s*<div class="mk-alert__body">\s*<span class="mk-alert__title">'.preg_quote(e(__('Almost done')), '/').'<\/span>/', $html);

        // any other one-time message is printed escaped, as everywhere
        $other = $this->actingAs(User::factory()->create())
            ->withSession(['error' => '<b>not bold</b>'])
            ->get(route('pages.premium'))->getContent();
        $this->assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt;', $other);
    }

    public function test_free_and_premium_is_a_table_and_stacked_rows(): void
    {
        $this->stripeKeys();
        $this->plans();
        config(['site.premium_comparison_enabled' => true]);

        $html = $this->get(route('pages.premium'))->assertOk()->getContent();

        $this->assertStringContainsString('<table class="mk-compare__table">', $html, 'account-5: a table from 640px');
        $this->assertStringContainsString('<div class="mk-compare__cards">', $html, 'stacked rows on phones');
        $this->assertStringContainsString('<td class="fw-bold">', $html);
    }

    /** account-42: the plan card of Account settings says every state in plain words (no "My subscription : EUR 5"). */
    public function test_the_plan_card_says_each_state_in_plain_words(): void
    {
        [$monthly] = $this->plans();
        // the plan card's dates as the invoices and the studio write them (R2A-038: "Nov 5, 2026", no leading zero)
        $date = fn ($value): string => \App\Support\BillingFormat::date(\Illuminate\Support\Carbon::parse($value));
        $states = [
            'active' => [['stripe_status' => 'active', 'trial_ends_at' => null, 'ends_at' => null, 'next_payment' => now()->addDays(20)], 'Premium · monthly',
                fn ($s) => __('Next payment: :price on :date, card ending :digits.', ['price' => '€5', 'date' => $date($s->next_payment), 'digits' => '4242'])],
            'trial' => [['stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(10), 'ends_at' => null, 'next_payment' => now()->addDays(10)], 'Premium · free trial',
                fn ($s) => __('Then :price :per on your card ending :digits.', ['price' => '€5', 'per' => __('a month'), 'digits' => '4242'])],
            'past_due' => [['stripe_status' => 'past_due', 'trial_ends_at' => null, 'ends_at' => null, 'next_payment' => now()->subDays(2)], 'Premium · payment problem',
                fn ($s) => __('Your last payment on :date did not go through. Update your card to keep Premium.', ['date' => $date($s->next_payment)])],
            'ending' => [['stripe_status' => 'active', 'trial_ends_at' => null, 'ends_at' => now()->addDays(9), 'next_payment' => null], 'Premium · ends on',
                fn ($s) => __('You cancelled. Premium stays on until the end of the paid period.')],
            'ended' => [['stripe_status' => 'canceled', 'trial_ends_at' => null, 'ends_at' => now()->subDays(3), 'next_payment' => null], 'Standard account',
                fn ($s) => __('Premium ended on :date.', ['date' => $date($s->ends_at)])],
        ];

        foreach ($states as $state => [$attributes, $badge, $sentence]) {
            $member = User::factory()->create();
            $subscription = Subscription::factory()->for($member)->for($monthly)->create($attributes + ['card_last4' => '4242', 'payment_provider' => 'stripe']);

            $html = html_entity_decode((string) $this->actingAs($member->fresh())->get(route('user.edit', ['section' => 'premium']))->assertOk()->getContent());

            $this->assertStringContainsString('data-account-section="premium"', $html, $state);
            $this->assertStringContainsString($badge, $html, $state);
            $this->assertStringContainsString($sentence($subscription->fresh()), $html, $state);
            $this->assertStringNotContainsString('EUR 5', $html, $state);
            $this->assertStringNotContainsString('bank debit', $html, $state);
        }

        // PayPal: the plan card says where to change it, and no card digits
        $member = User::factory()->create();
        Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null, 'trial_ends_at' => null,
            'next_payment' => now()->addDays(5), 'card_last4' => 'PayPal', 'payment_provider' => 'paypal']);
        $html = html_entity_decode((string) $this->actingAs($member->fresh())->get(route('user.edit', ['section' => 'premium']))->getContent());
        $this->assertStringContainsString(__('You pay with PayPal: change or cancel it in your PayPal account, under Payments > Automatic payments.'), $html);
        $this->assertStringContainsString(', with PayPal.', $html);
    }

    public function test_the_latest_invoices_are_listed_with_their_amount_and_pdf(): void
    {
        [$monthly] = $this->plans();
        $member = User::factory()->create();
        $subscription = Subscription::factory()->for($member)->for($monthly)->create(['stripe_status' => 'active', 'ends_at' => null]);
        foreach ([1, 2, 3, 4] as $month) {
            \App\Models\Transaction::query()->create([
                'stripe_id' => 'in_list_'.$month, 'amount' => 500, 'tax' => 0, 'fee' => 0, 'date' => now()->subMonths($month),
                'user_id' => $member->id, 'subscription_id' => $subscription->id, 'name' => 'A', 'address' => 'B', 'city' => 'C',
                'postal_code' => 'D', 'country' => 'PT',
            ]);
        }

        $html = $this->actingAs($member->fresh())->get(route('user.edit', ['section' => 'premium']))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'mk-item--invoice'), 'the latest three');
        $this->assertSame(3, substr_count($html, '€5.00'));
        $this->assertStringContainsString(locale_url(route('user.invoices.index')), $html, 'See all');
    }

    public function test_downloads_are_not_offered_by_default(): void
    {
        $this->assertSame('0', PremiumPageSettings::defaults()['premium_feature_downloads_enabled'], 'OD-M7');
        $this->assertSame('0', (string) config('admin_settings.settings.premium_feature_downloads_enabled.default'), 'the admin catalogue agrees');
    }
}
