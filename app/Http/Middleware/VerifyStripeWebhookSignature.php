<?php

namespace App\Http\Middleware;

use Closure;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The Stripe webhook's signature check, failing closed: without a signing secret (STRIPE_WEBHOOK_SECRET) Cashier's check
 * would compute the signature with an empty key, which anybody can do, so every event is refused (403) as PayPal does
 * without its webhook id. With a secret, Cashier's own check runs unchanged.
 */
class VerifyStripeWebhookSignature extends VerifyWebhookSignature
{
    public function handle($request, Closure $next)
    {
        $secret = config('cashier.webhook.secret');

        if (! is_string($secret) || trim($secret) === '') {
            // An empty key accepts a signature anyone can compute: fail closed.
            throw new AccessDeniedHttpException('Stripe webhook signing secret is not set.');
        }

        return parent::handle($request, $next);
    }
}
