@extends('layouts.default')

@php
    $categoryTitle = decode_html_entities($category->title);
    $translatedCategoryTitle = __($categoryTitle);
    $seoService = app(\App\Services\SeoService::class);
    $categorySeoValues = [
        'category' => $translatedCategoryTitle,
        'title' => $translatedCategoryTitle,
        'videos_count' => (string) (int) $category->videos_count,
    ];
@endphp

@section('title', $seoService->renderTemplate('category_meta_title_template', $categorySeoValues, $translatedCategoryTitle . ' ' . __('Videos'), 180))
@section('description', $seoService->renderTemplate('category_meta_description_template', $categorySeoValues, __('Watch :category videos on :site.', ['category' => $translatedCategoryTitle, 'site' => config('app.name')]), 500))
@section('image', $category->background_url)

@section('class', 'px-0 mt-0')

@section('content')
    <section class="avd-category-hero">
        <img src="{{$category->background_url}}" alt="{{$translatedCategoryTitle}} {{ __('background') }}">
        <div class="avd-category-hero-overlay"></div>
        <div class="avd-category-hero-content">
            <div class="avd-page-kicker text-white">
                <i class="fa-solid fa-{{$category->icon_name}}"></i>
                <span>{{ __('Category') }}</span>
            </div>
            <h1>{{$translatedCategoryTitle}}</h1>
            @if(config('site.category_video_count_pill_enabled', true))
                <span class="avd-status-chip text-white">
                    <i class="fa-solid fa-video"></i>
                    {{ trans_choice('videos', (int) $category->videos_count, ['count' => number_format((int) $category->videos_count)]) }}
                </span>
            @endif
        </div>
    </section>

    <div class="container-fluid px-0 px-sm-3 mt-4">
        @include('pages.partials.category-chips-scroller')

        @if($category->videos_count)
            <x-videos-area
                :url="route('categories.videos', ['category' => $category, 'locale' => app()->getLocale()])"
                ad-context="category_page"
                :skeleton-items="(int) $category->videos_count"
            />
        @else
            <div class="avd-empty-state text-center p-4 p-md-5">
                <i class="fa-solid fa-video-slash fa-3x text-muted mb-3"></i>
                <h2 class="h4 fw-black">{{__('No videos found')}}</h2>
                <p class="text-muted mb-0">{{__('Sorry, no videos are available yet. Please check back later for updates.')}}</p>
            </div>
        @endif
    </div>
@endsection
