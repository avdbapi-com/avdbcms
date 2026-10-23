@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
@endphp

@section('title', $seoService->renderTemplate('premium_meta_title_template', [], __('Premium'), 180))
@section('description', $seoService->renderTemplate('premium_meta_description_template', [], __('Unleash the full potential of your creative journey with our premium plan. 30 days trial - Then 5 EUR / month - Cancel anytime'), 500))

@section('class', 'ms-0')

@php
    $premiumEnabled = config('site.premium_enabled', true);
    $stripeReady = config('site.stripe_enabled', true) && filled(config('cashier.key')) && filled(config('cashier.secret')) && filled(config('cashier.webhook.secret'));
    $paypalReady = config('site.paypal_enabled', true) && filled(config('services.paypal.client_id')) && filled(config('services.paypal.client_secret')) && filled(config('services.paypal.webhook_id'));
    $billingReady = $stripeReady || $paypalReady;
    $planStripeReadyFor = fn ($plan) => $premiumEnabled && $stripeReady && filled($plan->stripe_id);
    $planPaypalReadyFor = fn ($plan) => $premiumEnabled && $paypalReady && filled($plan->paypal_plan_id);
    $hasReadyPlan = $plans->contains(fn ($plan) => $planStripeReadyFor($plan) || $planPaypalReadyFor($plan));
    $checkoutReady = $premiumEnabled && $billingReady && $hasReadyPlan;
    $selectedPlanId = $plans->first()?->id;
    $premiumComparisonEnabled = config('site.premium_comparison_enabled', true);
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
    $premiumLimitLabel = function (mixed $value) use ($premiumText): string {
        return blank($value) || (int) $value <= 0
            ? $premiumText('premium_unlimited_label')
            : number_format((int) $value);
    };
    $premiumSizeLabel = function (mixed $value) use ($premiumText): string {
        return blank($value) || (int) $value <= 0
            ? $premiumText('premium_unlimited_label')
            : \App\Support\NumberFormat::fileSize((int) $value);
    };
    $premiumIcon = function (string $key) use ($premiumText): string {
        $icon = $premiumText($key);

        return array_key_exists($icon, \App\Support\PremiumPageSettings::iconOptions()) ? $icon : 'fa-star';
    };
    $premiumFeatureKeys = \App\Support\PremiumPageSettings::featureKeys();
    $comparisonBooleanCell = fn (bool $enabled): string => $enabled
        ? '<i class="fa-solid fa-check fs-4 text-success"></i>'
        : '<i class="fa-solid fa-xmark fs-4 text-gray-500"></i>';
    $premiumHeroTitle = $premiumFormat('premium_page_title', ['site' => config('app.name')]);
@endphp

@section('content')
    <div class="avd-secondary-page avd-premium-page" x-data="{plan : {{ $selectedPlanId ? (int) $selectedPlanId : 'null' }}}">
            @if (session('error'))
                <div class="alert alert-danger">
                    {!! session('error') !!}
                </div>
            @endif
            @if (!$premiumEnabled)
                <div class="alert alert-warning">
                    {{ $premiumText('premium_disabled_message') }}
                </div>
            @elseif (!$checkoutReady || $plans->isEmpty())
                <div class="alert alert-warning">
                    {{ $premiumText('premium_checkout_unavailable_message') }}
                </div>
            @endif
            <div class="avd-secondary-hero">
                <div class="avd-secondary-kicker"><i class="fa-solid fa-crown"></i>{{ $premiumText('premium_page_kicker') }}</div>
                <h1 class="avd-secondary-title">{{ $premiumHeroTitle }}</h1>
                <p class="avd-secondary-subtitle">{{ $premiumFormat('premium_page_subtitle', ['site' => config('app.name')]) }}</p>
                <div class="avd-premium-price-line mt-3">
                    @if($plans->isEmpty())
                        <span>{{ $premiumText('premium_empty_plans_message') }}</span>
                    @else
                        @if(!Auth::user()?->premium_subscription && config('plans.trial_period.period') > 0)
                            <span>{{ $premiumFormat('premium_trial_badge_template', ['days' => config('plans.trial_period.period')]) }}</span>
                            <span>{{ $premiumText('premium_then_label') }}</span>
                        @endif
                        @foreach($plans as $plan)
                            <span x-show="plan === {{$plan->id}}" x-cloak>{{$plan->price}} / {{ __($plan->period)}} </span>
                        @endforeach
                        <span>{{ $premiumText('premium_cancel_anytime_label') }}</span>
                        @if($stripeReady && $paypalReady)
                            <span>{{ $premiumText('premium_card_or_paypal_label') }}</span>
                        @elseif($paypalReady)
                            <span>{{ $premiumText('premium_paypal_label') }}</span>
                        @endif
                    @endif
                </div>
            </div>
            <div class="row g-4">
                <div class="col-12 col-lg-6 col-xl-5 mb-3 mb-lg-0 h-100">
                        <div class="mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                <h5>{{ $premiumText('premium_billing_heading') }}</h5>
                                <hr>
                                @if($premiumBool('premium_plan_switcher_enabled'))
                                <div class="d-flex gap-2 mb-3">
                                    @forelse($plans as $plan)
                                        <button
                                            :class="plan === {{$plan->id}} ? 'btn-primary' : 'btn-outline-primary'"
                                            class="btn w-100"
                                            @click="plan = {{$plan->id}}"
                                        >
                                            {{__(Str::ucfirst($plan->name))}}
                                        </button>
                                    @empty
                                        <span class="text-muted w-100">{{ __('No premium plans available') }}</span>
                                    @endforelse
                                </div>
                                @endif
                                <small class="text-muted">{{ $premiumText('premium_annual_savings_text') }}</small>
                            </div>
                        </div>
                    </div>
                    @foreach($plans as $plan)
                        @php
                            $planStripeReady = $planStripeReadyFor($plan);
                            $planPaypalReady = $planPaypalReadyFor($plan);
                            $planReady = $planStripeReady || $planPaypalReady;
                        @endphp
                        <div class="card shadow avd-premium-plan-card" x-show="plan === {{$plan->id}}" x-cloak>
                            <div class="card-body">
                                <h5 class="card-title fs-1 text-primary text-center">{{$plan->price}} / {{ __($plan->period) }}</h5>
                            </div>
                            <ul class="list-group list-group-flush">
                                @foreach($premiumFeatureKeys as $featureKey)
                                    @if($premiumBool('premium_feature_' . $featureKey . '_enabled'))
                                        <li class="list-group-item d-flex align-items-center gap-4 py-3">
                                            <i class="fa-solid {{ $premiumIcon('premium_feature_' . $featureKey . '_icon') }} fa-2x text-danger"></i>
                                            <div class="d-flex flex-column gap-1">
                                                <div class="fw-bold">{{ $premiumText('premium_feature_' . $featureKey . '_title') }}</div>
                                                {{ $premiumText('premium_feature_' . $featureKey . '_description') }}
                                            </div>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                            <div class="card-body text-center py-3">
                                @if(Auth::user()?->has_current_subscription)
                                    <div class="alert alert-success">
                                        {!! __('premium.exist', ['route' => route('user.edit')]) !!}
                                    </div>
                                @elseif(!$planReady)
                                    <button class="btn btn-secondary fs-5 w-100" type="button" disabled>{{ $premiumText('premium_plan_unavailable_label') }}</button>
                                    <div class="text-sm text-muted mt-3">
                                        {{ $premiumText('premium_plan_unavailable_note') }}
                                    </div>
                                @elseif(Auth::user()?->premium_subscription)
                                    <div class="d-grid gap-2">
                                        @if($planStripeReady)
                                            <a href="{{route('premium.subscribe', $plan)}}" class="btn btn-primary fs-5 w-100">{{ $premiumText('premium_pay_card_label') }}</a>
                                        @endif
                                        @if($planPaypalReady)
                                            <a href="{{route('premium.subscribe.paypal', $plan)}}" class="btn btn-warning fs-5 w-100"><i class="fa-brands fa-paypal me-1"></i>{{ $premiumText('premium_pay_paypal_label') }}</a>
                                        @endif
                                    </div>
                                    <div class="text-sm text-muted mt-3">
                                        <p class="fw-bold">{{ $premiumFormat('premium_billing_without_trial_summary', ['date' => now()->translatedFormat('j F Y'), 'period' => __($plan->period)]) }}</p>
                                        {{ $premiumText('premium_billing_charge_note') }}
                                        {{ $premiumText('premium_billing_refund_note') }}
                                    </div>
                                @else
                                    <div class="d-grid gap-2">
                                        @if($planStripeReady)
                                            <a href="{{route('premium.subscribe', $plan)}}" class="btn btn-primary fs-5 w-100">{{ $premiumText('premium_start_card_label') }}</a>
                                        @endif
                                        @if($planPaypalReady)
                                            <a href="{{route('premium.subscribe.paypal', $plan)}}" class="btn btn-warning fs-5 w-100"><i class="fa-brands fa-paypal me-1"></i>{{ $premiumText('premium_start_paypal_label') }}</a>
                                        @endif
                                    </div>
                                    <div class="text-sm text-muted mt-3">
                                        <p class="fw-bold">{{ $premiumFormat('premium_billing_trial_summary', ['date' => now()->add('days', config('plans.trial_period.period'))->translatedFormat('j F Y'), 'period' => __($plan->period)]) }}</p>
                                        {{ $premiumText('premium_billing_charge_note') }}
                                        {{ $premiumText('premium_billing_refund_note') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="col-12 col-lg-6 col-xl-7 h-100">
                    @if($premiumBool('premium_timeline_enabled') && $checkoutReady && $plans->isNotEmpty() && !Auth::user()?->premium_subscription)
                    <div class="card card-body bg-white mb-3 h-50 d-flex justify-content-center align-content-center">
                        <div class="d-flex gap-2 step">
                            <div class="progression">
                                <div class="icon bg-success">
                                    <i class="fa fa-gift text-white"></i>
                                </div>
                                <div class="line">
                                    <div class="half-line bg-success"></div>
                                </div>
                            </div>
                            <div>
                                <p class="fw-bold mb-1">{{ $premiumFormat('premium_timeline_trial_title', ['days' => config('plans.trial_period.period'), 'site' => config('app.name')]) }}</p>
                                <small class="text-muted text-sm">{{ $premiumFormat('premium_timeline_trial_description', ['days' => config('plans.trial_period.period'), 'site' => config('app.name')]) }}</small>
                            </div>
                        </div>
                        @php($premiumReminderDate = now()->add('days', config('plans.trial_period.period'))->sub('days', config('plans.trial_period.email_reminder'))->translatedFormat('j F Y'))
                        <div class="d-flex gap-2 step">
                            <div class="progression">
                                <div class="icon bg-white border border-secondary">
                                    <i class="far fa-bell"></i>
                                </div>
                                <div class="line"></div>
                            </div>
                            <div>
                                <p class="fw-bold mb-1">{{ $premiumFormat('premium_timeline_reminder_title', ['date' => $premiumReminderDate, 'days' => config('plans.trial_period.email_reminder'), 'site' => config('app.name')]) }}</p>
                                <small class="text-muted">{{ $premiumFormat('premium_timeline_reminder_description', ['date' => $premiumReminderDate, 'days' => config('plans.trial_period.email_reminder'), 'site' => config('app.name')]) }}</small>
                            </div>
                        </div>
                        @php($premiumStartDate = now()->add('days', config('plans.trial_period.period'))->translatedFormat('j F Y'))
                        <div class="d-flex gap-2 step">
                            <div class="progression">
                                <div class="icon bg-white border border-secondary">
                                    <i class="fa fa-star text-warning"></i>
                                </div>
                            </div>
                            <div>
                                <p class="fw-bold mb-1">{{ $premiumFormat('premium_timeline_start_title', ['date' => $premiumStartDate, 'days' => config('plans.trial_period.period'), 'site' => config('app.name')]) }}</p>
                                <small class="text-muted">{{ $premiumFormat('premium_timeline_start_description', ['date' => $premiumStartDate, 'days' => config('plans.trial_period.period'), 'site' => config('app.name')]) }}</small>
                            </div>
                        </div>
                    </div>
                    @endif
                    @if($premiumComparisonEnabled)
                    <div @class(['card h-50 avd-premium-comparison-card', 'h-100' => Auth::user()?->premium_subscription])>
                        <div class="card-header text-center h5">
                            {{ $premiumText('premium_comparison_title') }}
                        </div>
                        <div class="card-body">
                            <table class="table" style="--table-cell-padding-y:.6rem">
                                <thead>
                                <tr>
                                    <th scope="col"></th>
                                    <th scope="col">{{ $premiumText('premium_comparison_free_label') }}</th>
                                    <th scope="col">{{ $premiumText('premium_comparison_premium_label') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @if($premiumBool('premium_comparison_uploads_row_enabled'))
                                <tr class="py-4">
                                    <td class="fw-bold">{{ $premiumText('premium_comparison_uploads_label') }}</td>
                                    <td>{{config('plans.free.max_uploads')}}</td>
                                    <td>{{ $premiumLimitLabel(config('plans.premium.max_uploads')) }}</td>
                                </tr>
                                @endif
                                @if($premiumBool('premium_comparison_file_size_row_enabled'))
                                <tr>
                                    <td class="fw-bold">{{ $premiumText('premium_comparison_file_size_label') }}</td>
                                    <td>@size(config('plans.free.max_file_size'))</td>
                                    <td>@size(config('plans.premium.max_file_size'))</td>
                                </tr>
                                @endif
                                @if($premiumBool('premium_comparison_storage_row_enabled'))
                                <tr>
                                    <td class="fw-bold">{{ $premiumText('premium_comparison_storage_label') }}</td>
                                    <td>@size(config('plans.free.max_videos_storage'))</td>
                                    <td>{{ $premiumSizeLabel(config('plans.premium.max_videos_storage')) }}</td>
                                </tr>
                                @endif
                                @if($premiumBool('premium_comparison_downloads_row_enabled'))
                                <tr>
                                    <td class="fw-bold">{{ $premiumText('premium_comparison_downloads_label') }}</td>
                                    <td>{!! $comparisonBooleanCell($premiumBool('premium_comparison_downloads_free_enabled')) !!}</td>
                                    <td>{!! $comparisonBooleanCell($premiumBool('premium_comparison_downloads_premium_enabled')) !!}</td>
                                </tr>
                                @endif
                                @if($premiumBool('premium_comparison_ads_row_enabled'))
                                <tr>
                                    <td class="fw-bold">{{ $premiumText('premium_comparison_ads_label') }}</td>
                                    <td>{!! $comparisonBooleanCell($premiumBool('premium_comparison_ads_free_enabled')) !!}</td>
                                    <td>{!! $comparisonBooleanCell($premiumBool('premium_comparison_ads_premium_enabled')) !!}</td>
                                </tr>
                                @endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
    </div>
@endsection
