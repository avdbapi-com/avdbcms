@extends('layouts.default')

@section('title', __('Subscriptions'))

{{-- The subscriptions feed /subscriptions (member design 6.11, QA channel-23): a page head with Manage, the channels as
     chips and the feed (the site's feed component: its reserved skeleton, paging and the "subscriptions" list ad as the
     admin set them). No side padding of its own: the site's gutter applies (5.2). --}}
@section('content')
    <div class="avd-mk mk-page mk-subs-feed">
        @auth
            @if(empty($subscription_count))
                <x-mk.head :title="__('Latest from your channels')" :kicker="__('Subscriptions')" icon="user-check" :desc="__('New videos from the channels you subscribe to appear here.')" />
                <section class="mk-panel">
                    <x-mk.empty icon="user-plus" :title="__('Start by subscribing to your first channel')" :text="__('Subscribe to channels you enjoy and never miss their latest videos.')">
                        <a class="mk-btn mk-btn--primary" href="{{ route('subscription.discover') }}"><i class="fa-solid fa-compass" aria-hidden="true"></i><span>{{ __('Discover channels') }}</span></a>
                    </x-mk.empty>
                </section>
            @else
                <x-mk.head :title="__('Latest from your channels')" :kicker="__('Subscriptions')" icon="user-check" :desc="trans_choice('New videos from the :count channel you follow.|New videos from the :count channels you follow.', $subscription_count, ['count' => \App\Support\LocalNumber::short($subscription_count)])">
                    <x-slot:actions>
                        <a class="mk-btn mk-btn--secondary" href="{{ route('subscription.manage') }}"><i class="fa-solid fa-sliders" aria-hidden="true"></i><span>{{ __('Manage') }}</span></a>
                    </x-slot:actions>
                </x-mk.head>
                @if($channels->isNotEmpty())
                    <nav class="mk-chips-row" aria-label="{{ __('Your channels') }}">
                        @foreach($channels as $channelUser)
                            <a class="mk-chan-chip" href="{{ $channelUser->route }}"><img class="mk-avatar mk-avatar--32" src="{{ $channelUser->avatar_url }}" alt="" width="32" height="32" loading="lazy" decoding="async"><span>{{ $channelUser->username }}</span></a>
                        @endforeach
                    </nav>
                @endif
                <div class="mk-section-head"><h2 class="mk-panel__title">{{ __('Latest videos') }}</h2></div>
                <div class="mk-subs-feed__list">
                    <x-videos-area
                        :url="route('me.subscriptions-videos', ['locale' => app()->getLocale()])"
                        ad-context="subscriptions"
                    />
                </div>
            @endif
        @else
            <x-mk.head :title="__('Subscriptions')" :kicker="__('Library')" icon="user-check" />
            <section class="mk-panel">
                <x-mk.empty icon="bell" :title="__('Don\'t miss new videos')" :text="__('Sign in to see the new videos of the channels you follow, all in one place.')">
                    <a class="mk-btn mk-btn--primary" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i><span>{{ __('Sign in') }}</span></a>
                    @if(config('site.registration_enabled', true))
                        <a class="mk-btn mk-btn--secondary" href="{{ locale_url(route('registration')) }}"><span>{{ __('Create account') }}</span></a>
                    @endif
                </x-mk.empty>
                <p class="mk-shelf__hint mk-subs-feed__discover"><a class="mk-quiet-link" href="{{ route('subscription.discover') }}">{{ __('Discover channels') }}</a></p>
            </section>
        @endauth
    </div>
@endsection
