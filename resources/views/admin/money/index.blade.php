{{-- Money > Earnings: what premium brings in this month, and what still needs setting up. --}}
@extends('layouts.admin')
@section('title', __('Earnings'))
@section('admin_page', 'money.overview')

@section('content')
    @include('admin.settings.partials.header', [
        'page' => 'money.overview',
        'title' => __('Earnings'),
        'description' => __('Income from premium plans and what needs setting up.'),
        'crumbs' => [[__('Money'), route('admin.money.index')], [__('Earnings'), null]],
    ])

    <div class="ad-metrics" role="list">
        <a role="listitem" href="{{ route('admin.transactions.index') }}">
            <small>{{ __('This month') }}</small>
            <b>{{ number_format($stats['month'], 2) }} {{ $currency }}</b>
            <span>{{ __(':count payments', ['count' => number_format($stats['month_count'])]) }}</span>
        </a>
        <a role="listitem" href="{{ route('admin.subscriptions.index') }}">
            <small>{{ __('Active subscribers') }}</small>
            <b>{{ number_format($stats['subscribers']) }}</b>
        </a>
        <a role="listitem" href="{{ route('admin.plans.index') }}">
            <small>{{ __('Premium plans') }}</small>
            <b>{{ number_format($stats['plans']) }}</b>
        </a>
        <a role="listitem" href="{{ route('admin.transactions.index') }}">
            <small>{{ __('All time') }}</small>
            <b>{{ number_format($stats['total'], 2) }} {{ $currency }}</b>
        </a>
    </div>

    @php($open = array_values(array_filter($steps, fn (array $step): bool => ! $step['done'])))
    <section class="ad-sec" aria-labelledby="money-steps-title">
        <div class="ad-sec-intro">
            <h2 id="money-steps-title">{{ $open === [] ? __('Everything is set up') : __('What needs setting up') }}</h2>
            <p>{{ __('Members can buy premium once a plan exists and card or PayPal payments are connected.') }}</p>
        </div>
        <div class="ad-sec-body">
            <ol class="ad-steps">
                @foreach ($steps as $step)
                    <li class="ad-sec-pad{{ $step['done'] ? ' is-done' : '' }}">
                        <span class="ad-badge{{ $step['done'] ? ' is-ok' : '' }}">{{ $step['done'] ? __('Done') : __('To do') }}</span>
                        @if ($step['done'])
                            <span>{{ $step['label'] }}</span>
                        @else
                            <a href="{{ $step['url'] }}">{{ $step['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    <nav class="ad-setlist" aria-label="{{ __('Money pages') }}" style="margin-top:20px">
        @foreach ([
            [route('admin.money.ads'), __('Ads'), __('Turn ads on, paste ad codes for each space, video ads and pop-unders.')],
            [route('admin.money.setup'), __('Payment setup'), __('Turn premium on and connect card (Stripe) and PayPal payments step by step.')],
            [route('admin.plans.index'), __('Premium plans'), __('Prices, free trial, and what free and premium members may upload.')],
            [route('admin.money.premium-page'), __('Premium page text'), __('Every text and box on your public Premium page.')],
        ] as [$url, $label, $line])
            <a href="{{ $url }}"><span><b>{{ $label }}</b><span>{{ $line }}</span></span></a>
        @endforeach
    </nav>

    <style>
        .ad-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:8px}
        .ad-metrics a{display:block;padding:16px 20px;color:inherit;text-decoration:none;border-inline-start:1px solid var(--border)}
        .ad-metrics a:first-child{border-inline-start:0}
        .ad-metrics small{display:block;color:var(--text-2);font-size:var(--fs-sm)}
        .ad-metrics b{display:block;font-size:var(--fs-xl,20px);font-weight:600;margin-top:4px}
        .ad-metrics span{display:block;color:var(--text-3);font-size:var(--fs-xs)}
        .ad-steps{list-style:none;margin:0;padding:0}
        .ad-steps li{display:flex;gap:12px;align-items:center;border-top:1px solid var(--border)}
        .ad-steps li:first-child{border-top:0}
    </style>
@endsection
