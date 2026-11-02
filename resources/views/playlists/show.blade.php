@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
    $playlistDescription = trim(strip_tags((string) $playlist->description));
    $playlistTotal = (int) $videos->total();
    $playlistSeoValues = [
        'title' => $playlist->title,
        'description' => $playlistDescription,
        'username' => $playlist->user?->username,
        'user' => $playlist->user?->username,
        'videos_count' => (string) $playlistTotal,
    ];
    $playlistFirst = $videos->getCollection()->first(fn ($video) => $video->is_public || ($video->user && $video->user->is(Auth::user())));
    $playlistIsOwner = $playlist->user && $playlist->user->is(Auth::user());
    $playlistAdEvery = \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6));
@endphp

@section('title', $seoService->renderTemplate('playlist_meta_title_template', $playlistSeoValues, $playlist->title, 180))
@section('description', $seoService->renderTemplate('playlist_meta_description_template', $playlistSeoValues, \Illuminate\Support\Str::limit($playlistDescription, 155, ''), 500))
@section('image', $playlistFirst?->thumbnail_url ?? '')

{{-- A playlist page (member design 6.11, QA channel-26): cover, h1 title, "by <channel> · 12 videos · Public · Updated
     20 Oct 2026", Play all (the one primary), Save, Share and the owner's Edit; the videos in order as rows of the page
     (no inner scroll box: channel-6), a page at a time. --}}
@section('content')
    <div class="avd-mk mk-page mk-page--narrow mk-playlist"
         data-rows-root
         data-rows-url="{{ \App\Support\PageUrl::local(locale_url($playlist->route)) }}"
         data-next-page="{{ $videos->hasMorePages() ? $videos->currentPage() + 1 : '' }}"
         data-message-error="{{ __('That did not work. Check your connection and try again.') }}">
        <header class="mk-head mk-head--playlist">
            @if($playlistFirst)
                <a class="mk-head__cover" href="{{ $playlistFirst->routeWithParams(['list' => $playlist->uuid]) }}" tabindex="-1" aria-hidden="true">
                    <span class="mk-thumb"><img src="{{ $playlistFirst->thumbnail_url }}" alt="" width="320" height="180" loading="eager" fetchpriority="high" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/placeholders/no-image.svg') }}';"><span class="mk-thumb__count"><i class="fa-solid fa-list" aria-hidden="true"></i> {{ trans_choice('videos', $playlistTotal, ['count' => \App\Support\LocalNumber::short($playlistTotal)]) }}</span></span>
                </a>
            @else
                <span class="mk-head__cover"><span class="mk-thumb mk-thumb--icon"><i class="fa-solid fa-list" aria-hidden="true"></i></span></span>
            @endif
            <div class="mk-head__text">
                <span class="mk-head__kicker"><i class="fa-solid fa-rectangle-list" aria-hidden="true"></i>{{ __('Playlist') }}</span>
                <h1 class="mk-head__title"{!! \App\Support\TextDirection::attr($playlist->title) !!}>{{ $playlist->title }}</h1>
                <p class="mk-head__desc mk-playlist__facts">
                    @if($playlist->user)
                        <span>{{ __('by') }} <a class="mk-quiet-link" href="{{ $playlist->user->route }}">{{ $playlist->user->username }}</a></span>
                    @else
                        <span>{{ __('Deleted user') }}</span>
                    @endif
                    <span>{{ trans_choice('videos', $playlistTotal, ['count' => \App\Support\LocalNumber::short($playlistTotal)]) }}</span>
                    <span>@include('playlists.status', ['playlist' => $playlist])</span>
                    <span>{{ __('Updated :date', ['date' => \App\Support\SiteDate::date($playlist->updated_at)]) }}</span>
                </p>
                @if($playlistDescription !== '')
                    <p class="mk-playlist__desc"{!! \App\Support\TextDirection::attr($playlist->description) !!}>{{ $playlist->description }}</p>
                @endif
            </div>
            <div class="mk-head__actions">
                @if($playlistFirst)
                    <a class="mk-btn mk-btn--primary" href="{{ $playlistFirst->routeWithParams(['list' => $playlist->uuid]) }}"><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Play all') }}</span></a>
                @endif
                @auth
                    @can('favorite', $playlist)
                        @unless($playlistIsOwner)
                            {{-- Save / Saved (library/actions-script: a refusal shows the server's own words, 6.18) --}}
                            <button type="button" class="mk-btn mk-btn--secondary mk-btn--fixed" aria-pressed="{{ $favorite_by_auth_user ? 'true' : 'false' }}"
                                    data-favorite-url="{{ route('user.playlists.favorite', $playlist) }}" data-label-on="{{ __('Saved') }}" data-label-off="{{ __('Save') }}">
                                <i class="{{ $favorite_by_auth_user ? 'fa-solid' : 'fa-regular' }} fa-bookmark" aria-hidden="true"></i>
                                <span>{{ $favorite_by_auth_user ? __('Saved') : __('Save') }}</span>
                            </button>
                        @endunless
                    @endcan
                @else
                    <a class="mk-btn mk-btn--secondary" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}" title="{{ __('Sign in to save this playlist.') }}"><i class="fa-regular fa-bookmark" aria-hidden="true"></i><span>{{ __('Save') }}</span></a>
                @endauth
                @if($playlist->is_active)
                    <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--outlined" data-share-dialog="#mkSharePlaylist" aria-label="{{ __('Share playlist') }}" title="{{ __('Share playlist') }}"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
                @endif
                @if($playlistIsOwner)
                    <a class="mk-btn mk-btn--quiet" href="{{ route('user.playlists.edit', $playlist) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i><span>{{ __('Edit playlist') }}</span></a>
                @endif
            </div>
        </header>

        @if($playlistTotal > 0)
            <ol class="mk-lrows mk-lrows--numbered" data-label-on="{{ __('Remove from Watch later') }}" data-label-off="{{ __('Save to Watch later') }}" data-rows>
                {{-- the first rows (PlaylistController::FIRST_PAGE, R1-069); the list ad after row N of the whole list --}}
                @include('library.playlist-rows', ['videos' => $videos->getCollection(), 'playlist' => $playlist, 'context' => 'playlist', 'adAfter' => $playlistAdEvery, 'listRows' => $playlistTotal, 'watchLaterIds' => $watchLaterIds ?? [], 'eagerRows' => 4, 'highFirst' => false])
            </ol>
            <div class="mk-loadmore" data-rows-more-wrap @if(!$videos->hasMorePages()) hidden @endif>
                <button type="button" class="mk-btn mk-btn--secondary" data-rows-more><span>{{ __('Show more') }}</span></button>
            </div>
        @else
            <section class="mk-panel">
                <x-mk.empty icon="list" :title="__('This playlist is empty')" :text="__('Videos added to this playlist appear here.')">
                    @if($playlistIsOwner)
                        <a class="mk-btn mk-btn--secondary" href="{{ route('user.playlists.edit', $playlist) }}"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>{{ __('Add videos') }}</span></a>
                    @endif
                </x-mk.empty>
            </section>
        @endif

        @if($playlist->is_active)
            <x-library.share id="mkSharePlaylist" :url="$playlist->route" :title="$playlist->title" :heading="__('Share playlist')" :label="__('Playlist address')" />
        @else
            @include('library.actions-script')
        @endif
    </div>
@endsection
