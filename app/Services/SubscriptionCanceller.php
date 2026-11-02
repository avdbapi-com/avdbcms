<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stops a premium subscription at its payment provider (Stripe or PayPal) before the site ends or deletes it, so the
 * member is not charged again. Account deletion (App\Observers\UserObserver) and the admin's cancel and delete actions
 * call it first; their own change goes on whatever the provider answers.
 */
final class SubscriptionCanceller
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly PayPalService $paypal,
    ) {
    }

    /**
     * True when nothing more is billed: the provider confirmed, the subscription has no provider id, it is already over
     * (canceled, incomplete_expired), or PayPal says it is no longer active (422). False when the provider did not
     * confirm (an API error, missing keys): logged with the id, so it can be cancelled by hand. Never throws.
     */
    public function cancelAtProvider(Subscription $subscription, string $reason): bool
    {
        $providerId = trim((string) $subscription->stripe_id);
        $status = strtolower(trim((string) $subscription->stripe_status));

        if ($providerId === '' || in_array($status, ['canceled', 'incomplete_expired'], true)) {
            return true;
        }

        // The column defaults to 'stripe': every row that is not PayPal's is Stripe's.
        $provider = strtolower(trim((string) $subscription->payment_provider)) === 'paypal' ? 'paypal' : 'stripe';

        try {
            if ($provider === 'paypal') {
                $this->paypal->cancelSubscription($providerId, $reason);
            } else {
                $this->stripe->cancelSubscription($providerId);
            }

            return true;
        } catch (RequestException $exception) {
            if ($provider === 'paypal' && $exception->response->status() === 422) {
                // PayPal: the subscription is no longer active, so nothing is billed any more.
                return true;
            }

            $this->warn($subscription, $provider, $providerId, $exception);

            return false;
        } catch (Throwable $exception) {
            $this->warn($subscription, $provider, $providerId, $exception);

            return false;
        }
    }

    private function warn(Subscription $subscription, string $provider, string $providerId, Throwable $exception): void
    {
        Log::warning('Premium subscription could not be cancelled at the payment provider.', [
            'premium_id' => $subscription->getKey(),
            'provider' => $provider,
            'provider_id' => $providerId,
            'error' => get_class($exception),
            'message' => mb_substr($exception->getMessage(), 0, 300),
        ]);
    }
}
