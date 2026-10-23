{{--
    Members > Logins & devices > Password resets, in the redesigned admin (the old admin keeps
    password-resets/index.blade.php): the "forgot password" requests still waiting, with the member they belong to,
    a row menu filled from one template (modules/list-rows.ts: delete one request with Undo), deleting them all, the
    pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Logins & devices'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="members.logins"
        :description="trans_choice('1 password reset is waiting.|:count password resets are waiting.', $stats['total'], ['count' => number_format($stats['total'])]).($stats['recent'] > 0 ? ' '.trans_choice('1 was asked for in the last hour.|:count were asked for in the last hour.', $stats['recent'], ['count' => number_format($stats['recent'])]) : '')">
        <x-slot:actions>
            @if ($stats['total'] > 0)
                <form method="POST" action="{{ route('admin.password-resets.clear') }}"
                      data-ad-confirm="{{ __('Delete every waiting password reset?') }}" data-ad-confirm-body="{{ __('The links in those emails stop working. Members can ask for a new one.') }}" data-ad-confirm-label="{{ __('Delete all') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete all...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-reset-delete" method="POST" action="{{ route('admin.password-resets.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Password reset deleted') }}"
          data-ad-confirm="{{ __('Delete this password reset?') }}" data-ad-confirm-body="{{ __('The link in that email stops working. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-reset-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <button type="submit" form="ad-reset-delete" formaction="__URL__" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
    </template>

    <x-admin.table :paginator="$resets" :label="__('Password resets')" list="password-resets" table-class="is-list" row-menu="ad-reset-menu">
        <x-slot:head>
            <th scope="col">{{ __('Email') }}</th>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('Asked') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="key" :title="__('No password resets waiting')">{{ __('When a member asks for a new password, the request waits here until they use the link.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($resets as $reset)
            @php
                $asked = \Illuminate\Support\Carbon::parse($reset->created_at);
                $key = 'ad-r-'.substr(md5($reset->email), 0, 10);
            @endphp
            <tr>
                <td class="c-main"><p><b id="{{ $key }}">{{ $reset->email }}</b></p></td>
                <td class="c-meta">@if ($reset->user_id){{ $reset->username }} <x-admin.status :type="$reset->email_verified_at ? 'ok' : 'warn'">{{ $reset->email_verified_at ? __('Email confirmed') : __('Email not confirmed') }}</x-admin.status>@else<x-admin.status type="off">{{ __('No member with this email') }}</x-admin.status>@endif</td>
                <td class="is-date" title="{{ $asked->translatedFormat('j M Y, H:i') }}">{{ $asked->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act {{ $key }}" data-id="{{ $key }}" data-url="{{ route('admin.password-resets.delete', $reset->email, false) }}" @if ($reset->user_id) data-user="{{ $reset->user_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
