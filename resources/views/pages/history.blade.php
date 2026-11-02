@extends('layouts.default')

@section('title', __('History'))

@php
    /** History (member design 6.11; QA channel-3, 7, 12, 30, 31, 34, 35): the page prints its first rows itself, so it
        has its final size from the first paint (1.2.2 grew from an empty element to 16 skeleton cards to the list), and
        pages the rest with "Show older" (the API answers the next rows in this page's markup). */
    $historyUser = auth()->user();
    $historySearch = \App\Support\WatchHistory::search(request()->query('q'));
    $historyPage = \App\Support\WatchHistory::page($historyUser, null, \App\Support\WatchHistory::FIRST_PAGE, $historySearch);
    $historyDays = \App\Support\WatchHistory::days($historyPage->items());
    $historyNext = $historyPage->nextCursor()?->encode();
    $historyTotal = \App\Support\WatchHistory::count($historyUser);
    $historyHasRows = $historyDays->isNotEmpty();
    // "Pause history" (6.11): offered once the site has the switch (App\Support\WatchHistory::pauseAvailable)
    $historyCanPause = \App\Support\WatchHistory::pauseAvailable($historyUser);
    $historyPaused = $historyCanPause && \App\Support\WatchHistory::isPaused($historyUser);
@endphp

@section('content')
    {{-- the page root without the line breaks between its tags (template formatting: a space written inside a line, as
         between a row's facts, stays) and links to this site as site-relative addresses: the content budget (4.3;
         R1-069: 16.4 KB in Arabic). Round 3 (R1-069): its tags on one line each, the attributes it does not need left
         out (an empty search, no older rows), and no copy of the layout's "That did not work..." sentence (the page
         script reads the layout's, #avd-i18n) --}}
    @php
        ob_start();
        $historyRootData = array_filter([
            'next' => $historyNext,
            'q' => $historySearch,
            'pause-url' => $historyCanPause ? \App\Support\PageUrl::local(route(\App\Support\WatchHistory::PAUSE_ROUTE)) : null,
            'paused' => $historyCanPause ? ($historyPaused ? '1' : '0') : null,
        ], fn ($value) => $value !== null && $value !== '');
    @endphp
    <div class="avd-mk mk-page mk-page--narrow mk-history" x-data="mkHistory" data-api="{{ \App\Support\PageUrl::local(url('/api/me/history')) }}"@foreach($historyRootData as $historyKey => $historyValue) data-{{ $historyKey }}="{{ $historyValue }}"@endforeach data-message-removed="{{ __('Removed from your history') }}" data-message-cleared="{{ __('Your history is cleared') }}">
        <x-mk.head :title="__('History')" :kicker="__('Library')" icon="clock-rotate-left" :desc="__('Videos you watched, newest first. Only you see this.')">
            @if($historyTotal > 0)
                <x-slot:actions>
                    <button type="button" class="mk-btn mk-btn--secondary" data-history-clear-all-open><i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>{{ __('Clear all history') }}</span></button>
                </x-slot:actions>
            @endif
        </x-mk.head>

        <x-mk.flash refusal />

        @if($historyCanPause)
            {{-- the paused state: one line under the head while it lasts (the toolbar's button turns it back on) --}}
            <x-mk.alert type="info" data-history-paused-note="" :hidden="$historyPaused ? false : ''">{{ __('Your history is paused. Videos you watch now are not added to it.') }}</x-mk.alert>
        @endif

        @if($historyTotal > 0 || $historySearch !== '' || $historyCanPause)
            <div class="mk-toolbar mk-toolbar--bare">
                @if($historyTotal > 0 || $historySearch !== '')
                    <form class="mk-history__search" role="search" method="GET" action="{{ \App\Support\PageUrl::local(route('pages.history')) }}">
                        <label class="mk-search">
                            <span class="mk-sr">{{ __('Search your history') }}</span>
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input class="mk-input" type="search" name="q" value="{{ $historySearch }}" maxlength="{{ \App\Support\WatchHistory::MAX_SEARCH }}" placeholder="{{ __('Search your history') }}" autocomplete="off">
                        </label>
                    </form>
                    @if($historySearch !== '')
                        <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ \App\Support\PageUrl::local(route('pages.history')) }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>{{ __('Clear search') }}</span></a>
                    @endif
                @endif
                @if($historyCanPause)
                    {{-- the label it shows and, for the script, the other one (R1-069: each label once in the page) --}}
                    <button type="button" class="mk-btn mk-btn--quiet mk-btn--sm" data-history-pause aria-pressed="{{ $historyPaused ? 'true' : 'false' }}" data-label-other="{{ $historyPaused ? __('Pause history') : __('Turn history back on') }}"><i class="fa-solid fa-{{ $historyPaused ? 'play' : 'pause' }}" aria-hidden="true"></i><span>{{ $historyPaused ? __('Turn history back on') : __('Pause history') }}</span></button>
                @endif
            </div>
        @endif

        <div class="mk-history__list" data-history-list>
            @include('library.history-days', ['days' => $historyDays, 'searching' => $historySearch !== '', 'eagerRows' => 4])
        </div>
        {{-- every row's menu, printed once (R1-069: its one item and its words were printed in every row); the page script
             puts a copy into a row's menu the first time its button opens it, before Bootstrap's dropdown looks for it. A
             menu of menu items, as the notifications page's row menu: Bootstrap's arrow keys reach its item (.dropdown-item;
             they did nothing in the menu printed in every row) --}}
        <template data-history-row-menu><div class="dropdown-menu dropdown-menu-end mk-menu" role="menu"><button type="button" class="dropdown-item mk-menu__item" role="menuitem" data-history-remove><i class="fa-solid fa-xmark" aria-hidden="true"></i>{{ __('Remove from history') }}</button></div></template>

        <div class="mk-history__empty" data-history-empty{{ $historyHasRows ? ' hidden' : '' }}>
            @if($historySearch !== '')
                <x-mk.empty icon="magnifying-glass" :title="__('No videos match your search')" :text="__('Try other words, or clear the search to see your whole history.')">
                    <a class="mk-btn mk-btn--secondary" href="{{ \App\Support\PageUrl::local(route('pages.history')) }}">{{ __('Clear search') }}</a>
                </x-mk.empty>
            @else
                <x-mk.empty icon="clock-rotate-left" :title="__('Your history is empty')" :text="__('Videos you watch while signed in appear here.')">
                    <a class="mk-btn mk-btn--primary" href="{{ \App\Support\PageUrl::local(route('pages.home')) }}"><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Find something to watch') }}</span></a>
                </x-mk.empty>
            @endif
        </div>

        <div class="mk-loadmore" data-history-more-wrap{{ $historyNext ? '' : ' hidden' }}>
            <button type="button" class="mk-btn mk-btn--secondary" data-history-more><span>{{ __('Show older') }}</span></button>
        </div>

        {{-- One confirmation for both questions ("Clear all history?" as printed; "Remove today's history?" when a day's
             Remove asks, with the words a day's removal takes, data-remove-day-*, and the question of an older day made
             from data-remove-day-question with the day's name): one dialog's markup on the page instead of two (the
             page-content budget, 4.3). "Pause history instead" is the gentle choice of "Clear all history?"
             (sheets-gallery), shown while history is on. --}}
        <x-library.confirm id="mkHistoryConfirm" :title="__('Clear all history?')" :confirm="__('Clear history')" icon="trash-can" confirm-attr="data-history-confirm" :data-remove-day-text="__('The videos you watched that day leave your history. Your likes and playlists stay.')" :data-remove-day-confirm="__('Remove')" :data-remove-day-question="__('Remove the history of :day?')">
            <p data-history-confirm-text>{{ \App\Support\WatchHistory::clearAllText($historyTotal) }}</p>
            @if($historyCanPause)
                <x-slot:gentle>
                    <button type="button" class="mk-btn mk-btn--quiet" data-history-pause-instead{{ $historyPaused ? ' hidden' : '' }}><i class="fa-solid fa-pause" aria-hidden="true"></i><span>{{ __('Pause history instead') }}</span></button>
                </x-slot:gentle>
            @endif
        </x-library.confirm>
    </div>
    {!! preg_replace('/>\s*\n\s*</', '><', (string) ob_get_clean()) !!}
    {{-- the page's script: resources/js/member/library-pages.js, one cached file (R1-069) --}}
    @include('library.pages-script')
@endsection
