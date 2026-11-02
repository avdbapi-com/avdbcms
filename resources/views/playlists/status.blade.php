{{-- A playlist's visibility as the kit's dot + label (member design 5.8; QA channel-26: green, cyan and red badges in
     every look). @include('playlists.status', ['playlist' => $playlist]) --}}
@php
    [$playlistStatusKind, $playlistStatusLabel] = match ($playlist->status) {
        \App\Enums\PlaylistStatus::PUBLIC => ['public', __('Public')],
        \App\Enums\PlaylistStatus::UNLISTED => ['unlisted', __('Unlisted')],
        default => ['private', __('Private')],
    };
@endphp
<x-mk.status :kind="$playlistStatusKind" :label="$playlistStatusLabel" />
