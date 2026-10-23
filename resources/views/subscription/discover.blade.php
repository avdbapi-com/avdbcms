@extends('layouts.default')

@section('title', __('Discover new channels'))

@section('class', 'px-3 mt-3')

@section('content')
    <div class="avd-home-topbar">
        <div>
            <div class="avd-page-kicker">
                <i class="fa-solid fa-user-plus"></i>
                <span>{{ __('Discover') }}</span>
            </div>
            <h1 class="avd-home-title">{{ __('Discover new channels')}}</h1>
            <p class="avd-home-subtitle">{{ __('Find creators worth following and keep your sidebar feed alive.') }}</p>
        </div>
    </div>

    @if($users->count())
        <div class="row gx-3 gy-3">
            @each('subscription.partials.user', $users, 'user')
        </div>
    @else
        <div class="avd-empty-state text-center p-4 p-md-5">
            <i class="fa-solid fa-user-slash fa-3x text-muted mb-3"></i>
            <h2 class="h4 fw-black">{{ __('No channels to subscribe to') }}</h2>
            <p class="text-muted mb-0">{{ __('No channels are available for discovery yet. Please check back later.') }}</p>
        </div>
    @endif
@endsection
