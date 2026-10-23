{{--
    Content > Files & thumbnails > Files, in the redesigned admin (the old admin keeps media/index.blade.php): the
    public storage folder by folder (the path as links), its folders, then its files 25 per page with a preview of
    pictures, a row menu filled from one template (modules/list-rows.ts: open, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@use('App\Http\Controllers\Admin\StorageController')
@extends('layouts.admin')

@section('title', __('Files & thumbnails'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="content.files"
        :description="__('The files in public storage: :folders folders and :files files here.', ['folders' => number_format(count($folders)), 'files' => number_format($files->total())])" />

    <nav class="ad-crumbs ad-console-block" aria-label="{{ __('Folder') }}">
        @if ($currentPath === '')
            <span aria-current="page">storage/public</span>
        @else
            <a href="{{ route('admin.media.index') }}">storage/public</a>
            @foreach ($crumbs as $crumb)
                {{ AdminTheme::icon('chev-right') }}
                @if ($loop->last)
                    <span aria-current="page">{{ $crumb['name'] }}</span>
                @else
                    <a href="{{ route('admin.media.index', ['path' => $crumb['path']]) }}">{{ $crumb['name'] }}</a>
                @endif
            @endforeach
        @endif
    </nav>

    <form id="ad-file-delete" method="POST" action="{{ route('admin.media.delete', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('File deleted') }}"
          data-ad-confirm="{{ __('Delete this file?') }}" data-ad-confirm-body="{{ __('Pages that show it get a broken picture or link. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete file') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-file-menu">
        <a href="__URL__" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open file') }}</a>
        <button type="submit" form="ad-file-delete" name="path" value="__PATH__" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete file...') }}</button>
    </template>

    <x-admin.table :paginator="$files" :label="__('Files')" list="files" table-class="is-list" row-menu="ad-file-menu">
        <x-slot:head>
            <th scope="col">{{ __('Name') }}</th>
            <th scope="col">{{ __('Size') }}</th>
            <th scope="col" class="hide-tab">{{ __('Changed') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="folder" :title="__('This folder is empty')">{{ __('Uploaded pictures and files appear here.') }}</x-admin.empty>
        </x-slot:empty>

        @if ($files->currentPage() === 1)
            @foreach ($folders as $folder)
                <tr>
                    <td class="c-main"><div class="ad-vid"><span class="ad-sym" aria-hidden="true">{{ AdminTheme::icon('folder') }}</span><p><a href="{{ route('admin.media.index', ['path' => $folder]) }}">{{ basename($folder) }}</a></p></div></td>
                    <td class="c-meta">{{ __('Folder') }}</td>
                    <td class="hide-tab"></td>
                    <td class="is-actions"></td>
                </tr>
            @endforeach
        @endif
        @foreach ($files as $file)
            @php $key = 'ad-file-'.substr(md5($file['path']), 0, 10); @endphp
            <tr>
                <td class="c-main"><div class="ad-vid">@if ($file['is_image'])<span class="ad-thumb"><img src="{{ $file['url'] }}" alt="" width="96" height="54" loading="lazy" decoding="async"></span>@else<span class="ad-sym" aria-hidden="true">{{ AdminTheme::icon('file') }}</span>@endif<p><b id="{{ $key }}">{{ $file['name'] }}</b></p></div></td>
                <td class="c-meta">{{ $file['size'] === null ? '-' : StorageController::formatBytes((int) $file['size']) }}</td>
                <td class="hide-tab is-date">{{ $file['modified'] ? \Illuminate\Support\Carbon::createFromTimestamp($file['modified'])->translatedFormat('j M Y, H:i') : '-' }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act {{ $key }}" data-id="{{ $key }}" data-url="{{ $file['url'] }}" data-path="{{ $file['path'] }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
