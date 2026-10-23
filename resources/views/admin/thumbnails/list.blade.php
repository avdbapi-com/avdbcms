{{--
    Content > Files & thumbnails > Thumbnails, in the redesigned admin (the old admin keeps thumbnails/index.blade.php):
    the pictures made for uploaded videos, the one each video uses, a row menu filled from one template
    (modules/list-rows.ts: use this picture, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Files & thumbnails'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="content.files"
        :description="trans_choice('1 thumbnail for uploaded videos.|:count thumbnails for uploaded videos.', $stats['total'], ['count' => number_format($stats['total'])]).' '.trans_choice('1 video has its own picture.|:count videos have their own pictures.', $stats['videos'], ['count' => number_format($stats['videos'])])" />

    <form id="ad-thumbnail-use" method="POST" action="{{ route('admin.thumbnails.index', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('The video now shows this picture') }}" hidden></form>
    <form id="ad-thumbnail-delete" method="POST" action="{{ route('admin.thumbnails.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Thumbnail deleted') }}"
          data-ad-confirm="{{ __('Delete this thumbnail?') }}" data-ad-confirm-body="{{ __('The picture file is deleted too. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete thumbnail') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-thumbnail-menu">
        <button type="submit" form="ad-thumbnail-use" formaction="{{ route('admin.thumbnails.set-active', ['id' => '__ID__'], false) }}" data-ad-when="spare" data-ad-expect="used">{{ AdminTheme::icon('check') }}{{ __('Use this picture') }}</button>
        <a href="{{ route('admin.videos.edit', ['video' => '__VIDEO__'], false) }}">{{ AdminTheme::icon('film') }}{{ __('Open video') }}</a>
        <hr>
        <button type="submit" form="ad-thumbnail-delete" formaction="{{ route('admin.thumbnails.delete', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete thumbnail...') }}</button>
    </template>

    <x-admin.table :paginator="$thumbnails" :label="__('Thumbnails')" list="thumbnails" table-class="is-list" row-menu="ad-thumbnail-menu">
        <x-slot:head>
            <th scope="col">{{ __('Thumbnail') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Added') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="image" :title="__('No thumbnails yet')">{{ __('Pictures made from uploaded videos are listed here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($thumbnails as $thumbnail)
            <tr>
                <td class="c-main"><div class="ad-vid"><span class="ad-thumb"><img src="{{ url('video/'.$thumbnail->video_uuid.'/thumbnails/'.$thumbnail->id) }}" alt="" width="96" height="54" loading="lazy" decoding="async"></span><p><b id="ad-t-{{ $thumbnail->id }}">{{ \Illuminate\Support\Str::limit((string) $thumbnail->video_title, 90) }}</b><small>{{ $thumbnail->username }}</small></p></div></td>
                <td class="c-meta"><x-admin.status :type="$thumbnail->is_active ? 'ok' : 'off'">{{ $thumbnail->is_active ? __('Shown on the video') : __('Not used') }}</x-admin.status></td>
                <td class="hide-tab is-date">{{ \Illuminate\Support\Carbon::parse($thumbnail->created_at)->translatedFormat('j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-t-{{ $thumbnail->id }}" data-id="{{ $thumbnail->id }}" data-state="{{ $thumbnail->is_active ? 'used' : 'spare' }}" data-video="{{ $thumbnail->video_id }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
