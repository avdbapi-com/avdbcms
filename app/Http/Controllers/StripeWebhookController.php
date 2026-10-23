<?php

namespace App\Http\Controllers;

use App\Data\StripeCard;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\Premium\Cancel;
use App\Notifications\Premium\Invoice;
use App\Notifications\Premium\Unpaid;
use App\Notifications\Premium\Welcome;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly StripeService $stripeService
    ) {
    }

    public function index(Request $request): Response
    {
        $type = $request->input('type');

        Log::channel('stripe')->info($type. ' ('.$request->input('id').')');

        $request->headers->set('Accept', 'application/json');

        $data = $request->input('data.object', []);

        if (!is_array($data)) {
            Log::channel('stripe')->warning('Stripe webhook ignored because data.object is missing.', [
                'type' => $type,
            ]);

            return response()->noContent();
        }

        return match ($type) {
            'invoice.payment_succeeded' => $this->onInvoicePaid($data),
            'invoice.payment_failed' => $this->onInvoiceUnPaid($data),
            'customer.subscription.created' => $this->onSubscriptionCreated($data),
            'customer.subscription.updated' => $this->onSubscriptionUpdated($data),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($data),
            'customer.updated' => $this->onCustomerUpdated($data),
            default => response()->noContent()
        };
    }

    public function onInvoicePaid (array $data) : Response  {

        if (($data['amount_paid'] ?? 0) === 0) {
            return response()->noContent();
        }

        $customerId = $data['customer'] ?? null;
        $subscriptionStripeId = $this->subscriptionIdFromInvoice($data);
        $stripeTransactionId = $data['payment_intent'] ?? $data['charge'] ?? $data['id'] ?? null;

        if (blank($customerId) || blank($subscriptionStripeId) || blank($stripeTransactionId)) {
            return $this->ignoreWebhook('invoice.payment_succeeded missing customer, subscription, or transaction id.', $data);
        }

        if (Transaction::where('stripe_id', $stripeTransactionId)->exists()) {
            return response()->noContent();
        }

        $user = User::where('stripe_id', $customerId)->first();
        $subscription = Subscription::where('payment_provider', 'stripe')
            ->where('stripe_id', $subscriptionStripeId)
            ->first();

        if (!$user || !$subscription) {
            return $this->ignoreWebhook('invoice.payment_succeeded could not be mapped to a user or subscription.', [
                'customer' => $customerId,
                'subscription' => $subscriptionStripeId,
            ]);
        }

        if ((int) $subscription->user_id !== (int) $user->id) {
            return $this->ignoreWebhook('invoice.payment_succeeded customer does not own subscription.', [
                'customer' => $customerId,
                'subscription' => $subscriptionStripeId,
            ]);
        }

        $address = $data['customer_address'] ?? [];
        $chargeId = $data['charge'] ?? data_get($data, 'payment_intent.latest_charge');
        $tax = $data['tax'] ?? collect($data['total_tax_amounts'] ?? [])->sum(fn (array $taxAmount) => $taxAmount['amount'] ?? 0);

        $transaction = Transaction::firstOrCreate([
            'stripe_id' => $stripeTransactionId,
        ], [
            'payment_provider' => 'stripe',
            'amount' => $data['amount_paid'] ?? 0,
            'tax' => $tax,
            'fee' => is_string($chargeId) ? $this->stripeService->getTransactionFee($chargeId) : 0,
            'date' => Carbon::createFromTimestamp($data['created'] ?? time(), config('app.timezone')),
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'name' => $data['customer_name'] ?? $user->username,
            'address' => trim(($address['line1'] ?? '').' '.($address['line2'] ?? '')),
            'city' => $address['city'] ?? '',
            'postal_code' => $address['postal_code'] ?? '',
            'country' => $address['country'] ?? '',
            'vat_id' => data_get($data, 'customer_tax_ids.0.value'),
        ]);

        if (!$transaction->wasRecentlyCreated) {
            return response()->noContent();
        }

        try {
            $this->invoiceService->generate($transaction);
        } catch (Throwable $exception) {
            Log::channel('stripe')->error('Invoice PDF generation failed.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $user->notify(new Invoice($transaction));

        return response()->noContent();
    }

    public function onInvoiceUnPaid (array $data) : Response  {

        $user = filled($data['customer'] ?? null)
            ? User::where('stripe_id', $data['customer'])->first()
            : null;

        if ($user?->is_premium) {
            $user->notify(new Unpaid($data['amount_due'] ?? 0));
        }

        return response()->noContent();
    }

    public function onSubscriptionCreated (array $data) : Response  {

        $customerId = $data['customer'] ?? null;
        $planStripeId = $this->planStripeId($data);

        if (blank($customerId) || blank($planStripeId) || blank($data['id'] ?? null)) {
            return $this->ignoreWebhook('customer.subscription.created missing customer, plan, or subscription id.', $data);
        }

        $user = User::where('stripe_id', $customerId)->first();
        $plan = Plan::where('stripe_id', $planStripeId)->first();

        if (!$user || !$plan) {
            return $this->ignoreWebhook('customer.subscription.created could not be mapped to a user or plan.', [
                'customer' => $customerId,
                'plan' => $planStripeId,
            ]);
        }

        $card = $this->stripeService->getUserCard($customerId);
        $periodEnd = $this->periodEnd($data);

        $attributes = array_merge([
            'payment_provider' => 'stripe',
            'next_payment' => $this->timestampOrNull($periodEnd),
            'stripe_status' => $data['status'] ?? 'active',
            'plan_id' => $plan->id,
            'stripe_id' => $data['id'],
            'ends_at' => null,
        ], $this->cardAttributes($card, !$user->premium_subscription));

        // User Cancel Subscription and Renew
        if ($user->premium_subscription) {
            $user->premium_subscription()->update($attributes);
        } else {
            Subscription::create(array_merge($attributes, [
                'user_id' => $user->id,
                'trial_ends_at' => $this->timestampOrNull($data['trial_end'] ?? null),
            ]));
        }

        $user->load('premium_subscription');

        $user->notify(new Welcome());

        return response()->noContent();
    }

    public function onSubscriptionUpdated (array $data) : Response  {

        $subscription = Subscription::where('payment_provider', 'stripe')
            ->where('stripe_id', $data['id'] ?? null)
            ->first();

        if (!$subscription) {
            return $this->ignoreWebhook('customer.subscription.updated could not be mapped to a subscription.', [
                'subscription' => $data['id'] ?? null,
            ]);
        }

        $updates = [
            'stripe_status' => $data['status'] ?? $subscription->stripe_status,
        ];

        $planStripeId = $this->planStripeId($data);

        if (filled($planStripeId)) {
            $plan = Plan::where('stripe_id', $planStripeId)->first();

            if ($plan) {
                $updates['plan_id'] = $plan->id;
            }
        }

        $periodEnd = $this->periodEnd($data);

        if ($data['cancel_at_period_end'] ?? false) {
            $updates['ends_at'] = $subscription->on_trial
                ? $subscription->trial_ends_at
                : $this->timestampOrNull($periodEnd);

            $subscription->update($updates);

            return response()->noContent();
        }

        if ($periodEnd) {
            $updates['next_payment'] = $this->timestampOrNull($periodEnd);
        }

        $updates['ends_at'] = null;

        $subscription->update($updates);

        return response()->noContent();
    }

    public function onCustomerUpdated (array $data) : Response  {

        $user = User::where('stripe_id', $data['id'] ?? null)->first();

        if (!$user) {
            return $this->ignoreWebhook('customer.updated could not be mapped to a user.', [
                'customer' => $data['id'] ?? null,
            ]);
        }

        $subscription = Subscription::where('user_id', $user->id)->first();

        if ($subscription && data_get($data, 'invoice_settings.default_payment_method')) {
            $card = $this->stripeService->getUserCard($data['id']);

            if ($card) {
                $subscription->update($this->cardAttributes($card));
            }
        }

        return response()->noContent();
    }

    public function onSubscriptionDeleted (array $data) : Response  {

        $subscription = Subscription::where('payment_provider', 'stripe')
            ->where('stripe_id', $data['id'] ?? null)
            ->first();

        if (!$subscription) {
            return $this->ignoreWebhook('customer.subscription.deleted could not be mapped to a subscription.', [
                'subscription' => $data['id'] ?? null,
            ]);
        }

        $subscription->update([
            'stripe_status' => $data['status'] ?? 'canceled',
            'ends_at' => $this->timestampOrNull($data['ended_at'] ?? $data['canceled_at'] ?? time()),
        ]);

        $subscription->user?->notify(new Cancel());

        return response()->noContent();
    }

    private function subscriptionIdFromInvoice(array $data): ?string
    {
        return $data['subscription'] ?? data_get($data, 'parent.subscription_details.subscription');
    }

    private function planStripeId(array $data): ?string
    {
        return data_get($data, 'plan.id')
            ?? data_get($data, 'items.data.0.price.id')
            ?? data_get($data, 'items.data.0.plan.id');
    }

    private function periodEnd(array $data): ?int
    {
        return $data['current_period_end'] ?? data_get($data, 'items.data.0.current_period_end');
    }

    private function timestampOrNull(mixed $timestamp): ?Carbon
    {
        return $timestamp
            ? Carbon::createFromTimestamp((int) $timestamp, config('app.timezone'))
            : null;
    }

    private function cardAttributes(?StripeCard $card, bool $forCreate = false): array
    {
        if ($card) {
            return [
                'card_last4' => $card->last4,
                'card_expired_at' => Carbon::createFromDate($card->expYear, $card->expMonth)->endOfMonth()->toDateString(),
            ];
        }

        return $forCreate
            ? ['card_last4' => '', 'card_expired_at' => now()->toDateString()]
            : [];
    }

    private function ignoreWebhook(string $message, array $context = []): Response
    {
        Log::channel('stripe')->warning($message, $context);

        return response()->noContent();
    }
}
