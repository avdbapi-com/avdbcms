{{-- Playlist cards: @include('library.playlist-cards', ['playlists' => $playlists]); 'eagerFirst' / 'highFirst' as library.cards --}}
@foreach($playlists as $playlist)
@include('library.playlist-card', ['playlist' => $playlist, 'eager' => $loop->index < (int) ($eagerFirst ?? 0), 'high' => $loop->first && (int) ($eagerFirst ?? 0) > 0 && ($highFirst ?? true)])
@endforeach
