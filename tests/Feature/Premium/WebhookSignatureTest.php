<?php

namespace Tests\Feature\Premium;

use App\Services\PayPalService;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_stripe_webhook_rejects_missing_signature(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test']);

        $this->postJson(route('stripe_webhook'), [
            'type' => 'customer.subscription.created',
            'data' => ['object' => []],
        ])->assertForbidden();
    }

    public function test_paypal_webhook_rejects_failed_signature_verification(): void
    {
        $this->mock(PayPalService::class, function (MockInterface $mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnFalse();
        });

        $this->postJson(route('paypal_webhook'), [
            'id' => 'WH-TEST',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => [],
        ])->assertForbidden();
    }
}
