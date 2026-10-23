{{--
    Members > Logins & devices > Devices, in the redesigned admin (the old admin keeps devices/index.blade.php): the
    apps and devices signed in with a member's account (API keys), all or only the ended ones, bulk "Sign out", a row
    menu filled from one template (modules/list-rows.ts: sign out one device with Undo, or all of a member's devices),
    removing the ended ones, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Logins & devices'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="members.logins"
        :description="trans_choice('1 app or device is signed in with a member account.|:count apps and devices are signed in with member accounts.', $stats['total'], ['count' => number_format($stats['total'])])">
        <x-slot:actions>
            @if ($stats['expired'] > 0)
                <form method="POST" action="{{ route('admin.devices.clear-expired') }}"
                      data-ad-confirm="{{ __('Remove the ended sign-ins?') }}" data-ad-confirm-body="{{ __('They no longer work. Nobody is signed out by this.') }}" data-ad-confirm-label="{{ __('Remove') }}" data-ad-confirm-tone="primary">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('trash') }}{{ trans_choice('Remove 1 ended sign-in|Remove :count ended sign-ins', $stats['expired'], ['count' => number_format($stats['expired'])]) }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-devices-bulk" method="POST" action="{{ route('admin.bulk.devices') }}" hidden>@csrf</form>
    <form id="ad-device-end" method="POST" action="{{ route('admin.devices.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Signed out') }}"
          data-ad-confirm="{{ __('Sign out this device?') }}" data-ad-confirm-body="{{ __('The app has to sign in again. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Sign out') }}" hidden>@method('DELETE')</form>
    <form id="ad-device-end-all" method="POST" action="{{ route('admin.devices.index', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __("All of this member's devices are signed out") }}"
          data-ad-confirm="{{ __("Sign out all of this member's devices?") }}" data-ad-confirm-body="{{ __('Every app they use has to sign in again. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Sign out all') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-device-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <hr>
        <button type="submit" form="ad-device-end" formaction="{{ route('admin.devices.delete', ['device' => '__ID__'], false) }}">{{ AdminTheme::icon('logout') }}{{ __('Sign out this device...') }}</button>
        <button type="submit" form="ad-device-end-all" formaction="{{ route('admin.devices.revoke-user', ['userId' => '__USER__'], false) }}" data-ad-if="user" class="is-danger">{{ AdminTheme::icon('logout') }}{{ __('Sign out all their devices...') }}</button>
    </template>

    <x-admin.table :paginator="$devices" :label="__('Signed-in apps and devices')" list="devices" table-class="is-list" row-menu="ad-device-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.devices.index') }}" class="ad-filter" aria-label="{{ __('Filter devices') }}">
                <select class="ad-select" name="state" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                    <option value="">{{ __('All devices') }}</option>
                    <option value="expired" @selected($expired)>{{ __('Ended sign-ins') }}</option>
                </select>
                <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn is-danger sm" form="ad-devices-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Sign out the selected devices?') }}" data-ad-confirm-body="{{ __('Their apps have to sign in again. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Sign out') }}">{{ AdminTheme::icon('logout') }}{{ __('Sign out...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all devices on this page') }}"></th>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('App or device') }}</th>
            <th scope="col">{{ __('Last used') }}</th>
            <th scope="col" class="hide-tab">{{ __('Ends') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="phone" :title="$expired ? __('No ended sign-ins') : __('No signed-in apps or devices')">{{ __('When members sign in with an app, the app appears here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($devices as $device)
            @php $ended = $device->expires_at !== null && $device->expires_at->isPast(); @endphp
            <tr>
                <td class="is-check"><input type="checkbox" name="ids[]" value="{{ $device->id }}" form="ad-devices-bulk" aria-labelledby="ad-l-sel ad-d-{{ $device->id }}"></td>
                <td class="c-main"><p><b id="ad-d-{{ $device->id }}">{{ $device->user?->username ?? __('Deleted member') }}</b><small>{{ $device->user?->email }}</small></p></td>
                <td class="c-meta">{{ $device->name }} <x-admin.status :type="$ended ? 'off' : 'ok'">{{ $ended ? __('Ended') : __('Signed in') }}</x-admin.status></td>
                <td class="is-date">{{ $device->last_used_at?->diffForHumans(short: true) ?? __('Never') }}</td>
                <td class="hide-tab is-date">{{ $device->expires_at?->translatedFormat('j M Y') ?? __('Never') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-d-{{ $device->id }}" data-id="{{ $device->id }}" @if ($device->user) data-user="{{ $device->user->id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
