<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PayPalService;
use App\Services\PayPalSubscriptionService;
use App\Support\PremiumAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Checkout;
use Throwable;

/**
 * Premium checkout (member design 6.16).
 *
 * Both checkouts come back to the Premium page itself, never to the home page with its ads and popunder (OD-M10):
 * Stripe's success_url and PayPal's "active" return to /premium?checkout=success, where the welcome sheet opens once
 * Premium is on; "Cancel" at Stripe to /premium?checkout=cancelled ("You were not charged"). Every problem shows ONE
 * plain message that never names a payment service (account-32); the details stay in the log for the owner.
 */
class PremiumController extends Controller
{
    public function subscribe(Plan $plan): Checkout|RedirectResponse
    {
        $user = Auth::user();

        if (!config('site.premium_enabled', true) || !PremiumAvailability::stripeReady() || blank($plan->stripe_id)) {
            return $this->checkoutUnavailable($plan);
        }

        $checkoutOptions = [
            'success_url' => route('pages.premium', ['checkout' => 'success']),
            'cancel_url' => route('pages.premium', ['checkout' => 'cancelled']),
            'billing_address_collection' => 'required',
            'automatic_tax' => [
                'enabled' => true
            ],
            'payment_method_types' => [
                'card',
            ],
            'payment_method_collection' => 'always',
        ];

        $trialDays = (int) config('plans.trial_period.period', 0);

        if (!$user->premium_subscription && $trialDays > 0) {
            $checkoutOptions['subscription_data'] = [
                'trial_period_days' => $trialDays,
            ];
        }

        if ($user->premium_subscription) {
            Arr::forget($checkoutOptions, 'subscription_data');
        }

        try {
            return $user->newSubscription($plan->id, $plan->stripe_id)
                ->checkout($checkoutOptions);
        } catch (Throwable $exception) {
            Log::error('Premium checkout failed.', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->checkoutFailed();
        }
    }

    public function subscribePaypal(Plan $plan, PayPalService $paypalService): RedirectResponse
    {
        $user = Auth::user();

        if (!config('site.premium_enabled', true) || !config('site.paypal_enabled', true) || !$paypalService->webhookIsConfigured() || blank($plan->paypal_plan_id)) {
            return $this->checkoutUnavailable($plan);
        }

        try {
            $subscription = $paypalService->createSubscription([
                'plan_id' => $plan->paypal_plan_id,
                'custom_id' => 'avdbclip:user:'.$user->id.':plan:'.$plan->id,
                'application_context' => [
                    'brand_name' => config('app.name'),
                    'locale' => app()->getLocale(),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => route('premium.paypal.return'),
                    'cancel_url' => route('pages.premium', ['checkout' => 'cancelled']),
                ],
            ]);

            $approvalUrl = $paypalService->approvalUrl($subscription);

            if (!$approvalUrl) {
                Log::error('PayPal did not return an approval link.', ['user_id' => $user->id, 'plan_id' => $plan->id]);

                return $this->checkoutFailed();
            }

            return redirect()->away($approvalUrl);
        } catch (Throwable $exception) {
            Log::error('PayPal checkout failed.', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->checkoutFailed();
        }
    }

    public function paypalReturn(
        Request $request,
        PayPalService $paypalService,
        PayPalSubscriptionService $subscriptionService
    ): RedirectResponse {
        $subscriptionId = $request->input('subscription_id') ?? $request->input('ba_token');

        if (!is_string($subscriptionId) || trim($subscriptionId) === '' || !$paypalService->isConfigured()) {
            return $this->paypalConfirming();
        }

        try {
            $resource = $paypalService->showSubscription($subscriptionId);
            $subscription = $subscriptionService->syncSubscription($resource, Auth::user());

            // The welcome only for the member's own subscription (another member's id changes and shows nothing).
            if ($subscription?->is_active && (int) $subscription->user_id === (int) Auth::id()) {
                return redirect()->to(locale_url(route('pages.premium', ['checkout' => 'success'])));
            }
        } catch (Throwable $exception) {
            Log::warning('PayPal return sync failed.', [
                'user_id' => Auth::id(),
                'subscription_id' => $subscriptionId,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $this->paypalConfirming();
    }

    /**
     * Back from PayPal before PayPal confirmed the payment: "Almost done" on the Premium page (account-16; mockup
     * premium-states). The page prints info_title above the message, offers nothing to buy while it waits, and looks
     * again on /premium?checkout=success until Premium starts. A member who already has Premium is simply shown the
     * page: "Almost done" next to "Premium is on" would contradict itself.
     */
    private function paypalConfirming(): RedirectResponse
    {
        $redirect = redirect()->to(locale_url(route('pages.premium')));

        if (Auth::user()?->is_premium) {
            return $redirect;
        }

        return $redirect
            ->with('info', __('PayPal is confirming your payment. Premium starts as soon as it does, usually within a minute. You can keep watching.'))
            ->with('info_title', __('Almost done'));
    }

    /**
     * This checkout can not start: Premium is switched off, the chosen payment service is not set up, or the plan has no
     * price with it (an old link, a link in an e-mail). Back to the Premium page with ONE message, never naming a payment
     * service (account-32):
     * - nothing can be bought: no message is added, the page itself says it (the owner's "Premium is not available yet"
     *   text), which a second, flashed sentence would only repeat;
     * - the plan can be bought another way: one line says to choose it (the page shows that way);
     * - the plan can not be bought at all, but another plan can: one line says to choose another plan.
     * A member who already has Premium never gets here (the route's premiumSubscribe policy).
     */
    private function checkoutUnavailable(Plan $plan): RedirectResponse
    {
        $redirect = redirect()->to(locale_url(route('pages.premium')));

        if (!PremiumAvailability::canSell()) {
            return $redirect;
        }

        return $redirect->with('info', PremiumAvailability::planReady($plan)
            ? __('This way of paying is not available right now. Please choose another one.')
            : __('This plan cannot be bought yet. Please choose another plan or check back later.'));
    }

    /** The payment page did not open (the service refused or did not answer). */
    private function checkoutFailed(): RedirectResponse
    {
        return redirect()
            ->to(locale_url(route('pages.premium')))
            ->with('error', __('The payment page did not open. You were not charged. Please try again in a few minutes.'));
    }
}
