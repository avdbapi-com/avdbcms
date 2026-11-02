@extends('layouts.default')

@section('title', __('Saved playlists'))

{{-- Saved playlists /playlists/manage (member design 6.11, QA channel-28): a page head even when the list is empty, and
     where a playlist is saved (the Save button on a playlist page). --}}
@section('content')
    <div class="avd-mk mk-page mk-saved">
        <x-mk.head :title="__('Saved playlists')" :kicker="__('Library')" icon="bookmark" :desc="__('Playlists of other channels you saved. Save one with the Save button on a playlist page.')" />

        <x-mk.flash refusal />

        @if($playlists->isNotEmpty())
            <div class="mk-cards">
                @foreach($playlists as $playlist)
                    @include('library.playlist-card', [
                        'playlist' => $playlist,
                        'channelName' => $playlist->user?->username,
                        // the time it was saved, never in the future: a row the database dated in its own time zone
                        // (before 1.2.3 the Save button left added_at to the database's clock) reads "now" at the most
                        'meta' => __('Saved :when', ['when' => \Illuminate\Support\Carbon::parse($playlist->pivot?->added_at ?? $playlist->updated_at)->min(now())->diffForHumans()]),
                        // the first screen's pictures load at once, the first with a high priority (R1-067)
                        'eager' => $loop->index < 4,
                        'high' => $loop->first,
                    ])
                @endforeach
            </div>
            {{ $playlists->links('vendor.pagination.mk') }}
        @else
            <section class="mk-panel">
                <x-mk.empty icon="bookmark" :title="__('No saved playlists yet')" :text="__('Open a playlist you like and press Save. It stays here, so you can come back to it.')">
                    <a class="mk-btn mk-btn--secondary" href="{{ route('subscription.discover') }}"><i class="fa-solid fa-compass" aria-hidden="true"></i><span>{{ __('Discover channels') }}</span></a>
                </x-mk.empty>
            </section>
        @endif
    </div>
@endsection
