{{--
    The Premium page /premium (member design 6.16; mockups premium, premium-states, premium-welcome): the plans as
    cards (a chosen card with an inset ring, Monthly / Yearly as a segmented choice), "How the free trial works", and
    Free and Premium as a table from 640px and stacked rows on phones (account-5). Every text of the page is the owner's
    (Money > Premium page text); prices carry the currency symbol (account-51). One plain message per state, never the
    name of a payment service in an error (account-32). No ads here (OD-M10: the layout's rule for this route).
    Back from a checkout: /premium?checkout=success opens the welcome sheet once Premium is on (until then "Premium is
    being confirmed"), /premium?checkout=cancelled says nobody was charged. While a payment is being confirmed (that
    state, or PayPal's "Almost done" return) the page offers nothing to buy, so nobody pays twice; the PayPal return
    then waits on /premium?checkout=success like a card payment. A member whose last payment failed (no Premium while
    the payment service retries) reads a warning and never gets the plan buttons either.
--}}
@extends('layouts.default')

@use('App\Support\PremiumAvailability')
@use('Illuminate\Support\Facades\Auth')

@php
    $seoService = app(\App\Services\SeoService::class);
@endphp

@section('title', $seoService->renderTemplate('premium_meta_title_template', [], __('Premium'), 180))
@section('description', $seoService->renderTemplate('premium_meta_description_template', [], __(':site Premium: more uploads, bigger files and no ads on videos.', ['site' => config('app.name')]), 500))

{{-- No class of its own on the main column (R1-004): the side menu is pinned here as on every page of the default
     layout (ThemeStructure), and the column's start margin is what keeps the page out from under it ("ms-0" removed
     that margin, so the menu covered 208-215 px of the page from 992 to 1919 px). --}}

@php
    $member = Auth::user();
    $premiumDefaults = \App\Support\PremiumPageSettings::defaults();
    $premiumText = function (string $key) use ($premiumDefaults): string {
        $value = config('site.' . $key, $premiumDefaults[$key] ?? '');

        return is_scalar($value) ? __((string) $value) : '';
    };
    $premiumBool = function (string $key) use ($premiumDefaults): bool {
        $value = config('site.' . $key, ($premiumDefaults[$key] ?? '0') === '1');

        return is_bool($value) ? $value : (string) $value === '1';
    };
    $premiumFormat = fn (string $key, array $replacements = []): string => \App\Support\PremiumPageSettings::format($premiumText($key), $replacements);
    // the owner kept the shipped text (PremiumPageSettings::COUNTED_DEFAULTS are then said with the language's forms)
    $premiumKept = function (string $key) use ($premiumDefaults): bool {
        $value = config('site.' . $key, $premiumDefaults[$key] ?? '');

        return is_scalar($value) && (string) $value === (string) ($premiumDefaults[$key] ?? '');
    };
    $premiumIcon = function (string $key) use ($premiumText): string {
        $icon = $premiumText($key);

        return array_key_exists($icon, \App\Support\PremiumPageSettings::iconOptions()) ? $icon : 'fa-star';
    };

    $premiumEnabled = PremiumAvailability::enabled();
    $canSell = PremiumAvailability::canSell();
    $hasCurrent = (bool) ($member?->has_current_subscription);
    $isPremium = (bool) ($member?->is_premium);
    // a subscription whose last payment failed (past due, unpaid, PayPal "suspended"): no Premium while it is retried
    $pastDue = $member !== null && $hasCurrent && ! $isPremium;
    $paysWithPayPal = $pastDue && $member->premium_subscription?->payment_provider === 'paypal';
    $checkout = request()->query('checkout');
    $confirming = $checkout === 'success' && $member !== null && ! $isPremium;
    // back from PayPal before PayPal confirmed (PremiumController::paypalConfirming(): an info message with a title)
    $paypalWaiting = $member !== null && ! $isPremium && ! $confirming
        && is_string(session('info_title')) && session('info_title') !== '' && is_string(session('info')) && session('info') !== '';
    // a payment is being confirmed: nothing to buy now, so nobody starts a second checkout and pays twice
    $waiting = $confirming || $paypalWaiting;
    $welcome = $checkout === 'success' && $isPremium;
    $waitQuery = request()->query('wait', 0);
    $waitRound = max(0, min(20, is_numeric($waitQuery) ? (int) $waitQuery : 0));
    $trialDays = max(0, (int) config('plans.trial_period.period', 0));
    $reminderDays = max(0, (int) config('plans.trial_period.email_reminder', 0));
    $trialOffered = $trialDays > 0 && ! $member?->premium_subscription;
    $readyPlans = $plans->filter(fn ($plan) => PremiumAvailability::planReady($plan));
    $chosenPlan = ($readyPlans->first() ?? $plans->first())?->id;
    $showButtons = $canSell && ! $hasCurrent && ! $waiting;
    $planUrl = \App\Http\Controllers\User\ProfileController::sectionUrl('premium');
    // the Monthly / Yearly choice only when there is something to buy: the plans that can be bought right now
    $showSwitcher = $showButtons && $premiumBool('premium_plan_switcher_enabled') && $readyPlans->count() > 1;
    $monthlyPlan = $plans->first(fn ($plan) => PremiumAvailability::months($plan) === 1);
    // dates and prices as Account settings > Premium and the invoices write them, in the reader's language (R2A-038:
    // "26 nov. 2026", "5 €"; the owner's texts put a date in with PremiumPageSettings::format(), which keeps one full stop)
    $day = fn ($date): string => \App\Support\BillingFormat::date(\Illuminate\Support\Carbon::instance($date));
    $limitLabel = function (mixed $value) use ($premiumText): string {
        // plain digits: a thousands separator is a different character in every language
        return blank($value) || (int) $value <= 0 ? $premiumText('premium_unlimited_label') : (string) (int) $value;
    };
    // sizes in the reader's language ("2 Go", "2 ГБ", "2 غيغابايت"; 1.2.3 round 3, R3-045's look-around: "2 GB" everywhere)
    $sizeLabel = function (mixed $value) use ($premiumText): string {
        return blank($value) || (int) $value <= 0 ? $premiumText('premium_unlimited_label') : \App\Support\NumberFormat::localFileSize((int) $value);
    };
    $features = collect(\App\Support\PremiumPageSettings::featureKeys())
        ->filter(fn (string $key) => $premiumBool('premium_feature_' . $key . '_enabled'))
        ->map(fn (string $key) => [
            'icon' => $premiumIcon('premium_feature_' . $key . '_icon'),
            'title' => $premiumText('premium_feature_' . $key . '_title'),
            'text' => $premiumText('premium_feature_' . $key . '_description'),
        ])
        ->values();
    $methodsLabel = PremiumAvailability::stripeReady() && PremiumAvailability::paypalReady()
        ? $premiumText('premium_card_or_paypal_label')
        : (PremiumAvailability::paypalReady() && ! PremiumAvailability::stripeReady() ? $premiumText('premium_paypal_label') : '');
    // "Card or PayPal" is said of the plans' buttons: only where they are (R3-057: a member who already has Premium, or
    // whose payment is being confirmed, read it under cards without buttons)
    $notes = array_values(array_filter([$plans->isNotEmpty() ? $premiumText('premium_annual_savings_text') : '', $showButtons ? $methodsLabel : ''], fn ($note) => trim($note) !== ''));
    $booleanCell = fn (bool $yes): string => $yes
        ? '<i class="fa-solid fa-check" aria-hidden="true"></i> ' . e(__('Yes'))
        : '<i class="fa-solid fa-xmark" aria-hidden="true"></i> ' . e(__('No'));
    // "Ads on videos" (R1-123, mockup premium): no ads is the benefit, so it reads "No ads" with the check; a plan with ads
    // says "Yes" without a mark (a cross beside the Premium column read as a missing feature)
    $adsCell = fn (bool $ads): string => $ads
        ? e(__('Yes'))
        : '<i class="fa-solid fa-check" aria-hidden="true"></i> ' . e(__('No ads'));
    $comparisonRows = array_values(array_filter([
        $premiumBool('premium_comparison_uploads_row_enabled') ? [$premiumText('premium_comparison_uploads_label'), e((string) (int) config('plans.free.max_uploads')), e($limitLabel(config('plans.premium.max_uploads')))] : null,
        $premiumBool('premium_comparison_file_size_row_enabled') ? [$premiumText('premium_comparison_file_size_label'), '<bdi>' . e(\App\Support\NumberFormat::localFileSize((int) config('plans.free.max_file_size'))) . '</bdi>', '<bdi>' . e($sizeLabel(config('plans.premium.max_file_size'))) . '</bdi>'] : null,
        $premiumBool('premium_comparison_storage_row_enabled') ? [$premiumText('premium_comparison_storage_label'), '<bdi>' . e($sizeLabel(config('plans.free.max_videos_storage'))) . '</bdi>', '<bdi>' . e($sizeLabel(config('plans.premium.max_videos_storage'))) . '</bdi>'] : null,
        $premiumBool('premium_comparison_downloads_row_enabled') ? [$premiumText('premium_comparison_downloads_label'), $booleanCell($premiumBool('premium_comparison_downloads_free_enabled')), $booleanCell($premiumBool('premium_comparison_downloads_premium_enabled'))] : null,
        $premiumBool('premium_comparison_ads_row_enabled') ? [$premiumText('premium_comparison_ads_label'), $adsCell($premiumBool('premium_comparison_ads_free_enabled')), $adsCell($premiumBool('premium_comparison_ads_premium_enabled'))] : null,
    ]));
    $timelineShown = $premiumBool('premium_timeline_enabled') && $trialOffered && $showButtons && $plans->isNotEmpty();
    $trialEnds = now()->addDays($trialDays);
    $reminderDate = $trialEnds->copy()->subDays($reminderDays);
    // The trial's days in the kept default texts are said with the language's forms of "day" (review of R1-122: "1 days
    // trial", and Arabic has other forms for 1, 2 and 11 or more days); an owner's own text is used as written.
    $trialDaysLabel = fn (string $key, array $replacements): string => $premiumKept($key)
        ? trans_choice('{1} :count day trial|[2,*] :count days trial', $trialDays)
        : $premiumFormat($key, $replacements);
    $reminderNote = $premiumKept('premium_timeline_reminder_description')
        ? trans_choice('{1} We will email you :count day before your trial ends.|[2,*] We will email you :count days before your trial ends.', $reminderDays)
        : $premiumFormat('premium_timeline_reminder_description', ['date' => $day($reminderDate), 'days' => $reminderDays, 'site' => config('app.name')]);
@endphp

@section('content')
<div class="avd-mk mk-page mk-page--form avd-premium-page" x-data="{ chosen: {{ $chosenPlan ? (int) $chosenPlan : 'null' }} }">

    {{-- The view-only demo's refusal of a payment ("Start with card", "Start with PayPal" send it back here): "Not saved"
         and why, focused, as on every other member page (R2A-015: the page came back without a word). The page's own
         one-time messages follow below. --}}
    <x-mk.flash refusal :messages="false" />

    @if($waiting)
        {{-- One waiting message, nothing to buy. It looks again every 8 seconds, 8 times; the PayPal return waits on
             /premium?checkout=success from its first look on, so the welcome sheet opens once Premium is on. --}}
        <div class="mk-alert" role="status" data-premium-waiting
             @if($waitRound < 8) x-data x-init="setTimeout(() => { const url = new URL(window.location.href); url.searchParams.set('checkout', 'success'); url.searchParams.set('wait', '{{ $waitRound + 1 }}'); window.location.replace(url.toString()); }, 8000)" @endif>
            <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
            <div class="mk-alert__body">
                <span class="mk-alert__title">{{ $paypalWaiting ? session('info_title') : __('Almost done') }}</span>
                <span>
                    @if($paypalWaiting)
                        {{ session('info') }}
                    @elseif($waitRound < 8)
                        {{ __('Premium is being confirmed. It starts in a minute.') }}
                    @else
                        {{ __('Premium is still being confirmed. Look again in a few minutes: you were only charged if Premium starts.') }}
                    @endif
                    <a href="{{ $planUrl }}">{{ __('See your plan') }}</a>
                </span>
            </div>
            <span></span>
        </div>
    @elseif($isPremium)
        {{-- account-2: a sentence and a link, the title would only repeat it --}}
        <x-mk.alert type="success">{{ $welcome ? __('Premium is on.') : __('You already have Premium.') }} <a href="{{ $planUrl }}">{{ __('See your plan') }}</a></x-mk.alert>
    @elseif($pastDue)
        <x-mk.alert type="warning" :title="__('Payment problem')">{{ $paysWithPayPal ? __('Your last payment did not go through. Check your PayPal account to keep Premium.') : __('Your last payment did not go through. Update your card to keep Premium.') }} <a href="{{ $planUrl }}">{{ __('See your plan') }}</a></x-mk.alert>
    @endif

    @if($checkout === 'cancelled')
        <x-mk.alert type="info">{{ __('Checkout cancelled. You were not charged.') }}</x-mk.alert>
    @endif

    {{-- The one-time messages (always escaped, as <x-mk.flash>). A message with a title is PayPal's "Almost done"
         (account-16): it is the waiting message above, and a member who already has Premium does not need it. --}}
    @foreach(['success' => 'success', 'status' => 'success', 'info' => 'info', 'warning' => 'warning', 'error' => 'danger'] as $flashKey => $flashType)
        @continue($flashKey === 'info' && is_string(session('info_title')) && session('info_title') !== '')
        @if(is_string(session($flashKey)) && session($flashKey) !== '')
            <x-mk.alert :type="$flashType" dismissible>{{ session($flashKey) }}</x-mk.alert>
        @endif
    @endforeach

    @if($hasCurrent || $waiting)
        {{-- the member's own state above says everything --}}
    @elseif(! $premiumEnabled)
        <x-mk.alert type="info" :title="__('Premium is not available yet')">{{ $premiumText('premium_disabled_message') }}</x-mk.alert>
    @elseif(! $canSell)
        <x-mk.alert type="info" :title="__('Premium is not available yet')">{{ $plans->isEmpty() ? $premiumText('premium_empty_plans_message') : $premiumText('premium_checkout_unavailable_message') }}</x-mk.alert>
    @endif

    <x-mk.head :kicker="$premiumText('premium_page_kicker')" icon="star"
               :title="$premiumFormat('premium_page_title', ['site' => config('app.name')])"
               :desc="$premiumFormat('premium_page_subtitle', ['site' => config('app.name')])">
        @if($showSwitcher)
            <x-slot:actions>
                <div class="mk-seg" role="radiogroup" aria-label="{{ $premiumText('premium_billing_heading') }}">
                    @foreach($readyPlans as $plan)
                        <label class="mk-seg__item">
                            <input type="radio" name="billing" value="{{ $plan->id }}" x-model.number="chosen" @checked($plan->id === $chosenPlan)>
                            <span class="mk-seg__label">{{ PremiumAvailability::name($plan) }}</span>
                        </label>
                    @endforeach
                </div>
            </x-slot:actions>
        @endif
    </x-mk.head>

    @if($plans->isNotEmpty())
        <section class="mk-plans-area" aria-labelledby="premium-plans-title">
            <h2 class="mk-sr" id="premium-plans-title">{{ $premiumText('premium_billing_heading') }}</h2>
            <div class="mk-plans">
                @foreach($plans as $plan)
                    @php
                        $ready = PremiumAvailability::planReady($plan);
                        $months = PremiumAvailability::months($plan);
                        $isChosen = $plan->id === $chosenPlan;
                        $savedMonths = ($monthlyPlan && $months > 1)
                            ? (int) floor(((int) $monthlyPlan->getRawOriginal('price') * $months - (int) $plan->getRawOriginal('price')) / max(1, (int) $monthlyPlan->getRawOriginal('price')))
                            : 0;
                        $summary = $trialOffered
                            ? $premiumFormat('premium_billing_trial_summary', ['date' => $day($trialEnds), 'period' => __($plan->period)])
                            : $premiumFormat('premium_billing_without_trial_summary', ['date' => $day(now()), 'period' => __($plan->period)]);
                    @endphp
                    {{-- the ring marks the plan the buttons buy: none while nothing can be bought --}}
                    <section @class(['mk-plan-card', 'is-chosen' => $isChosen && $ready && $showButtons, 'is-disabled' => ! $ready])
                             @if($ready && $showButtons) :class="{ 'is-chosen': chosen === {{ $plan->id }} }" @endif
                             aria-labelledby="plan-{{ $plan->id }}-title">
                        <div class="mk-plan-card__top">
                            <h3 class="mk-panel__title" id="plan-{{ $plan->id }}-title">{{ PremiumAvailability::name($plan) }}</h3>
                            {{-- one badge: "not available yet", else what a longer plan saves (mockup premium), else the
                                 owner's free-trial badge --}}
                            @if(! $ready && $canSell)
                                <span class="mk-badge mk-badge--neutral">{{ $premiumText('premium_plan_unavailable_label') }}</span>
                            @elseif($savedMonths > 0)
                                <span class="mk-badge mk-badge--premium">{{ trans_choice('{1} Save :count month|[2,*] Save :count months', $savedMonths, ['count' => $savedMonths]) }}</span>
                            @elseif($trialOffered && $showButtons)
                                <span class="mk-badge mk-badge--premium">{{ $trialDaysLabel('premium_trial_badge_template', ['days' => $trialDays]) }}</span>
                            @endif
                        </div>
                        <div class="mk-plan-card__price">
                            <span class="mk-bignum"><bdi>{{ \App\Support\BillingFormat::price($plan) }}</bdi></span>
                            <span class="mk-muted">{{ PremiumAvailability::per($plan) }}@if($equivalent = PremiumAvailability::monthlyEquivalent($plan)) ({{ $equivalent }})@endif</span>
                        </div>
                        @if(! $ready && $canSell)
                            <p class="mk-muted">{{ $premiumText('premium_plan_unavailable_note') }}</p>
                        @elseif($showButtons)
                            <p class="mk-muted">{{ trim($summary . ' ' . $premiumText('premium_cancel_anytime_label')) }}</p>
                        @endif
                        @if($features->isNotEmpty())
                            <ul class="mk-facts mk-facts--ok mk-features">
                                @foreach($features as $feature)
                                    <li>
                                        <i class="fa-solid {{ $feature['icon'] }}" aria-hidden="true"></i>
                                        <span><strong class="mk-feature__title">{{ $feature['title'] }}</strong>@if(trim($feature['text']) !== '')<span class="mk-muted"> {{ $feature['text'] }}</span>@endif</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($showButtons && $ready)
                            <div class="mk-plan-card__actions">
                                @if(PremiumAvailability::planStripeReady($plan))
                                    <a href="{{ route('premium.subscribe', $plan) }}"
                                       @class(['mk-btn mk-btn--block', 'mk-btn--primary' => $isChosen, 'mk-btn--secondary' => ! $isChosen])
                                       :class="{ 'mk-btn--primary': chosen === {{ $plan->id }}, 'mk-btn--secondary': chosen !== {{ $plan->id }} }">
                                        <span>{{ $trialOffered ? $premiumText('premium_start_card_label') : $premiumText('premium_pay_card_label') }}</span>
                                    </a>
                                @endif
                                @if(PremiumAvailability::planPaypalReady($plan))
                                    <a href="{{ route('premium.subscribe.paypal', $plan) }}" class="mk-btn mk-btn--quiet mk-btn--block">
                                        <i class="fa-brands fa-paypal" aria-hidden="true"></i>
                                        <span>{{ $trialOffered ? $premiumText('premium_start_paypal_label') : $premiumText('premium_pay_paypal_label') }}</span>
                                    </a>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
            @if($notes !== [])
                <p class="mk-help mk-plans__note">{!! implode('&nbsp;· ', array_map('e', $notes)) !!}</p>
            @endif
            @if($showButtons)
                <p class="mk-help mk-plans__note">{{ trim($premiumText('premium_billing_charge_note') . ' ' . $premiumText('premium_billing_refund_note')) }}</p>
            @endif
        </section>
    @endif

    @if($timelineShown)
        <section class="mk-panel" aria-labelledby="premium-timeline-title">
            <div class="mk-panel__head"><h2 class="mk-panel__title" id="premium-timeline-title">{{ __('How the free trial works') }}</h2></div>
            <ol class="mk-timeline">
                <li>
                    <strong>{{ $trialDaysLabel('premium_timeline_trial_title', ['days' => $trialDays, 'site' => config('app.name')]) }}</strong>
                    <span>{{ $premiumFormat('premium_timeline_trial_description', ['days' => $trialDays, 'site' => config('app.name')]) }}</span>
                </li>
                @if($reminderDays > 0 && $reminderDays < $trialDays)
                    <li>
                        <strong>{{ $premiumFormat('premium_timeline_reminder_title', ['date' => $day($reminderDate), 'days' => $reminderDays, 'site' => config('app.name')]) }}</strong>
                        <span>{{ $reminderNote }}</span>
                    </li>
                @endif
                <li>
                    <strong>{{ $premiumFormat('premium_timeline_start_title', ['date' => $day($trialEnds), 'days' => $trialDays, 'site' => config('app.name')]) }}</strong>
                    <span>{{ $premiumFormat('premium_timeline_start_description', ['date' => $day($trialEnds), 'days' => $trialDays, 'site' => config('app.name')]) }}</span>
                </li>
            </ol>
        </section>
    @endif

    @if(config('site.premium_comparison_enabled', true) && $comparisonRows !== [])
        <section class="mk-panel" aria-labelledby="premium-compare-title">
            <div class="mk-panel__head"><h2 class="mk-panel__title" id="premium-compare-title">{{ $premiumText('premium_comparison_title') }}</h2></div>
            <div class="mk-compare">
                <table class="mk-compare__table">
                    <thead>
                        <tr>
                            <th scope="col"><span class="mk-sr">{{ __('What you get') }}</span></th>
                            <th scope="col">{{ $premiumText('premium_comparison_free_label') }}</th>
                            <th scope="col">{{ $premiumText('premium_comparison_premium_label') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparisonRows as [$rowLabel, $free, $premium])
                            <tr>
                                <td class="fw-bold">{{ $rowLabel }}</td>
                                <td>{!! $free !!}</td>
                                <td><strong>{!! $premium !!}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mk-compare__cards">
                    @foreach($comparisonRows as [$rowLabel, $free, $premium])
                        <div class="mk-compare__row">
                            <span class="mk-compare__what">{{ $rowLabel }}</span>
                            <span><span class="mk-muted">{{ $premiumText('premium_comparison_free_label') }}</span> {!! $free !!}</span>
                            <span><span class="mk-muted">{{ $premiumText('premium_comparison_premium_label') }}</span> <strong>{!! $premium !!}</strong></span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @includeWhen($welcome, 'modals.premium')
</div>
@endsection
