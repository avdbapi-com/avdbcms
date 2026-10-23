{{--
    Members > Email not confirmed (a tab of All members) in the redesigned admin (the old admin keeps
    verification/index.blade.php): the members who have not confirmed their email (or all / the confirmed ones), search,
    bulk "mark as confirmed", a row menu filled from one template (modules/list-rows.ts: confirm / unconfirm with
    Undo), "Confirm all", the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Email not confirmed'))

@section('content')
    @php ob_start(); @endphp
    @php
        $search = (string) request('search', '');
        $tabs = [
            ['label' => __('All members'), 'url' => route('admin.users.index', [], false), 'active' => false, 'count' => number_format($stats['total'])],
            ['label' => __('Email not confirmed'), 'url' => route('admin.verification.index', [], false), 'active' => true, 'count' => number_format($stats['unverified'])],
        ];
        $filters = ['unverified' => __('Not confirmed'), 'verified' => __('Confirmed'), 'all' => __('All members')];
    @endphp

    <x-admin.page-header page="members.all" :title="__('Email not confirmed')"
        :description="__('Members who have not clicked the link of their sign-up email yet. Their videos are not shown on your site until they do.')"
        :tabs="$tabs">
        <x-slot:actions>
            @if ($stats['unverified'] > 0)
                <form method="POST" action="{{ route('admin.verification.verify-all') }}"
                      data-ad-confirm="{{ trans_choice('Mark 1 email as confirmed?|Mark :count emails as confirmed?', $stats['unverified'], ['count' => number_format($stats['unverified'])]) }}"
                      data-ad-confirm-body="{{ __('Use this when you trust these members: they can then sign in and their videos show.') }}" data-ad-confirm-label="{{ __('Confirm all') }}" data-ad-confirm-tone="primary">
                    @csrf
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('check-circle') }}{{ __('Confirm all') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-verify-bulk" method="POST" action="{{ route('admin.bulk.users') }}" hidden>@csrf</form>
    <form id="ad-verify-change" method="POST" action="{{ route('admin.verification.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-verify-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__ID__'], false) }}">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <hr>
        <button type="submit" form="ad-verify-change" formaction="{{ route('admin.verification.verify', ['id' => '__ID__'], false) }}" data-ad-when="unverified" data-ad-expect="verified" data-ad-done="{{ __('Email marked as confirmed') }}" data-ad-undo-url="{{ route('admin.verification.unverify', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('check-circle') }}{{ __('Mark email as confirmed') }}</button>
        <button type="submit" form="ad-verify-change" formaction="{{ route('admin.verification.unverify', ['id' => '__ID__'], false) }}" data-ad-when="verified" data-ad-expect="unverified" data-ad-done="{{ __('Email marked as not confirmed') }}" data-ad-undo-url="{{ route('admin.verification.verify', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('x') }}{{ __('Mark email as not confirmed') }}</button>
    </template>

    <x-admin.table :paginator="$users" :label="__('Members')" list="verification" table-class="is-list" row-menu="ad-verify-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.verification.index', [], false) }}" class="ad-filter" role="search" aria-label="{{ __('Find members') }}">
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ $search }}" placeholder="{{ __('Name or email') }}" aria-label="{{ __('Search members') }}">
                </span>
                <select class="ad-select" name="filter" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                    @foreach ($filters as $value => $label)
                        <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-verify-bulk" name="action" value="verify">{{ __('Mark emails as confirmed') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all members on this page') }}"></th>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('Email') }}</th>
            <th scope="col" class="hide-tab">{{ __('Joined') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="check-circle" :title="$filter === 'unverified' && $search === '' ? __('Every member confirmed their email') : __('No members match')">{{ __('Members who sign up get an email with a link to confirm it.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($users as $user)
            @php($confirmed = $user->email_verified_at !== null)
            <tr>
                <td class="is-check">@if ($user->id !== auth()->id())<input type="checkbox" name="ids[]" value="{{ $user->id }}" form="ad-verify-bulk" aria-labelledby="ad-l-sel ad-vu-{{ $user->id }}">@endif</td>
                <td class="c-main"><p><a id="ad-vu-{{ $user->id }}" href="{{ route('admin.users.edit', $user, false) }}">{{ $user->username }}</a><small>{{ $user->email }}</small></p></td>
                <td class="c-meta"><x-admin.status :type="$confirmed ? 'ok' : 'warn'">{{ $confirmed ? __('Confirmed') : __('Not confirmed') }}</x-admin.status></td>
                <td class="hide-tab is-date">{{ $user->created_at?->translatedFormat('j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-vu-{{ $user->id }}" data-id="{{ $user->id }}" data-state="{{ $confirmed ? 'verified' : 'unverified' }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
