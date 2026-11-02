{{-- A playlist card (1.2.3: the kit's card, resources/views/library/playlist-card.blade.php). Kept under its old name for
     any view that still includes playlists.card. --}}
@include('library.playlist-card', ['playlist' => $playlist])
