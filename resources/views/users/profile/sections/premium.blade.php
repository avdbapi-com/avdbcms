{{--
    Account settings > Premium and invoices (member design 6.16; mockups account-premium, premium-states): the plan
    card in plain words (account-42: standard, free trial, active, payment problem, cancelled but still on, ended) and the
    last three invoices with "See all". "Become premium" / "Renew" only while Premium can be bought (account-32).
    Dates and amounts as the Invoices list, the invoice PDF and the Premium page write them, in the member's language
    (App\Support\BillingFormat, R2A-038: "26 Eki 2026", "€5,00"); a sentence that ends with a date that ends with its own
    full stop keeps one (BillingFormat::fill: "26 нояб. 2026 г.").
--}}
@php
    $day = fn ($date): string => $date ? \App\Support\BillingFormat::date(\Illuminate\Support\Carbon::instance($date)) : '';
    $fill = fn (string $line, array $replace): string => \App\Support\BillingFormat::fill($line, $replace);
    $state = $plan['state'];
    $badge = match ($state) {
        'trial' => ['premium', 'fa-solid fa-star', __('Premium · free trial')],
        'active' => ['premium', 'fa-solid fa-star', match ($plan['months'] ?? 0) {
            1 => __('Premium · monthly'),
            12 => __('Premium · yearly'),
            default => __('Premium'),
        }],
        'past_due' => ['warning', 'fa-solid fa-star', __('Premium · payment problem')],
        'ending' => ['premium', 'fa-solid fa-star', __('Premium · ends on :date', ['date' => $day($plan['ends'] ?? null)])],
        default => ['neutral', 'fa-solid fa-user', __('Standard account')],
    };
    // the card's state class as the kit names it (an ended plan is "expired")
    $stateClass = $state === 'ended' ? 'expired' : $state;
    $via = ($plan['paypal'] ?? false) ? 'paypal' : (($plan['card'] ?? null) ? 'card' : 'none');
@endphp

@include('users.profile.partials.notices', ['fields' => []])

<section class="mk-panel" aria-labelledby="hPlan">
    <div class="mk-panel__head"><h2 class="mk-panel__title" id="hPlan">{{ __('Your plan') }}</h2></div>
    <div class="mk-panel__body">
        <div class="mk-plan mk-plan--{{ $stateClass }}">
            <div class="mk-plan__top">
                <span class="mk-badge mk-badge--{{ $badge[0] }}"><i class="{{ $badge[1] }}" aria-hidden="true"></i>{{ $badge[2] }}</span>
            </div>

            @switch($state)
                @case('trial')
                    <p>
                        {{ $fill(__('Your free trial ends on :date.'), ['date' => $day($plan['trial_ends'])]) }}
                        @if($via === 'card')
                            {{ __('Then :price :per on your card ending :digits.', ['price' => $plan['price'], 'per' => $plan['per'], 'digits' => $plan['card']]) }}
                        @elseif($via === 'paypal')
                            {{ __('Then :price :per with PayPal.', ['price' => $plan['price'], 'per' => $plan['per']]) }}
                        @else
                            {{ __('Then :price :per.', ['price' => $plan['price'], 'per' => $plan['per']]) }}
                        @endif
                    </p>
                    @break
                @case('active')
                    @if($plan['next'])
                        <p>
                            @if($via === 'card')
                                {{ __('Next payment: :price on :date, card ending :digits.', ['price' => $plan['price'], 'date' => $day($plan['next']), 'digits' => $plan['card']]) }}
                            @elseif($via === 'paypal')
                                {{ __('Next payment: :price on :date, with PayPal.', ['price' => $plan['price'], 'date' => $day($plan['next'])]) }}
                            @else
                                {{ $fill(__('Next payment: :price on :date.'), ['price' => $plan['price'], 'date' => $day($plan['next'])]) }}
                            @endif
                        </p>
                    @else
                        <p>{{ __('Premium is on.') }}</p>
                    @endif
                    @break
                @case('past_due')
                    @if($via === 'paypal')
                        <p>{{ $plan['next']
                            ? __('Your last payment on :date did not go through. Check your PayPal account to keep Premium.', ['date' => $day($plan['next'])])
                            : __('Your last payment did not go through. Check your PayPal account to keep Premium.') }}</p>
                    @else
                        <p>{{ $plan['next']
                            ? __('Your last payment on :date did not go through. Update your card to keep Premium.', ['date' => $day($plan['next'])])
                            : __('Your last payment did not go through. Update your card to keep Premium.') }}</p>
                    @endif
                    @break
                @case('ending')
                    <p>{{ __('You cancelled. Premium stays on until the end of the paid period.') }}</p>
                    @break
                @case('ended')
                    <p>{{ ($plan['ends'] ?? null) ? $fill(__('Premium ended on :date.'), ['date' => $day($plan['ends'])]) : __('Premium has ended.') }}</p>
                    @break
                @default
                    <p>{{ __('Premium takes the ads away and lets you upload without limits.') }}</p>
            @endswitch

            <div class="mk-plan__actions">
                @if(in_array($state, ['trial', 'active', 'past_due', 'ending'], true))
                    @if($billingUrl)
                        @if($state === 'past_due')
                            <a class="mk-btn mk-btn--primary" href="{{ $billingUrl }}"><i class="fa-regular fa-credit-card" aria-hidden="true"></i><span>{{ __('Update card') }}</span></a>
                        @elseif($state === 'ending')
                            <a class="mk-btn mk-btn--secondary" href="{{ $billingUrl }}"><span>{{ __('Keep Premium') }}</span></a>
                        @else
                            <a class="mk-btn mk-btn--secondary" href="{{ $billingUrl }}"><span>{{ __('Manage payment') }}</span></a>
                            <a class="mk-btn mk-btn--quiet" href="{{ $billingUrl }}"><span>{{ $state === 'trial' ? __('Cancel trial') : __('Cancel Premium') }}</span></a>
                        @endif
                    @elseif($plan['paypal'] ?? false)
                        <p class="mk-help">{{ __('You pay with PayPal: change or cancel it in your PayPal account, under Payments > Automatic payments.') }}</p>
                    @else
                        <p class="mk-help">{{ __('To change or cancel your plan, contact the site.') }}</p>
                    @endif
                @elseif($canSell)
                    <a class="mk-btn mk-btn--primary" href="{{ locale_url(route('pages.premium')) }}">
                        <i class="fa-solid fa-star" aria-hidden="true"></i>
                        <span>{{ $state === 'ended' ? __('Renew Premium') : __('See Premium') }}</span>
                    </a>
                @endif
            </div>
        </div>
    </div>
</section>

<section class="mk-panel" aria-labelledby="hInvoices">
    <div class="mk-panel__head">
        <h2 class="mk-panel__title" id="hInvoices">{{ __('Latest invoices') }}</h2>
        @if($invoices->isNotEmpty())
            <a class="mk-btn mk-btn--quiet mk-btn--sm mk-panel__action" href="{{ locale_url(route('user.invoices.index')) }}"><span>{{ __('See all') }}</span></a>
        @endif
    </div>
    @if($invoices->isEmpty())
        <div class="mk-panel__body">
            <x-mk.empty icon="file-invoice" :title="__('No invoices yet')" :text="__('Invoices appear here after you pay for Premium.')" compact heading="h3" />
        </div>
    @else
        <ul class="mk-rows">
            @foreach($invoices as $invoice)
                <li class="mk-item mk-item--invoice">
                    <span class="mk-attn__icon mk-attn__icon--muted" aria-hidden="true"><i class="fa-solid fa-file-invoice"></i></span>
                    <div class="mk-item__body">
                        <span class="mk-item__title">{{ \App\Services\InvoiceService::title($invoice) }}</span>
                        <div class="mk-row__meta">
                            <span>{{ \App\Services\InvoiceService::day($invoice) }}</span>
                            <span><span class="mk-status mk-status--public">{{ __('Paid') }}</span></span>
                        </div>
                    </div>
                    <div class="mk-item__aside">
                        {{-- isolated, in the direction of the amount itself (the Arabic form starts with a right-to-left mark) --}}
                        <span class="mk-row__num mk-amount"><bdi>{{ \App\Services\InvoiceService::amount($invoice) }}</bdi></span>
                        <a class="mk-icon-btn mk-icon-btn--muted" href="{{ $invoice->route }}" target="_blank" rel="noopener"
                           title="{{ __('Download PDF') }}" aria-label="{{ __('Download the PDF of :invoice', ['invoice' => \App\Services\InvoiceService::number($invoice)]) }}"><i class="fa-solid fa-download" aria-hidden="true"></i></a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
