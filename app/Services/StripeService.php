<?php


namespace App\Services;

use App\Data\StripeCard;
use Laravel\Cashier\Cashier;

class StripeService
{
    public function getUserCard(string $stripeUserId): ?StripeCard
    {
        $user = Cashier::stripe()->customers->retrieve($stripeUserId);

        if (blank($user->invoice_settings->default_payment_method)) {
            return null;
        }

        $card = Cashier::stripe()->paymentMethods->retrieve($user->invoice_settings->default_payment_method)->card;

        if (!$card) {
            return null;
        }

        return new StripeCard(
            $card->brand,
            $card->last4,
            $card->exp_month,
            $card->exp_year,
        );
    }

    /** Cancel a Stripe subscription now (Stripe stops charging it). Throws what the Stripe API throws. */
    public function cancelSubscription(string $subscriptionId): void
    {
        Cashier::stripe()->subscriptions->cancel($subscriptionId);
    }

    public function getTransactionFee(?string $stripeChargeId): int
    {
        if (blank($stripeChargeId)) {
            return 0;
        }

        $charge = Cashier::stripe()->charges->retrieve($stripeChargeId,
            ['expand' => ['balance_transaction']]
        );

        return (int) ($charge->balance_transaction->fee ?? 0);
    }
}
