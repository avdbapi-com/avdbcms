<?php

namespace App\Http\Controllers;

use App\Notifications\Premium\Cancel;
use App\Notifications\Premium\Invoice;
use App\Notifications\Premium\Unpaid;
use App\Notifications\Premium\Welcome;
use App\Services\InvoiceService;
use App\Services\PayPalService;
use App\Services\PayPalSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayPalWebhookController extends Controller
{
    public function __construct(
        private readonly PayPalService $paypalService,
        private readonly PayPalSubscriptionService $subscriptions,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->paypalService->verifyWebhookSignature($request)) {
            Log::channel('paypal')->warning('PayPal webhook rejected by signature verification.', [
                'event_type' => $request->input('event_type'),
                'id' => $request->input('id'),
            ]);

            abort(403);
        }

        $eventType = $request->input('event_type');
        $resource = $request->input('resource', []);

        Log::channel('paypal')->info($eventType.' ('.$request->input('id').')');

        if (!is_array($resource)) {
            return response()->noContent();
        }

        return match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.UPDATED' => $this->onSubscriptionSynced($resource, $eventType),
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->onSubscriptionCanceled($resource),
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->onSubscriptionUnpaid($resource),
            'PAYMENT.SALE.COMPLETED' => $this->onSaleCompleted($resource),
            default => response()->noContent(),
        };
    }

    private function onSubscriptionSynced(array $resource, string $eventType): Response
    {
        $subscription = $this->subscriptions->syncSubscription($resource);

        if ($subscription && $eventType === 'BILLING.SUBSCRIPTION.ACTIVATED') {
            $subscription->user?->notify(new Welcome());
        }

        return response()->noContent();
    }

    private function onSubscriptionCanceled(array $resource): Response
    {
        $subscription = $this->subscriptions->syncSubscription(array_merge($resource, [
            'status' => 'CANCELLED',
        ]));

        $subscription?->user?->notify(new Cancel());

        return response()->noContent();
    }

    private function onSubscriptionUnpaid(array $resource): Response
    {
        $subscription = $this->subscriptions->syncSubscription(array_merge($resource, [
            'status' => 'SUSPENDED',
        ]));

        if ($subscription?->user?->is_premium) {
            $subscription->user->notify(new Unpaid(0));
        }

        return response()->noContent();
    }

    private function onSaleCompleted(array $resource): Response
    {
        $transaction = $this->subscriptions->recordSale($resource);

        if (!$transaction) {
            return response()->noContent();
        }

        try {
            $this->invoiceService->generate($transaction);
            $transaction->user?->notify(new Invoice($transaction));
        } catch (Throwable $exception) {
            Log::channel('paypal')->error('PayPal invoice handling failed.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return response()->noContent();
    }
}
