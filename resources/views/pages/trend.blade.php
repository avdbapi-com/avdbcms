@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
@endphp

@section('title', $seoService->renderTemplate('trend_meta_title_template', [], __('Trending'), 180))
@section('description', $seoService->renderTemplate('trend_meta_description_template', [], 'The pulse of what is trending on '.config('app.name').'. Check out the latest videos that people are watching right now.', 500))

@section('class', 'avd-feed-shell px-0 px-sm-3 mt-3')

@section('content')
    <div class="avd-home-topbar px-3 px-sm-0">
        <div>
            <div class="avd-page-kicker">
                <i class="fa-solid fa-arrow-trend-up"></i>
                <span>{{ __('Trending') }}</span>
            </div>
            <h1 class="avd-home-title">{{ __('Popular right now') }}</h1>
            <p class="avd-home-subtitle">{{ __('The videos collecting momentum across the site.') }}</p>
        </div>
    </div>

    @include('pages.partials.category-chips-scroller')

    <x-videos-area
        :url="route('videos.trend', ['locale' => app()->getLocale()])"
        ad-context="trend_page"
    />
@endsection
