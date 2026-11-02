@extends('layouts.default')

@section('title', __('Library'))

{{-- The member's library /library (member design 6.11, OD-M5; data from App\Http\Controllers\LibraryController): one
     shelf each for History, Watch later, Liked videos, the member's playlists and the channels they follow. A shelf is
     exactly one full row of cards at every width (its columns follow its own width; the cards past the last column are
     not shown); on phones it is a swipe row of all six. --}}
@section('content')
    {{-- the page root without whitespace between tags, and links to this site as site-relative addresses like the cards'
         (the content budget, 4.3; R1-069); the head's folder icon and the channel chips' avatar size are drawn by
         member/_channel.scss, like the shelf icons (an element and a class pair less) --}}
    {{-- the first two cards of the first two shelves that have cards load at once (a phone shows about one and a half
         cards of each of them, a wide screen loads the rest of the row right after), the first card of each of them with
         a high priority, the second one too from 576px (a preload: the cards are all one size, so the largest picture can
         be any of them) (R1-067: every thumbnail was lazy, so the largest picture came seconds after the page on a weak
         phone) --}}
    @php ob_start(); $libraryShelves = 2; @endphp
    <div class="avd-mk mk-page mk-library">
        <x-mk.head :title="__('Library')" :kicker="__('Library')" :desc="__('Everything you saved, watched and liked. Only you see this page.')" />

        <x-mk.flash refusal />

        @if($isEmpty)
            <section class="mk-panel">
                <x-mk.empty icon="folder-open" :title="__('Your library is empty')" :text="__('Videos you watch, save for later or like, your playlists and the channels you follow all appear here.')">
                    <a class="mk-btn mk-btn--primary" href="{{ route('pages.home') }}"><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Find something to watch') }}</span></a>
                    <a class="mk-btn mk-btn--secondary" href="{{ route('subscription.discover') }}"><i class="fa-solid fa-compass" aria-hidden="true"></i><span>{{ __('Discover channels') }}</span></a>
                </x-mk.empty>
            </section>
        @else
            @if($showHistory)
                <section class="mk-shelf" aria-labelledby="mkShelfHistory">
                    <div class="mk-section-head">
                        <h2 class="mk-panel__title" id="mkShelfHistory">{{ __('History') }} <span class="mk-count">{{ \App\Support\LocalNumber::short($history['count']) }}</span></h2>
                        <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('pages.history')) }}" aria-label="{{ __('See all: :name', ['name' => __('History')]) }}">{{ __('See all') }}</a>
                    </div>
                    @if($history['videos']->isNotEmpty())
@php $shelfEager = $libraryShelves-- > 0; @endphp
                        <div class="mk-cards mk-cards--shelf">
                            @foreach($history['videos'] as $video)
                                @include('library.card', ['video' => $video, 'showChannel' => true, 'eager' => $shelfEager && $loop->index < 2, 'high' => $shelfEager && $loop->first])
@if($shelfEager && $loop->index === 1)@push('preload')<link rel="preload" as="image" href="{{ \App\Support\PageUrl::local($video->thumbnail_url) }}" fetchpriority="high" media="(min-width: 576px)">@endpush @endif
                            @endforeach
                        </div>
                    @else
                        <p class="mk-shelf__hint">{{ __('Videos you watch while signed in appear here.') }}</p>
                    @endif
                </section>
            @endif

            <section class="mk-shelf" aria-labelledby="mkShelfLater">
                <div class="mk-section-head">
                    <h2 class="mk-panel__title" id="mkShelfLater">{{ __('Watch later') }} <span class="mk-count">{{ \App\Support\LocalNumber::short($watchLater['count']) }}</span></h2>
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('custom-playlist.show', \App\Enums\CustomPlaylistType::WATCH_LATER)) }}" aria-label="{{ __('See all: :name', ['name' => __('Watch later')]) }}">{{ __('See all') }}</a>
                </div>
                @if($watchLater['videos']->isNotEmpty())
@php $shelfEager = $libraryShelves-- > 0; @endphp
                    <div class="mk-cards mk-cards--shelf">
                        @foreach($watchLater['videos'] as $video)
                            @include('library.card', ['video' => $video, 'showChannel' => true, 'eager' => $shelfEager && $loop->index < 2, 'high' => $shelfEager && $loop->first])
@if($shelfEager && $loop->index === 1)@push('preload')<link rel="preload" as="image" href="{{ \App\Support\PageUrl::local($video->thumbnail_url) }}" fetchpriority="high" media="(min-width: 576px)">@endpush @endif
                        @endforeach
                    </div>
                @else
                    <p class="mk-shelf__hint">{{ __('Save a video with its clock button to watch it later.') }}</p>
                @endif
            </section>

            <section class="mk-shelf" aria-labelledby="mkShelfLiked">
                <div class="mk-section-head">
                    <h2 class="mk-panel__title" id="mkShelfLiked">{{ __('Liked videos') }} <span class="mk-count">{{ \App\Support\LocalNumber::short($liked['count']) }}</span></h2>
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('custom-playlist.show', \App\Enums\CustomPlaylistType::LIKED_VIDEOS)) }}" aria-label="{{ __('See all: :name', ['name' => __('Liked videos')]) }}">{{ __('See all') }}</a>
                </div>
                @if($liked['videos']->isNotEmpty())
@php $shelfEager = $libraryShelves-- > 0; @endphp
                    <div class="mk-cards mk-cards--shelf">
                        @foreach($liked['videos'] as $video)
                            @include('library.card', ['video' => $video, 'showChannel' => true, 'eager' => $shelfEager && $loop->index < 2, 'high' => $shelfEager && $loop->first])
@if($shelfEager && $loop->index === 1)@push('preload')<link rel="preload" as="image" href="{{ \App\Support\PageUrl::local($video->thumbnail_url) }}" fetchpriority="high" media="(min-width: 576px)">@endpush @endif
                        @endforeach
                    </div>
                @else
                    <p class="mk-shelf__hint">{{ __('Videos you like appear here.') }}</p>
                @endif
            </section>

            <section class="mk-shelf" aria-labelledby="mkShelfPlaylists">
                <div class="mk-section-head">
                    <h2 class="mk-panel__title" id="mkShelfPlaylists">{{ __('Playlists') }} <span class="mk-count">{{ \App\Support\LocalNumber::short($playlists['count']) }}</span></h2>
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('user.playlists.index')) }}" aria-label="{{ __('See all: :name', ['name' => __('Playlists')]) }}">{{ __('See all') }}</a>
                </div>
                @if($playlists['items']->isNotEmpty())
@php $shelfEager = $libraryShelves-- > 0; @endphp
                    <div class="mk-cards mk-cards--shelf">
                        @foreach($playlists['items'] as $playlist)
                            @include('library.playlist-card', ['playlist' => $playlist, 'eager' => $shelfEager && $loop->index < 2, 'high' => $shelfEager && $loop->first])
                        @endforeach
                    </div>
                @else
                    <p class="mk-shelf__hint">{{ __('Playlists you make in the studio appear here.') }}</p>
                @endif
            </section>

            <section class="mk-shelf" aria-labelledby="mkShelfSaved">
                <div class="mk-section-head">
                    <h2 class="mk-panel__title" id="mkShelfSaved">{{ __('Saved playlists') }} <span class="mk-count">{{ \App\Support\LocalNumber::short($saved['count']) }}</span></h2>
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('playlist.manage')) }}" aria-label="{{ __('See all: :name', ['name' => __('Saved playlists')]) }}">{{ __('See all') }}</a>
                </div>
                @if($saved['items']->isNotEmpty())
                    <div class="mk-cards mk-cards--shelf">
                        @foreach($saved['items'] as $playlist)
                            {{-- another channel's playlist: its channel and when it was saved, as on the Saved playlists page (R2A-031) --}}
                            @include('library.playlist-card', ['playlist' => $playlist, 'channelName' => $playlist->user?->username, 'meta' => __('Saved :when', ['when' => \Illuminate\Support\Carbon::parse($playlist->pivot?->added_at ?? $playlist->updated_at)->min(now())->diffForHumans()])])
                        @endforeach
                    </div>
                @else
                    <p class="mk-shelf__hint">{{ __('Open a playlist you like and press Save. It stays here, so you can come back to it.') }}</p>
                @endif
            </section>

            <section class="mk-shelf" aria-labelledby="mkShelfSubs">
                <div class="mk-section-head">
                    <h2 class="mk-panel__title" id="mkShelfSubs">{{ __('Subscriptions') }} <span class="mk-count">{{ \App\Support\LocalNumber::short($subscriptions['count']) }}</span></h2>
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('subscription.manage')) }}" aria-label="{{ __('Manage subscriptions') }}">{{ __('Manage') }}</a>
                </div>
                @if($subscriptions['channels']->isNotEmpty())
                    <div class="mk-chips-row">
                        @foreach($subscriptions['channels'] as $channelUser)
                            <a class="mk-chan-chip" href="{{ \App\Support\PageUrl::local($channelUser->route) }}"><img src="{{ \App\Support\PageUrl::local($channelUser->avatar_url) }}" alt="" loading="lazy" decoding="async">{{ $channelUser->username }}</a>
                        @endforeach
                    </div>
                @else
                    <p class="mk-shelf__hint">{{ __('Channels you subscribe to appear here.') }} <a class="mk-quiet-link" href="{{ route('subscription.discover') }}">{{ __('Discover channels') }}</a></p>
                @endif
            </section>
        @endif
    </div>
    {{-- no space between tags at all: every one sits between cards, shelves or the parts of a head, all block, flex or
         grid boxes, where a space draws nothing (R1-069) --}}
    {!! preg_replace('/>\s+</', '><', (string) ob_get_clean()) !!}
@endsection
