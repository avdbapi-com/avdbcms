{{--
    Members > Exports in the redesigned admin (the old admin keeps exports/index.blade.php): the member lists exported
    from Members (made in the background), their state, a row menu filled from one template (modules/list-rows.ts:
    download when ready), the pager.
--}}
@use('App\Enums\ExportStatus')
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Exports'))

@section('content')
    <x-admin.list-page>
    @php
        $states = [
            ExportStatus::PENDING->value => ['warn', __('Being made')],
            ExportStatus::COMPLETED->value => ['ok', __('Ready')],
            ExportStatus::ERROR->value => ['danger', __('Failed')],
        ];
    @endphp

    <x-admin.page-header page="members.exports" :primary="false">
        <x-slot:actions>
            <a class="ad-btn" href="{{ route('admin.users.export') }}">{{ AdminTheme::icon('download') }}{{ __('Export the member list') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-export-menu">
        <a href="{{ route('admin.exports.download', ['export' => '__ID__'], false) }}" data-ad-when="ready">{{ AdminTheme::icon('download') }}{{ __('Download') }}</a>
        <span class="ad-menu-note" data-ad-when="!ready">{{ __('Not ready to download') }}</span>
    </template>

    <x-admin.table :paginator="$exports" :label="__('Exports')" list="exports" table-class="is-list" row-menu="ad-export-menu">
        <x-slot:head>
            <th scope="col">{{ __('File') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Size') }}</th>
            <th scope="col">{{ __('Made') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="download" :title="__('No exports yet')">{{ __('Export the member list and the file appears here when it is ready.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($exports as $export)
            @php [$tone, $state] = $states[$export->status?->value] ?? ['off', '-']; @endphp
            <tr>
                <td class="c-main"><p>@if ($export->is_completed)<a id="ad-x-{{ $export->id }}" href="{{ route('admin.exports.download', $export, false) }}">{{ $export->file }}</a>@else<b id="ad-x-{{ $export->id }}">{{ $export->file }}</b>@endif</p></td>
                <td class="c-meta"><x-admin.status :type="$tone">{{ $state }}</x-admin.status></td>
                <td class="hide-tab">@size($export->size)</td>
                <td class="is-date" title="{{ $export->updated_at?->translatedFormat('j M Y, H:i') }}">{{ $export->updated_at?->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-x-{{ $export->id }}" data-id="{{ $export->id }}" data-state="{{ $export->is_completed ? 'ready' : 'waiting' }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
