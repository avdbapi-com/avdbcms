@extends('layouts.default')

@section('title', $title)

@php
    $customTotal = (int) $videos->total();
    $customIsWatchLater = $type === \App\Enums\CustomPlaylistType::WATCH_LATER;
    $customFirst = $videos->getCollection()->first(fn ($video) => $video->is_public || $video->is_unlisted || ($video->user && $video->user->is(Auth::user())));
@endphp

{{-- Watch later and Liked videos (member design 6.11; QA channel-6, channel-27): a compact head with the count (and
     the running time), Play all and, in Watch later, "Remove watched" (asked first, then undone with one click); the
     rows are part of the page. When a row leaves Watch later the head, Play all and "Remove watched" follow at once, and
     the page shows its empty state after the last one (library/actions-script). --}}
@section('content')
    {{-- the page root with the whitespace between tags collapsed to one space (it renders the same), its first rows only
         (CustomPlaylistController::FIRST_PAGE) and links to this site as site-relative addresses: the content budget
         (4.3; R1-069) --}}
    @php ob_start(); @endphp
    <div class="avd-mk mk-page mk-page--narrow mk-playlist"
         data-rows-root
         data-rows-url="{{ \App\Support\PageUrl::local(locale_url(route('custom-playlist.show', $type))) }}"
         data-next-page="{{ $videos->hasMorePages() ? $videos->currentPage() + 1 : '' }}"
         data-message-error="{{ __('That did not work. Check your connection and try again.') }}">
        <x-mk.head :title="$title" :kicker="__('Library')" :icon="$customIsWatchLater ? 'clock' : 'thumbs-up'" :desc="$summary" data-rows-head>
            @if($customFirst || ($customIsWatchLater && $watchedCount > 0))
                <x-slot:actions>
                    @if($customFirst)
                        <a class="mk-btn mk-btn--primary" href="{{ \App\Support\PageUrl::local($customFirst->routeWithParams(['list' => $playlist->uuid])) }}" data-play-all><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Play all') }}</span></a>
                    @endif
                    @if($customIsWatchLater && $watchedCount > 0)
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-toggle="modal" data-bs-target="#mkRemoveWatched" data-remove-watched-open><i class="fa-solid fa-broom" aria-hidden="true"></i><span>{{ __('Remove watched') }}</span></button>
                    @endif
                </x-slot:actions>
            @endif
        </x-mk.head>

        @if($undo && session('success'))
            <div class="mk-alert mk-alert--success" role="status">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div class="mk-alert__body"><span>{{ session('success') }}</span></div>
                <form method="POST" action="{{ \App\Support\PageUrl::local(route('custom-playlist.clear-watched-videos')) }}">
                    @csrf
                    <input type="hidden" name="undo" value="{{ $undo }}">
                    <button type="submit" class="mk-btn mk-btn--quiet mk-btn--sm"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i><span>{{ __('Undo') }}</span></button>
                </form>
            </div>
        @else
            {{-- "refusal": a view-only demo account's Remove watched (a form) comes back here with the guard's reason --}}
            <x-mk.flash refusal />
        @endif

        @php
            ob_start();
        @endphp
        <section class="mk-panel">
            @if($customIsWatchLater)
                <x-mk.empty icon="clock" :title="__('Nothing saved for later')" :text="__('Save a video with the clock button on its card or under the player, and it waits here for you.')">
                    <a class="mk-btn mk-btn--primary" href="{{ \App\Support\PageUrl::local(route('pages.home')) }}"><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Find something to watch') }}</span></a>
                </x-mk.empty>
            @else
                <x-mk.empty icon="thumbs-up" :title="__('No liked videos yet')" :text="__('Tap the thumbs up under a video you enjoy, and it appears here.')">
                    <a class="mk-btn mk-btn--primary" href="{{ \App\Support\PageUrl::local(route('pages.home')) }}"><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Find something to watch') }}</span></a>
                </x-mk.empty>
            @endif
        </section>
        @php
            $customEmpty = (string) ob_get_clean();
        @endphp

        @if($customTotal > 0)
            <ol class="mk-lrows mk-lrows--numbered" data-label-on="{{ __('Remove from Watch later') }}" data-label-off="{{ __('Save to Watch later') }}" data-rows>
                @include('library.playlist-rows', ['videos' => $videos->getCollection(), 'playlist' => $playlist, 'context' => $type->value, 'adAfter' => \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6)), 'listRows' => $customTotal, 'watchLaterIds' => $watchLaterIds, 'eagerRows' => 4])
            </ol>
            <div class="mk-loadmore" data-rows-more-wrap @if(!$videos->hasMorePages()) hidden @endif>
                <button type="button" class="mk-btn mk-btn--secondary" data-rows-more><span>{{ __('Show more') }}</span></button>
            </div>
            @if($customIsWatchLater)
                {{-- shown when the last row leaves the list --}}
                <template data-rows-empty>{!! $customEmpty !!}</template>
            @endif
        @else
            {!! $customEmpty !!}
        @endif

        @if($customIsWatchLater && $watchedCount > 0)
            <x-library.confirm id="mkRemoveWatched" :title="__('Remove watched videos?')" :confirm="$watched['confirm']" icon="broom" :form="\App\Support\PageUrl::local(route('custom-playlist.clear-watched-videos'))">
                <p data-remove-watched-text>{{ $watched['text'] }}</p>
            </x-library.confirm>
        @endif

        @include('library.actions-script')
    </div>
    {!! preg_replace('/>\s+</', '> <', (string) ob_get_clean()) !!}
@endsection
