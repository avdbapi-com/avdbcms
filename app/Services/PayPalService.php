<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PayPalService
{
    public function isConfigured(): bool
    {
        return filled(config('services.paypal.client_id'))
            && filled(config('services.paypal.client_secret'));
    }

    public function webhookIsConfigured(): bool
    {
        return $this->isConfigured() && filled(config('services.paypal.webhook_id'));
    }

    public function createSubscription(array $payload): array
    {
        return $this->paypal()
            ->withHeaders([
                'Prefer' => 'return=representation',
                'PayPal-Request-Id' => (string) str()->uuid(),
            ])
            ->post($this->baseUrl().'/v1/billing/subscriptions', $payload)
            ->throw()
            ->json();
    }

    public function showSubscription(string $subscriptionId): array
    {
        return $this->paypal()
            ->get($this->baseUrl().'/v1/billing/subscriptions/'.$subscriptionId)
            ->throw()
            ->json();
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        if (!$this->webhookIsConfigured()) {
            return false;
        }

        try {
            $response = $this->paypal()
                ->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
                    'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                    'cert_url' => $request->header('PAYPAL-CERT-URL'),
                    'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                    'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                    'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                    'webhook_id' => config('services.paypal.webhook_id'),
                    'webhook_event' => $request->all(),
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return false;
        }

        return ($response['verification_status'] ?? null) === 'SUCCESS';
    }

    public function amountToCents(array $amount): int
    {
        return (int) round(((float) ($amount['total'] ?? $amount['value'] ?? 0)) * 100);
    }

    public function feeToCents(array $resource): int
    {
        $fee = data_get($resource, 'transaction_fee.value')
            ?? data_get($resource, 'transaction_fee.amount.value')
            ?? data_get($resource, 'seller_receivable_breakdown.paypal_fee.value');

        return (int) round(((float) $fee) * 100);
    }

    public function approvalUrl(array $subscription): ?string
    {
        foreach ($subscription['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) === 'approve') {
                return $link['href'] ?? null;
            }
        }

        return null;
    }

    private function paypal()
    {
        return Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout(20)
            ->retry(2, 200);
    }

    private function accessToken(): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('PayPal credentials are not configured.');
        }

        $cacheKey = 'paypal.access_token.'.config('services.paypal.mode', 'sandbox').'.'.sha1((string) config('services.paypal.client_id'));

        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            $response = Http::asForm()
                ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.client_secret'))
                ->post($this->baseUrl().'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ])
                ->throw()
                ->json();

            return $response['access_token'] ?? throw new RuntimeException('PayPal did not return an access token.');
        });
    }

    private function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
