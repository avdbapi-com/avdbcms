{{--
    Money > Plans in the redesigned admin (the old admin keeps plans/index.blade.php): whether premium and the two
    checkouts are ready, the webhook addresses to give Stripe and PayPal, the plans with their price and payment IDs, a
    row menu filled from one template (modules/list-rows.ts: delete with Undo), the pager and "New plan".
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Plans'))

@section('content')
    @php ob_start(); @endphp
    @php
        $checks = [
            [__('Premium'), $billingStatus['premium_enabled'], $billingStatus['premium_enabled'] ? __('Members can buy a plan.') : __('Premium is off: members cannot buy a plan.')],
            [__('Stripe checkout'), $billingStatus['stripe_ready'] && $billingStatus['stripe_missing_plans'] === 0,
                $billingStatus['stripe_ready'] ? trans_choice('1 plan has no Stripe price ID.|:count plans have no Stripe price ID.', $billingStatus['stripe_missing_plans'], ['count' => $billingStatus['stripe_missing_plans']]) : __('Its keys or webhook are missing.')],
            [__('PayPal checkout'), $billingStatus['paypal_ready'] && $billingStatus['paypal_missing_plans'] === 0,
                $billingStatus['paypal_ready'] ? trans_choice('1 plan has no PayPal plan ID.|:count plans have no PayPal plan ID.', $billingStatus['paypal_missing_plans'], ['count' => $billingStatus['paypal_missing_plans']]) : __('Its keys or webhook are missing.')],
        ];
        $setup = ($page = \App\Support\AdminNav::page('money.setup')) !== null ? \App\Support\AdminNav::url($page) : null;
    @endphp

    <x-admin.page-header page="money.plans">
        <x-slot:actions>
            <a class="ad-btn is-primary" href="{{ route('admin.plans.create') }}">{{ AdminTheme::icon('plus') }}{{ __('New plan') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <section class="ad-panel ad-console-block" aria-labelledby="ad-plans-checkout">
        <div class="ad-panel-h">
            <h2 id="ad-plans-checkout" class="ad-home-grow">{{ __('Payments') }}</h2>
            @if ($setup)
                <a class="ad-small" href="{{ $setup }}">{{ __('Payment setup') }}</a>
            @endif
        </div>
        <ul class="ad-list">
            @foreach ($checks as [$label, $ready, $text])
                <li>
                    <span class="ad-li-text"><b>{{ $label }}</b><span>{{ $ready ? __('Ready.') : $text }}</span></span>
                    <x-admin.status :type="$ready ? 'ok' : 'warn'">{{ $ready ? __('Ready') : __('Check') }}</x-admin.status>
                </li>
            @endforeach
        </ul>
        <dl class="ad-facts">
            <div><dt>{{ __('Stripe webhook address') }}</dt><dd><code>{{ $billingStatus['stripe_webhook_url'] }}</code></dd></div>
            <div><dt>{{ __('PayPal webhook address') }}</dt><dd><code>{{ $billingStatus['paypal_webhook_url'] }}</code></dd></div>
        </dl>
    </section>

    <form id="ad-plan-delete" method="POST" action="{{ route('admin.plans.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Plan deleted') }}"
          data-ad-confirm="{{ __('Delete this plan?') }}" data-ad-confirm-body="{{ __('Members can no longer buy it. Who already pays for it keeps their premium until it ends. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete plan') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-plan-menu">
        <a href="{{ route('admin.plans.edit', ['plan' => '__ID__'], false) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit') }}</a>
        <hr>
        <button type="submit" form="ad-plan-delete" formaction="{{ route('admin.plans.destroy', ['plan' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete plan...') }}</button>
    </template>

    <x-admin.table :paginator="$plans" :label="__('Plans')" list="plans" table-class="is-list" row-menu="ad-plan-menu">
        <x-slot:head>
            <th scope="col">{{ __('Plan') }}</th>
            <th scope="col">{{ __('Price') }}</th>
            <th scope="col" class="hide-tab">{{ __('Stripe price ID') }}</th>
            <th scope="col" class="hide-tab">{{ __('PayPal plan ID') }}</th>
            <th scope="col" class="hide-tab">{{ __('Created') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="credit" :title="__('No plans yet')" :action="__('Create your first plan')" :href="route('admin.plans.create')">{{ __('A plan is what members pay for premium: a price, monthly or yearly.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($plans as $plan)
            @php($period = match ((int) $plan->duration) { 1 => __('per month'), 12 => __('per year'), default => __('every :count months', ['count' => (int) $plan->duration]) })
            <tr>
                <td class="c-main"><p><a id="ad-plan-{{ $plan->id }}" href="{{ route('admin.plans.edit', $plan, false) }}">{{ $plan->name }}</a><small class="ad-phone-only">{{ $plan->price }} {{ $period }}</small></p></td>
                <td class="c-meta"><span class="hide-phone">{{ $plan->price }} <small class="ad-muted">{{ $period }}</small></span>@if (blank($plan->stripe_id) || blank($plan->paypal_plan_id))<small class="ad-phone-only"><x-admin.status type="warn">{{ __('Payment ID missing') }}</x-admin.status></small>@endif</td>
                <td class="hide-tab">@if ($plan->stripe_id)<code>{{ $plan->stripe_id }}</code>@else<x-admin.status type="warn">{{ __('Missing') }}</x-admin.status>@endif</td>
                <td class="hide-tab">@if ($plan->paypal_plan_id)<code>{{ $plan->paypal_plan_id }}</code>@else<x-admin.status type="warn">{{ __('Missing') }}</x-admin.status>@endif</td>
                <td class="hide-tab is-date">{{ $plan->created_at?->translatedFormat('j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-plan-{{ $plan->id }}" data-id="{{ $plan->id }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
