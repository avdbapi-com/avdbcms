{{--
    Money > Subscribers > Subscriptions, in the redesigned admin (the old admin keeps subscriptions/index.blade.php):
    who pays for premium, one state at a time, a row menu filled from one template (modules/list-rows.ts: cancel or
    activate, asked first; delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Subscribers'))

@section('content')
    <x-admin.list-page>
    @php
        $labels = ['active' => __('Active'), 'trialing' => __('Trial'), 'canceled' => __('Cancelled'), 'unpaid' => __('Unpaid'), 'past_due' => __('Payment late'), 'incomplete' => __('Not finished')];
        $tones = ['active' => 'ok', 'trialing' => 'info', 'canceled' => 'off', 'unpaid' => 'warn', 'past_due' => 'warn'];
        $label = fn (?string $state): string => $labels[$state] ?? \Illuminate\Support\Str::headline((string) $state);
        $total = array_sum($states);
    @endphp

    <x-admin.page-header page="money.subscribers"
        :description="trans_choice('1 subscription.|:count subscriptions.', $total, ['count' => number_format($total)]).' '.__('Active: :count.', ['count' => number_format(($states['active'] ?? 0) + ($states['trialing'] ?? 0))])" />

    <form id="ad-subscription-change" method="POST" action="{{ route('admin.subscriptions.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <form id="ad-subscription-delete" method="POST" action="{{ route('admin.subscriptions.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Subscription deleted') }}"
          data-ad-confirm="{{ __('Delete this subscription for good?') }}" data-ad-confirm-body="{{ __('Its record is removed. Payments already made stay in Payments. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete subscription') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-subscription-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <button type="submit" form="ad-subscription-change" formaction="{{ route('admin.subscriptions.cancel', ['subscription' => '__ID__'], false) }}" data-ad-when="!canceled" data-ad-expect="canceled" data-ad-done="{{ __('Subscription cancelled') }}"
                data-ad-confirm="{{ __('Cancel this subscription?') }}" data-ad-confirm-body="{{ __('Premium ends now for this member.') }}" data-ad-confirm-label="{{ __('Cancel subscription') }}">{{ AdminTheme::icon('ban') }}{{ __('Cancel subscription...') }}</button>
        <button type="submit" form="ad-subscription-change" formaction="{{ route('admin.subscriptions.activate', ['subscription' => '__ID__'], false) }}" data-ad-when="canceled" data-ad-expect="active" data-ad-done="{{ __('Subscription active again') }}">{{ AdminTheme::icon('check') }}{{ __('Activate') }}</button>
        <hr>
        <button type="submit" form="ad-subscription-delete" formaction="{{ route('admin.subscriptions.delete', ['subscription' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
    </template>

    <x-admin.table :paginator="$subscriptions" :label="__('Subscriptions')" list="subscriptions" table-class="is-list" row-menu="ad-subscription-menu">
        @if (count($states) > 1 || $state !== null)
            <x-slot:toolbar>
                <form method="GET" action="{{ route('admin.subscriptions.index') }}" class="ad-filter" aria-label="{{ __('Filter subscriptions') }}">
                    <select class="ad-select" name="state" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                        <option value="">{{ __('All subscriptions') }}</option>
                        @foreach ($states as $value => $count)
                            <option value="{{ $value }}" @selected($state === (string) $value)>{{ $label((string) $value) }} ({{ number_format($count) }})</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
                </form>
            </x-slot:toolbar>
        @endif

        <x-slot:head>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Plan') }}</th>
            <th scope="col" class="hide-tab">{{ __('Card') }}</th>
            <th scope="col">{{ __('Next payment') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="credit" :title="__('No subscriptions yet')">{{ __('When members pay for premium, their subscriptions appear here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($subscriptions as $subscription)
            @php $next = $subscription->ends_at ? __('Ends :date', ['date' => $subscription->ends_at->translatedFormat('j M Y')]) : ($subscription->next_payment?->translatedFormat('j M Y') ?? '-'); @endphp
            <tr>
                <td class="c-main"><p><b id="ad-sub-{{ $subscription->id }}">{{ $subscription->user?->username ?? __('Deleted member') }}</b><small>{{ $subscription->user?->email }}</small></p></td>
                <td class="c-meta"><x-admin.status :type="$tones[$subscription->stripe_status] ?? 'off'">{{ $label($subscription->stripe_status) }}</x-admin.status><small class="ad-phone-only">{{ $subscription->plan?->name ?? __('No plan') }}</small></td>
                <td class="hide-tab"><p class="ad-cell-two"><b>{{ $subscription->plan?->name ?? __('No plan') }}</b><small>{{ \Illuminate\Support\Str::headline($subscription->payment_provider ?? 'stripe') }}</small></p></td>
                <td class="hide-tab">{{ $subscription->card_last4 ? '•••• '.$subscription->card_last4 : '-' }}</td>
                <td class="is-date">{{ $next }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-sub-{{ $subscription->id }}" data-id="{{ $subscription->id }}" data-state="{{ $subscription->stripe_status }}" @if ($subscription->user) data-user="{{ $subscription->user->id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
