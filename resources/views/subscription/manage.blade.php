@extends('layouts.default')

@section('title', __('My Subscriptions'))

@section('content')
<div class="avd-secondary-page avd-subscriptions-page">
    <div class="avd-secondary-hero">
        <div class="d-flex flex-column flex-xl-row gap-3 align-items-start align-items-xl-center justify-content-between">
            <div>
                <div class="avd-secondary-kicker"><i class="fa-solid fa-user-plus"></i>{{ __('Subscriptions') }}</div>
                <h1 class="avd-secondary-title">{{ __('My Subscriptions')}}</h1>
                <p class="avd-secondary-subtitle">{{ __('Keep track of channels you follow and manage each subscription from one place.') }}</p>
            </div>
            <div class="avd-secondary-actions">
                <a href="{{route('subscription.discover')}}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                    <i class="fa-solid fa-compass"></i>
                    <span>{{ __('Discover new channels') }}</span>
                </a>
            </div>
        </div>
    </div>
    <div class="container px-0">
        @forelse($subscriptions as $user)
            <article class="mb-3 card avd-subscription-card">
                <div class="position-relative avd-subscription-card-row d-flex flex-column flex-sm-row align-items-center px-6 py-4 gap-3 gap-sm-5 justify-content-between">
                    <a href="{{$user->route}}" class="avd-subscription-card-link text-decoration-none d-flex flex-column flex-sm-row align-items-center gap-3 gap-sm-5 flex-grow-1 min-w-0 w-100">
                        <img class="rounded-circle avd-subscription-avatar avd-avatar-media flex-shrink-0" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar" width="108" height="108" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                        <div class="text-center text-sm-start min-w-0">
                            <h6 class="mb-1 text-break">{{$user->username}}</h6>
                            <small class="text-muted d-block mb-1">{{trans_choice('subscribers', $user->subscribers_count)}} &middot; {{trans_choice('videos', $user->videos_count)}}</small>
                            <small class="text-muted d-block text-break">{{$user->short_description}}</small>
                        </div>
                    </a>
                    <div class="avd-subscription-card-action flex-shrink-0">
                        <subscribe-button
                            user="{{$user->id}}"
                            size="sm"
                            class="avd-subscribe-host"
                        ></subscribe-button>
                    </div>
                </div>
            </article>
        @empty
            <div class="avd-empty-state d-flex flex-column align-items-center justify-content-center text-center p-4 p-md-5">
                <i class="fa-solid fa-user-plus fa-3x text-muted mb-3"></i>
                <h2 class="h4 fw-black mb-2">{{ __("Don't miss new videos") }}</h2>
                <p class="text-muted mb-3">{{ __('Start by subscribing to your first channel') }}</p>
                <a href="{{route('subscription.discover')}}" class="btn btn-outline-primary rounded-5 d-inline-flex align-items-center gap-2">
                    <i class="fa-solid fa-compass"></i>
                    <span>{{ __('Discover new channels') }}</span>
                </a>
            </div>
        @endforelse
    </div>
</div>
@endsection
