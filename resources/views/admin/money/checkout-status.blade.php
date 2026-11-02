{{--
    Money > Payment setup, above the switches: what keeps Premium checkout closed although payments were set up
    (App\Support\PremiumAvailability::setupProblems()). Since 1.2.3 a Stripe checkout needs the webhook signing secret
    and a PayPal checkout the webhook ID; a site updated with only the keys sells nothing until they are added, so the
    page says so, with the webhook address to give the payment service and a link to the field.
    Data: $checkoutProblems (sentences), $missingWebhooks ('stripe' / 'paypal'), $webhookUrls (service => address).
--}}
@if (($checkoutProblems ?? []) !== [])
    <x-admin.callout type="warn" class="ad-checkout-status" :title="__('Members cannot buy premium right now')" role="status" data-checkout-status>
        {{-- On a right-to-left page the key prefixes (whsec_) keep their order (AdminText::html(): markup, no invisible
             character). The webhook address is a <code dir="ltr"> of its own, so it reads left to right and is copied
             exactly as the payment service needs it. --}}
        {{ \App\Support\AdminText::html(implode(' ', $checkoutProblems)) }}
        @foreach ($missingWebhooks ?? [] as $service)
            {{ \App\Support\AdminText::html($service === 'stripe'
                ? __('In your Stripe dashboard, add a webhook for :url and copy its signing secret (it starts with whsec_).')
                : __('At developer.paypal.com, add a webhook for :url to your app and copy its Webhook ID.'),
                [':url' => '<code class="ad-checkout-url" dir="ltr" translate="no">'.e($webhookUrls[$service]).'</code>']) }}
        @endforeach
        <x-slot:actions>
            @foreach ($missingWebhooks ?? [] as $service)
                <a class="ad-btn sm" href="#pay-{{ $service === 'stripe' ? 'STRIPE_WEBHOOK_SECRET' : 'PAYPAL_WEBHOOK_ID' }}" data-checkout-fix="{{ $service }}">{{ $service === 'stripe' ? __('Add the webhook signing secret') : __('Add the webhook ID') }}</a>
            @endforeach
            @if (\App\Support\PremiumAvailability::plansUnlinked())
                <a class="ad-btn sm" href="{{ route('admin.plans.index') }}">{{ __('Premium plans') }}</a>
            @endif
        </x-slot:actions>
    </x-admin.callout>
@endif
