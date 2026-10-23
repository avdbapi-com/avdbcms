<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PayPalService;
use App\Services\PayPalSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Checkout;
use Throwable;

class PremiumController extends Controller
{
    public function subscribe(Plan $plan): Checkout|RedirectResponse
    {
        $user = Auth::user();

        if (!config('site.premium_enabled', true)) {
            return $this->checkoutUnavailable(__('Premium subscriptions are currently unavailable.'));
        }

        if (!config('site.stripe_enabled', true) || !$this->stripeIsConfigured()) {
            return $this->checkoutUnavailable(__('Stripe checkout is not configured yet. Please contact support.'));
        }

        if (blank($plan->stripe_id)) {
            return $this->checkoutUnavailable(__('This premium plan is missing its Stripe price ID. Please contact support.'));
        }

        $checkoutOptions = [
            'success_url' => route('pages.home', ['premium' => true]),
            'cancel_url' => route('pages.premium'),
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

            return $this->checkoutUnavailable(__('Premium checkout could not start. Please contact support.'));
        }
    }

    public function subscribePaypal(Plan $plan, PayPalService $paypalService): RedirectResponse
    {
        $user = Auth::user();

        if (!config('site.premium_enabled', true)) {
            return $this->checkoutUnavailable(__('Premium subscriptions are currently unavailable.'));
        }

        if (!config('site.paypal_enabled', true) || !$paypalService->webhookIsConfigured()) {
            return $this->checkoutUnavailable(__('PayPal checkout is not configured yet. Please contact support.'));
        }

        if (blank($plan->paypal_plan_id)) {
            return $this->checkoutUnavailable(__('This premium plan is missing its PayPal Plan ID. Please contact support.'));
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
                    'cancel_url' => route('pages.premium'),
                ],
            ]);

            $approvalUrl = $paypalService->approvalUrl($subscription);

            if (!$approvalUrl) {
                return $this->checkoutUnavailable(__('PayPal did not return an approval link. Please contact support.'));
            }

            return redirect()->away($approvalUrl);
        } catch (Throwable $exception) {
            Log::error('PayPal checkout failed.', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->checkoutUnavailable(__('PayPal checkout could not start. Please contact support.'));
        }
    }

    public function paypalReturn(
        Request $request,
        PayPalService $paypalService,
        PayPalSubscriptionService $subscriptionService
    ): RedirectResponse {
        $subscriptionId = $request->input('subscription_id') ?? $request->input('ba_token');

        if (!$subscriptionId || !$paypalService->isConfigured()) {
            return redirect()
                ->route('pages.premium')
                ->with('info', __('PayPal approval is processing. Your premium access will update after PayPal confirms the subscription.'));
        }

        try {
            $resource = $paypalService->showSubscription($subscriptionId);
            $subscription = $subscriptionService->syncSubscription($resource, Auth::user());

            if ($subscription?->is_active) {
                return redirect()->route('pages.home', ['premium' => true]);
            }
        } catch (Throwable $exception) {
            Log::warning('PayPal return sync failed.', [
                'user_id' => Auth::id(),
                'subscription_id' => $subscriptionId,
                'exception' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('pages.premium')
            ->with('info', __('PayPal approval is processing. Your premium access will update after PayPal confirms the subscription.'));
    }

    private function stripeIsConfigured(): bool
    {
        return filled(config('cashier.key'))
            && filled(config('cashier.secret'))
            && filled(config('cashier.webhook.secret'));
    }

    private function checkoutUnavailable(string $message): RedirectResponse
    {
        return redirect()
            ->route('pages.premium')
            ->with('error', $message);
    }
}
