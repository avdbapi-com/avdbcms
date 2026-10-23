<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class PayPalSubscriptionService
{
    public function __construct(private readonly PayPalService $paypalService)
    {
    }

    public function syncSubscription(array $resource, ?User $fallbackUser = null): ?Subscription
    {
        $subscriptionId = $resource['id'] ?? null;
        $planId = $resource['plan_id'] ?? null;

        if (blank($subscriptionId) || blank($planId)) {
            return null;
        }

        $plan = Plan::where('paypal_plan_id', $planId)->first();
        $user = $fallbackUser ?? $this->userFromResource($resource);

        if (!$plan || !$user) {
            return null;
        }

        $status = $this->status($resource['status'] ?? null);
        $endsAt = in_array($status, ['canceled', 'unpaid'], true)
            ? $this->date(data_get($resource, 'status_update_time')) ?? now()
            : null;

        $attributes = [
            'payment_provider' => 'paypal',
            'stripe_status' => $status,
            'next_payment' => $this->date(data_get($resource, 'billing_info.next_billing_time')),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'stripe_id' => $subscriptionId,
            'ends_at' => $endsAt,
            'card_last4' => 'PayPal',
            'card_expired_at' => now()->addYear()->endOfYear(),
        ];

        $subscription = Subscription::where('payment_provider', 'paypal')
            ->where('stripe_id', $subscriptionId)
            ->first();

        if (!$subscription && $user->premium_subscription && !$user->premium_subscription->is_active) {
            $subscription = $user->premium_subscription;
        }

        if ($subscription) {
            $subscription->update($attributes);

            return $subscription->refresh();
        }

        return Subscription::create($attributes);
    }

    public function recordSale(array $resource): ?Transaction
    {
        $saleId = $resource['id'] ?? null;
        $subscriptionId = $resource['billing_agreement_id']
            ?? data_get($resource, 'supplementary_data.related_ids.subscription_id');

        if (blank($saleId) || blank($subscriptionId)) {
            return null;
        }

        if (Transaction::where('stripe_id', $saleId)->exists()) {
            return null;
        }

        $subscription = Subscription::where('payment_provider', 'paypal')
            ->where('stripe_id', $subscriptionId)
            ->with('user')
            ->first();

        if (!$subscription?->user) {
            return null;
        }

        $amount = $this->paypalService->amountToCents($resource['amount'] ?? []);

        if ($amount <= 0) {
            return null;
        }

        $transaction = Transaction::firstOrCreate([
            'stripe_id' => $saleId,
        ], [
            'payment_provider' => 'paypal',
            'amount' => $amount,
            'tax' => 0,
            'fee' => $this->paypalService->feeToCents($resource),
            'date' => $this->date($resource['create_time'] ?? null) ?? now(),
            'user_id' => $subscription->user->id,
            'subscription_id' => $subscription->id,
            'name' => $subscription->user->username,
            'address' => '',
            'city' => '',
            'postal_code' => '',
            'country' => data_get($resource, 'billing_address.country_code', ''),
            'vat_id' => null,
        ]);

        return $transaction->wasRecentlyCreated ? $transaction : null;
    }

    private function userFromResource(array $resource): ?User
    {
        $customId = $resource['custom_id'] ?? '';

        if (preg_match('/user:(\d+)/', $customId, $matches)) {
            return User::find((int) $matches[1]);
        }

        $email = data_get($resource, 'subscriber.email_address');

        return $email ? User::where('email', $email)->first() : null;
    }

    private function status(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE' => 'active',
            'APPROVAL_PENDING' => 'incomplete',
            'SUSPENDED' => 'unpaid',
            'CANCELLED', 'EXPIRED' => 'canceled',
            default => strtolower((string) $status ?: 'incomplete'),
        };
    }

    private function date(?string $date): ?Carbon
    {
        return $date ? Carbon::parse($date, config('app.timezone')) : null;
    }
}
