{{--
    Money > Subscribers > Premium records, in the redesigned admin (the old admin keeps premiums/index.blade.php): every
    premium record with its end and state, a row menu filled from one template (modules/list-rows.ts: add 7, 30, 90
    or 365 days - the "extend" action with that number of days -, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Subscribers'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="money.subscribers"
        :description="trans_choice('1 premium record.|:count premium records.', $stats['total'], ['count' => number_format($stats['total'])]).' '.__('Active: :active. Ended: :ended.', ['active' => number_format($stats['active']), 'ended' => number_format($stats['expired'])])" />

    <form id="ad-premium-extend" method="POST" action="{{ route('admin.premiums.index', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('Premium extended') }}" hidden></form>
    <form id="ad-premium-delete" method="POST" action="{{ route('admin.premiums.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Premium record deleted') }}"
          data-ad-confirm="{{ __('Delete this premium record?') }}" data-ad-confirm-body="{{ __('The member loses premium if this was their record. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete record') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-premium-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <hr>
        @foreach ([7, 30, 90, 365] as $days)
            <button type="submit" form="ad-premium-extend" formaction="{{ route('admin.premiums.extend', ['id' => '__ID__'], false) }}" name="days" value="{{ $days }}">{{ AdminTheme::icon('plus') }}{{ trans_choice('Add 1 day|Add :count days', $days, ['count' => $days]) }}</button>
        @endforeach
        <hr>
        <button type="submit" form="ad-premium-delete" formaction="{{ route('admin.premiums.destroy', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete record...') }}</button>
    </template>

    <x-admin.table :paginator="$premiums" :label="__('Premium records')" list="premiums" table-class="is-list" row-menu="ad-premium-menu">
        <x-slot:head>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Plan') }}</th>
            <th scope="col">{{ __('Ends') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="star" :title="__('No premium records yet')">{{ __('A record is made when a member gets premium.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($premiums as $premium)
            @php
                $ends = $premium->ends_at ? \Illuminate\Support\Carbon::parse($premium->ends_at) : null;
                [$tone, $state] = match (true) {
                    in_array($premium->stripe_status, ['active', 'trialing'], true) && ($ends === null || $ends->isFuture()) => ['ok', __('Active')],
                    $premium->stripe_status === 'unpaid' => ['warn', __('Unpaid')],
                    default => ['off', $premium->stripe_status ? __(\Illuminate\Support\Str::headline($premium->stripe_status)) : __('Ended')],
                };
            @endphp
            <tr>
                <td class="c-main"><p><b id="ad-pr-{{ $premium->id }}">{{ $premium->username ?? __('Deleted member') }}</b><small>{{ $premium->user_email }}</small></p></td>
                <td class="c-meta"><x-admin.status :type="$tone">{{ $state }}</x-admin.status><small class="ad-phone-only">{{ $premium->plan_name ?? __('No plan') }}</small></td>
                <td class="hide-tab"><p class="ad-cell-two"><b>{{ $premium->plan_name ?? __('No plan') }}</b><small>{{ \Illuminate\Support\Str::headline($premium->payment_provider ?? 'stripe') }}</small></p></td>
                <td class="is-date">{{ $ends?->translatedFormat('j M Y') ?? __('Never') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-pr-{{ $premium->id }}" data-id="{{ $premium->id }}" @if ($premium->username !== null) data-user="{{ $premium->user_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
