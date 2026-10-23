{{--
    Money > Payments & invoices > Invoices, in the redesigned admin (the old admin keeps invoices/index.blade.php): the
    invoice of every payment, searched by member, a row menu filled from one template (modules/list-rows.ts: download
    the invoice), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Payments & invoices'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="money.payments"
        :description="trans_choice('1 invoice.|:count invoices.', $stats['count'], ['count' => number_format($stats['count'])]).' '.__('Total :amount, of which tax :tax and fees :fee.', ['amount' => '$'.number_format($stats['amount'], 2), 'tax' => '$'.number_format($stats['tax'], 2), 'fee' => '$'.number_format($stats['fee'], 2)])" />

    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-invoice-menu">
        <a href="__URL__" target="_blank" rel="noopener">{{ AdminTheme::icon('download') }}{{ __('Download invoice') }}</a>
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
    </template>

    <x-admin.table :paginator="$invoices" :label="__('Invoices')" list="invoices" table-class="is-list" row-menu="ad-invoice-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.invoices.index') }}" class="ad-filter" role="search" aria-label="{{ __('Find invoices') }}">
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="user" value="{{ request('user') }}" placeholder="{{ __('Member name or email') }}" aria-label="{{ __('Search invoices by member') }}">
                </span>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if (request()->filled('user'))
                    <a class="ad-btn is-ghost" href="{{ route('admin.invoices.index') }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col" class="is-num">{{ __('Amount') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Tax') }}</th>
            <th scope="col" class="hide-tab">{{ __('Country') }}</th>
            <th scope="col">{{ __('Date') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="file" :title="request()->filled('user') ? __('No invoices for this member') : __('No invoices yet')">{{ __('Every payment gets an invoice you can download here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($invoices as $invoice)
            <tr>
                <td class="c-main"><p><b id="ad-inv-{{ $invoice->id }}">{{ $invoice->user?->username ?? __('Deleted member') }}</b><small>{{ $invoice->user?->email }}</small></p></td>
                <td class="c-meta is-num"><b>{{ $invoice->amount }}</b></td>
                <td class="is-num hide-tab">{{ $invoice->tax }}</td>
                <td class="hide-tab">{{ strtoupper($invoice->country ?? '-') }}</td>
                <td class="is-date" title="{{ $invoice->date?->translatedFormat('j M Y, H:i') }}">{{ $invoice->date?->translatedFormat($invoice->date->isCurrentYear() ? 'j M' : 'j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-inv-{{ $invoice->id }}" data-id="{{ $invoice->id }}" data-url="{{ $invoice->route }}" @if ($invoice->user) data-user="{{ $invoice->user->id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
