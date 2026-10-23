@extends('layouts.default')

@section('title', __('My Favorite Playlists'))

@section('content')
    @if($playlists->count())
        <h3 class="mb-4">{{ __('My Favorite Playlists')}}</h3>
    @endif
    <div class="container">
        @forelse($playlists as $playlist)
            @include('search.playlist', ['playlist' => $playlist])
        @empty
            <div class="avd-empty-state d-flex flex-column align-items-center justify-content-center text-center p-4 p-md-5">
                <i class="fa-solid fa-list-check fa-3x text-muted mb-3"></i>
                <h2 class="h4 fw-black mb-2">{{ __("You haven't added any playlists to your favorites yet") }}</h2>
                <p class="text-muted mb-3">{{ __('Save playlists you want to revisit and they will appear here.') }}</p>
                <a href="{{ route('pages.home') }}" class="btn btn-outline-primary rounded-5 d-inline-flex align-items-center gap-2">
                    <i class="fa-solid fa-compass"></i>
                    <span>{{ __('Browse videos') }}</span>
                </a>
            </div>
        @endforelse
    </div>
@endsection
