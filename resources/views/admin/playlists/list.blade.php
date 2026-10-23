{{--
    Content > Playlists in the redesigned admin (the old admin keeps playlists/index.blade.php): members' playlists
    with their owner and size, search and a "visible to" filter, bulk delete, a row menu filled from one template
    (modules/list-rows.ts), the pager.
--}}
@use('App\Enums\PlaylistStatus')
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Playlists'))

@section('content')
    @php
        $filtered = request()->filled('search') || request()->filled('status');
        $visibility = [
            (string) PlaylistStatus::PUBLIC->value => __('Everyone'),
            (string) PlaylistStatus::UNLISTED->value => __('People with the link'),
            (string) PlaylistStatus::PRIVATE->value => __('Only its owner'),
        ];
    @endphp

    <x-admin.page-header page="content.playlists"
        :description="$filtered ? null : trans_choice('1 playlist. Members make them; delete the ones that break your rules.|:count playlists. Members make them; delete the ones that break your rules.', $playlists->total(), ['count' => number_format($playlists->total())])" />

    <form id="ad-playlists-bulk" method="POST" action="{{ route('admin.bulk.playlists') }}" hidden>@csrf</form>
    <form id="ad-playlist-delete" method="POST" action="{{ route('admin.bulk.playlists') }}" data-ad-row-action="delete" data-ad-done="{{ __('Playlist deleted') }}"
          data-ad-confirm="{{ __('Delete this playlist?') }}" data-ad-confirm-body="{{ __('The videos in it stay on your site. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete playlist') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-select">{{ __('Select') }}</span><span hidden id="ad-l-actions">{{ __('Actions for') }}</span>
    <template id="ad-playlist-menu">
        <a href="__URL__" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
        <a href="{{ route('admin.users.edit', ['user' => '__OWNER__'], false) }}" data-ad-if="owner">{{ AdminTheme::icon('user') }}{{ __('Open the owner') }}</a>
        <hr>
        <button type="submit" form="ad-playlist-delete" formaction="{{ route('admin.playlists.delete', ['playlist' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete playlist...') }}</button>
    </template>

    <x-admin.table :paginator="$playlists" :label="__('Playlists')" list="playlists" table-class="is-list">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.playlists.index') }}" class="ad-filter" role="search" aria-label="{{ __('Find playlists') }}">
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Search titles and descriptions') }}" aria-label="{{ __('Search playlists') }}">
                </span>
                <select class="ad-select" name="status" aria-label="{{ __('Visible to') }}" data-ad-autosubmit>
                    <option value="">{{ __('Anyone') }}</option>
                    @foreach ($visibility as $value => $label)
                        <option value="{{ $value }}" @selected((string) request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if ($filtered)
                    <a class="ad-btn is-ghost" href="{{ route('admin.playlists.index') }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn is-danger sm" form="ad-playlists-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected playlists?') }}" data-ad-confirm-body="{{ __('The videos in them stay on your site. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete playlists') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all playlists on this page') }}"></th>
            <th scope="col">{{ __('Playlist') }}</th>
            <th scope="col">{{ __('Visible to') }}</th>
            <th scope="col" class="is-num">{{ __('Videos') }}</th>
            <th scope="col">{{ __('Created') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            @if ($filtered)
                <x-admin.empty icon="search" :title="__('No playlists match')">{{ __('Try another word, or clear the filters.') }}</x-admin.empty>
            @else
                <x-admin.empty icon="list" :title="__('No playlists yet')">{{ __('Playlists your members make appear here.') }}</x-admin.empty>
            @endif
        </x-slot:empty>

        @php ob_start(); @endphp
        @foreach ($playlists as $playlist)
            @php
                [$tone, $label] = match ($playlist->status) {
                    PlaylistStatus::PUBLIC => ['ok', __('Everyone')],
                    PlaylistStatus::UNLISTED => ['off', __('People with the link')],
                    default => ['off', __('Only its owner')],
                };
            @endphp
            <tr>
                <td class="is-check"><input type="checkbox" class="ad-check" name="ids[]" value="{{ $playlist->id }}" form="ad-playlists-bulk" data-ad-select aria-labelledby="ad-l-select ad-p-{{ $playlist->id }}"></td>
                <td class="c-main">
                    <span class="ad-vid-text">
                        <a class="ad-vid-title" id="ad-p-{{ $playlist->id }}" href="{{ $playlist->route }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit((string) $playlist->title, 80) }}</a>
                        <span class="ad-vid-sub">{{ $playlist->user?->username ?? __('Deleted member') }}</span>
                    </span>
                </td>
                <td class="c-meta">
                    <x-admin.status :type="$tone">{{ $label }}</x-admin.status>
                    <span class="ad-phone-only ad-faint">{{ trans_choice('1 video|:count videos', (int) $playlist->videos_count, ['count' => number_format((int) $playlist->videos_count)]) }}</span>
                </td>
                <td class="is-num">{{ number_format((int) $playlist->videos_count) }}</td>
                <td class="ad-muted is-date"><time datetime="{{ $playlist->created_at?->toIso8601String() }}">{{ $playlist->created_at?->translatedFormat('j M Y') }}</time></td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" data-ad-row-menu="ad-playlist-menu" aria-haspopup="true" aria-labelledby="ad-l-actions ad-p-{{ $playlist->id }}" data-id="{{ $playlist->id }}" data-url="{{ $playlist->route }}"@if ($playlist->user_id) data-owner="{{ $playlist->user_id }}"@endif></button></td>
            </tr>
        @endforeach
        {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
    </x-admin.table>
@endsection
