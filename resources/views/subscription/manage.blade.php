@extends('layouts.default')

@section('title', __('Your subscriptions'))

{{-- Manage subscriptions /subscriptions/manage (member design 6.11; QA channel-24, channel-25): compact rows (avatar 48,
     name, @handle, subscribers or "Count hidden", videos, one line of description), search, sort and pages, and the
     Subscribed button of each channel: its bell menu sets what the channel notifies (the channel bell; the head
     says so when the site has it), and it asks before it unsubscribes. --}}
@section('content')
    <div class="avd-mk mk-page mk-page--narrow mk-subs-manage avd-subscriptions-page">
        <x-mk.head :title="__('Your subscriptions')" :kicker="__('Library')" icon="user-check"
                   :desc="$total === 0 ? __('Channels you subscribe to appear here.') : (\Illuminate\Support\Facades\Route::has('notifications.channel')
                       ? trans_choice(':count channel. The bell on its button sets what it notifies you about.|:count channels. The bell on each button sets what they notify you about.', $total, ['count' => \App\Support\LocalNumber::short($total)])
                       : trans_choice(':count channel. Unsubscribe from one with its Subscribed button.|:count channels. Unsubscribe from one with its Subscribed button.', $total, ['count' => \App\Support\LocalNumber::short($total)]))">
            <x-slot:actions>
                <a class="mk-btn mk-btn--secondary" href="{{ route('subscription.discover') }}"><i class="fa-solid fa-compass" aria-hidden="true"></i><span>{{ __('Discover channels') }}</span></a>
            </x-slot:actions>
        </x-mk.head>

        <x-mk.flash refusal />

        @if($total > 0)
            <section class="mk-panel" aria-label="{{ __('Your subscriptions') }}">
                <form class="mk-toolbar" role="search" method="GET" action="{{ route('subscription.manage') }}">
                    <label class="mk-search">
                        <span class="mk-sr">{{ __('Search your subscriptions') }}</span>
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input class="mk-input" type="search" name="q" value="{{ $search }}" maxlength="{{ \App\Http\Controllers\SubscriptionController::MAX_SEARCH }}" placeholder="{{ __('Search channels') }}" autocomplete="off">
                    </label>
                    <label class="mk-sr" for="mkSubsSort">{{ __('Sort') }}</label>
                    {{-- data-avd-apply: the list is sorted on an explicit choice only, never on the first arrow key (the
                         footer's language select, R2A-003; the listeners are in components/layout.blade.php) --}}
                    <select class="form-select mk-subs-manage__sort" id="mkSubsSort" name="sort" data-native-select data-avd-apply>
                        <option value="recent" @selected($sort === 'recent')>{{ __('Recently subscribed') }}</option>
                        <option value="oldest" @selected($sort === 'oldest')>{{ __('First subscribed') }}</option>
                        <option value="name" @selected($sort === 'name')>{{ __('Name') }}</option>
                    </select>
                    <noscript><button type="submit" class="mk-btn mk-btn--secondary mk-btn--sm">{{ __('Search') }}</button></noscript>
                </form>
                @if($subscriptions->isNotEmpty())
                    <ul class="mk-rows mk-subs-manage__rows">
                        @foreach($subscriptions as $channelUser)
                            @php
                                $channelCountShown = $channelUser->show_subscribers && config('site.show_subscriber_count', true);
                                $channelSubs = (int) ($channelUser->subscribers_count ?? 0);
                                $channelVideos = (int) ($channelUser->videos_count ?? 0);
                            @endphp
                            <li class="mk-item">
                                <img class="mk-avatar mk-avatar--48" src="{{ $channelUser->avatar_url }}" alt="" width="48" height="48" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                                <div class="mk-item__body">
                                    <a class="mk-item__title" href="{{ $channelUser->route }}"{!! \App\Support\TextDirection::attr($channelUser->username) !!}>{{ $channelUser->username }}</a>
                                    <div class="mk-row__meta">
                                        <span><bdi dir="ltr">{{ '@' . ($channelUser->slug ?: $channelUser->username) }}</bdi></span>
                                        <span>{{ $channelCountShown ? trans_choice('subscribers', $channelSubs, ['count' => \App\Support\LocalNumber::short($channelSubs)]) : __('Count hidden') }}</span>
                                        <span>{{ trans_choice('videos', $channelVideos, ['count' => \App\Support\LocalNumber::short($channelVideos)]) }}</span>
                                    </div>
                                    @if($channelUser->description)
                                        <span class="mk-muted mk-clamp-1 mk-subs-manage__desc"{!! \App\Support\TextDirection::attr($channelUser->description) !!}>{{ $channelUser->short_description ?? \Illuminate\Support\Str::limit($channelUser->description, 140) }}</span>
                                    @endif
                                </div>
                                <div class="mk-item__aside">
                                    @include('library.subscribe', ['channel' => $channelUser, 'subscribed' => true, 'size' => 'sm'])
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    {{ $subscriptions->links('vendor.pagination.mk') }}
                @else
                    <x-mk.empty compact icon="magnifying-glass" :title="__('No channel matches your search')" :text="__('Try another name.')">
                        <a class="mk-btn mk-btn--secondary mk-btn--sm" href="{{ route('subscription.manage') }}">{{ __('Clear search') }}</a>
                    </x-mk.empty>
                @endif
            </section>
        @else
            <section class="mk-panel">
                <x-mk.empty icon="user-plus" :title="__('You do not follow any channel yet')" :text="__('Subscribe to channels you enjoy: their new videos appear in your subscriptions feed.')" />
            </section>
        @endif
    </div>
@endsection
