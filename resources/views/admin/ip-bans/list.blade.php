{{--
    Members > Blocked addresses in the redesigned admin (the old admin keeps ip-bans/index.blade.php): "Block an
    address", the blocked addresses with their reason and end, a row menu filled from one template
    (modules/list-rows.ts: unblock with Undo), clearing the ended blocks, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Blocked addresses'))

@section('content')
    @php ob_start(); @endphp
    @php
        $durations = ['' => __('For good'), '1' => __('1 hour'), '6' => __('6 hours'), '24' => __('1 day'), '168' => __('1 week'), '720' => __('30 days'), '8760' => __('1 year')];
        $ended = $stats['total'] - $stats['active'];
    @endphp

    <x-admin.page-header page="members.blocked"
        :description="trans_choice('1 address is blocked: it cannot open your site.|:count addresses are blocked: they cannot open your site.', $stats['active'], ['count' => number_format($stats['active'])])">
        <x-slot:actions>
            @if ($ended > 0)
                <form method="POST" action="{{ route('admin.ip-bans.clear-expired') }}">
                    @csrf
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('trash') }}{{ trans_choice('Clear 1 ended block|Clear :count ended blocks', $ended, ['count' => $ended]) }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <section class="ad-panel ad-console-block" aria-labelledby="ad-bans-add">
        <div class="ad-panel-h"><h2 id="ad-bans-add">{{ __('Block an address') }}</h2></div>
        <form method="POST" action="{{ route('admin.ip-bans.store') }}" class="ad-panel-b ad-record-fields is-3">
            @csrf
            <x-admin.field name="ip_address" :label="__('IP address')" placeholder="203.0.113.7" />
            <x-admin.field name="reason" :label="__('Reason (only you see it)')" />
            <x-admin.field name="duration" :label="__('For how long')">
                <select class="ad-select" id="duration" name="duration">
                    @foreach ($durations as $value => $label)
                        <option value="{{ $value }}" @selected((string) old('duration', '') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <div class="ad-record-actions"><button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('ban') }}{{ __('Block address') }}</button></div>
        </form>
    </section>

    <form id="ad-ban-delete" method="POST" action="{{ route('admin.ip-bans.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Address unblocked') }}"
          data-ad-confirm="{{ __('Unblock this address?') }}" data-ad-confirm-body="{{ __('It can open your site again. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Unblock') }}" data-ad-confirm-tone="primary" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-ban-menu">
        <button type="submit" form="ad-ban-delete" formaction="{{ route('admin.ip-bans.delete', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('check-circle') }}{{ __('Unblock') }}</button>
    </template>

    <x-admin.table :paginator="$bans" :label="__('Blocked addresses')" list="ip-bans" table-class="is-list" row-menu="ad-ban-menu">
        <x-slot:head>
            <th scope="col">{{ __('Address') }}</th>
            <th scope="col">{{ __('Until') }}</th>
            <th scope="col" class="hide-tab">{{ __('Blocked') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="shield" :title="__('No blocked addresses')">{{ __('Block an address above when it sends spam or attacks your site.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($bans as $ban)
            @php
                $until = $ban->expires_at ? \Illuminate\Support\Carbon::parse($ban->expires_at) : null;
                $over = $until !== null && $until->isPast();
            @endphp
            <tr>
                <td class="c-main"><p><b id="ad-ban-{{ $ban->id }}"><code>{{ $ban->ip_address }}</code></b>@if ($ban->reason)<small>{{ $ban->reason }}</small>@endif</p></td>
                <td class="c-meta"><x-admin.status :type="$over ? 'off' : 'danger'">{{ $until === null ? __('For good') : ($over ? __('Ended') : $until->translatedFormat('j M Y, H:i')) }}</x-admin.status></td>
                <td class="hide-tab is-date">{{ \Illuminate\Support\Carbon::parse($ban->created_at)->translatedFormat('j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-ban-{{ $ban->id }}" data-id="{{ $ban->id }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
