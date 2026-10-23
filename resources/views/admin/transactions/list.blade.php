{{--
    Money > Payments & invoices > Payments, in the redesigned admin (the old admin keeps transactions/index.blade.php):
    the money taken (all time, this month, today), the last 12 months and the best customers (folded), every payment
    with its tax and fee, a row menu filled from one template (modules/list-rows.ts: delete the record with Undo),
    the pager. Amounts are shown as before (stored in cents, printed in dollars).
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Payments & invoices'))

@section('content')
    <x-admin.list-page>
    @php $money = fn (float|int $amount): string => '$'.number_format((float) $amount, 2); @endphp

    <x-admin.page-header page="money.payments"
        :description="__(':revenue taken in :count payments (:net after tax and fees). This month :month, today :today.', ['revenue' => $money($summary['revenue']), 'count' => number_format($summary['total']), 'net' => $money($summary['net']), 'month' => $money($summary['month_revenue']), 'today' => $money($summary['today_revenue'])])" />

    @if ($summary['months'] !== [])
        <div class="ad-grid2 ad-console-block">
            <details class="ad-panel ad-fold">
                <summary class="ad-panel-h"><h2>{{ __('By month') }}</h2></summary>
                <ul class="ad-list">
                    @foreach ($summary['months'] as $month)
                        <li><span class="ad-li-text"><b>{{ $month['month'] }}</b><span>{{ trans_choice('1 payment|:count payments', $month['count'], ['count' => number_format($month['count'])]) }}</span></span><span>{{ $money($month['revenue']) }}</span></li>
                    @endforeach
                </ul>
            </details>
            <details class="ad-panel ad-fold">
                <summary class="ad-panel-h"><h2>{{ __('Best customers') }}</h2></summary>
                <ul class="ad-list">
                    @foreach ($summary['customers_top'] as $customer)
                        <li><span class="ad-li-text"><a href="{{ route('admin.users.edit', $customer['id'], false) }}">{{ $customer['username'] }}</a><span>{{ trans_choice('1 payment|:count payments', $customer['count'], ['count' => number_format($customer['count'])]) }}</span></span><span>{{ $money($customer['spent']) }}</span></li>
                    @endforeach
                </ul>
            </details>
        </div>
    @endif

    <form id="ad-payment-delete" method="POST" action="{{ route('admin.transactions.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Payment record deleted') }}"
          data-ad-confirm="{{ __('Delete this payment record?') }}" data-ad-confirm-body="{{ __('Only the record on your site goes; the payment itself stays with the payment provider. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete record') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-payment-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <hr>
        <button type="submit" form="ad-payment-delete" formaction="{{ route('admin.transactions.delete', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete record...') }}</button>
    </template>

    <x-admin.table :paginator="$transactions" :label="__('Payments')" list="payments" table-class="is-list" row-menu="ad-payment-menu">
        <x-slot:head>
            <th scope="col">{{ __('Payment') }}</th>
            <th scope="col" class="is-num">{{ __('Amount') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Tax') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Fee') }}</th>
            <th scope="col">{{ __('Date') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="dollar" :title="__('No payments yet')">{{ __('Payments for premium appear here as they come in.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($transactions as $payment)
            @php $date = \Illuminate\Support\Carbon::parse($payment->date); @endphp
            <tr>
                <td class="c-main"><p><b id="ad-pay-{{ $payment->id }}">{{ $payment->username ?? $payment->name ?? __('Deleted member') }}</b><small>{{ \Illuminate\Support\Str::headline($payment->payment_provider ?? 'stripe') }}@if ($payment->country) &middot; {{ strtoupper($payment->country) }}@endif @if ($payment->stripe_id) &middot; <code>{{ \Illuminate\Support\Str::limit($payment->stripe_id, 24) }}</code>@endif</small></p></td>
                <td class="c-meta is-num"><b>{{ $money($payment->amount / 100) }}</b></td>
                <td class="is-num hide-tab">{{ $money($payment->tax / 100) }}</td>
                <td class="is-num hide-tab">{{ $money($payment->fee / 100) }}</td>
                <td class="is-date" title="{{ $date->translatedFormat('j M Y, H:i') }}">{{ $date->translatedFormat($date->isCurrentYear() ? 'j M' : 'j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-pay-{{ $payment->id }}" data-id="{{ $payment->id }}" @if ($payment->username !== null) data-user="{{ $payment->user_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
