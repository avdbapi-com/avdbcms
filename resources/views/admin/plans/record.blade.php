{{--
    Money > Plans > one plan (new or existing), in the redesigned admin (the old admin keeps plans/create and edit):
    name, price, billing period and the payment providers' IDs, saved with the save bar, Ctrl+S or the header's
    button; Delete in the header's More menu with its confirmation.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $plan->exists ? $plan->name : __('New plan'))

@section('content')
    @php
        $title = $plan->exists ? $plan->name : __('New plan');
        $duration = (string) old('duration', $plan->duration ?? 1);
    @endphp

    <x-admin.page-header page="money.plans" :title="$title"
        :description="$plan->exists ? $plan->price.' '.((int) $plan->duration === 12 ? __('per year') : __('per month')) : __('A plan is what members pay for premium: a price, monthly or yearly.')"
        :crumbs="[['label' => __('Money'), 'url' => route('admin.money.index')], ['label' => __('Plans'), 'url' => route('admin.plans.index')], ['label' => \Illuminate\Support\Str::limit($title, 40), 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            @if ($plan->exists)
                <x-admin.menu id="ad-plan-more" :label="__('More actions')">
                    <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}"
                          data-ad-confirm="{{ __('Delete this plan?') }}" data-ad-confirm-body="{{ __('Members can no longer buy it. Who already pays for it keeps their premium until it ends.') }}" data-ad-confirm-label="{{ __('Delete plan') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete plan...') }}</button>
                    </form>
                </x-admin.menu>
            @endif
            <button type="submit" class="ad-btn is-primary" form="ad-plan-form">{{ $plan->exists ? __('Save changes') : __('Create plan') }}</button>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-plan-form" method="POST" action="{{ $plan->exists ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" @if ($plan->exists) data-ad-form @endif class="ad-record-main">
        @csrf
        @if ($plan->exists)
            @method('PUT')
        @endif

        <section class="ad-panel" aria-labelledby="ad-plan-details">
            <div class="ad-panel-h"><h2 id="ad-plan-details">{{ __('Plan') }}</h2></div>
            <div class="ad-panel-b ad-record-fields is-3">
                <x-admin.field name="name" :label="__('Name')" :value="$plan->name" :help="__('Shown to members, e.g. Premium monthly.')" />
                {{-- Stored in cents, as the old form did. --}}
                <x-admin.field name="price" type="number" :label="__('Price in cents')" :value="$plan->exists ? ($plan->getAttributes()['price'] ?? 0) : null" :help="__('999 = 9.99')" />
                <x-admin.field name="duration" :label="__('Billed')">
                    <select class="ad-select" id="duration" name="duration" required>
                        <option value="1" @selected($duration === '1')>{{ __('Every month') }}</option>
                        <option value="12" @selected($duration === '12')>{{ __('Every year') }}</option>
                    </select>
                </x-admin.field>
            </div>
        </section>

        <section class="ad-panel" aria-labelledby="ad-plan-providers">
            <div class="ad-panel-h"><h2 id="ad-plan-providers">{{ __('Payment providers') }}</h2></div>
            <div class="ad-panel-b ad-record-fields">
                <x-admin.field name="stripe_id" :label="__('Stripe price ID')" :value="$plan->stripe_id" placeholder="price_..." :help="__('From your Stripe dashboard: the ID of this plan\'s price. Without it, Stripe cannot sell this plan.')" />
                <x-admin.field name="paypal_plan_id" :label="__('PayPal plan ID')" :value="$plan->paypal_plan_id" placeholder="P-..." :help="__('From your PayPal dashboard. Without it, PayPal cannot sell this plan.')" />
            </div>
        </section>

        @if ($plan->exists)
            <x-admin.savebar form="ad-plan-form" />
        @else
            <div class="ad-record-actions">
                <a class="ad-btn" href="{{ route('admin.plans.index') }}">{{ __('Cancel') }}</a>
                <button type="submit" class="ad-btn is-primary">{{ __('Create plan') }}</button>
            </div>
        @endif
    </form>
@endsection
