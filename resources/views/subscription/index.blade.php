@extends('layouts.default')

@section('title', __('Subscriptions'))

@section('class', 'px-0 px-sm-3 mt-0 mt-sm-3')

@section('content')
    @auth
        @if(empty($subscription_count))
            <div class="avd-empty-state d-flex flex-column align-items-center justify-content-center text-center p-4 p-md-5 my-4">
                <i class="fa-solid fa-user-plus fa-3x text-muted mb-3"></i>
                <h2 class="h4 fw-black mb-2">{{ __('Start by subscribing to your first channel') }}</h2>
                <p class="text-muted mb-3">{{ __('Subscribe to channels you enjoy and never miss their latest videos.') }}</p>
                <a href="{{ route('subscription.discover') }}" class="btn btn-success rounded-5 d-inline-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>{{ __('Discover channels') }}</span>
                </a>
            </div>
        @else
            <div class="d-flex justify-content-between align-items-center mb-3 p-3">
                <h3 class="mb-0">{{ __('Latest') }}</h3>
                <a href="{{route('subscription.manage')}}" class="btn btn-primary">
                    <i class="fa-solid fa-users"></i>
                    {{ __('Manage') }}
                </a>
            </div>
            <x-videos-area
                :url="route('me.subscriptions-videos', ['locale' => app()->getLocale()])"
                ad-context="subscriptions"
            />
        @endif
    @else
        <div class="avd-empty-state d-flex flex-column align-items-center justify-content-center text-center p-4 p-md-5 my-4">
            <i class="fa-solid fa-bell fa-3x text-muted mb-3"></i>
            <h1 class="h4 fw-black mb-2">{{ __("Don't miss new videos") }}</h1>
            <p class="text-muted mb-3">{{ __('Sign In to see updates from your favorite channels') }}</p>
            <a href="{{route('login')}}" class="btn btn-outline-primary rounded-5 d-inline-flex align-items-center gap-2">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>{{ __('Sign In') }}</span>
            </a>
        </div>
    @endif
@endsection
