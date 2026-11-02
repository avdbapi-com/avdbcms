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
    @php
        // watch-34: a category's own picture makes a photo head; without one (the shared default doodle every category
        // showed) the head is a compact band in the look's colours, so the first videos start higher.
        $categoryHasPicture = filled($category->background);
        // perf-7: the category's own icon gets its rule in the layout's head when the main CSS's subset lacks it
        \App\Support\IconSubset::remember($category->icon_name);
    @endphp
    <section @class(['avd-category-hero', 'avd-category-hero--plain' => ! $categoryHasPicture])>
        @if($categoryHasPicture)
            <img src="{{$category->background_url}}" alt="" fetchpriority="high" decoding="async">
            <div class="avd-category-hero-overlay"></div>
        @endif
        <div class="avd-category-hero-content">
            <div @class(['avd-page-kicker', 'text-white' => $categoryHasPicture])>
                <i class="fa-solid fa-{{$category->icon_name}}" aria-hidden="true"></i>
                <span>{{ __('Category') }}</span>
            </div>
            <h1>{{$translatedCategoryTitle}}</h1>
            @if(config('site.category_video_count_pill_enabled', true))
                {{-- watch-9: the count chip has its own colours on the photo and on the band (white on a light pill was
                     unreadable in light looks). --}}
                <span class="avd-status-chip avd-category-count">
                    <i class="fa-solid fa-video" aria-hidden="true"></i>
                    {{ trans_choice('videos', (int) $category->videos_count, ['count' => \App\Support\LocalNumber::format((int) $category->videos_count)]) }}
                </span>
            @endif
        </div>
    </section>

    <div class="container-fluid px-0 px-sm-3 mt-4">
        @include('pages.partials.category-chips-scroller')

        @if($category->videos_count)
            {{-- R1-151: the page gutter of the phone mockups (16px, as the home feed; the cards touched the screen edges) --}}
            <div class="px-3 px-sm-0">
                <x-videos-area
                    :url="route('categories.videos', ['category' => $category, 'locale' => app()->getLocale()])"
                    ad-context="category_page"
                    :skeleton-items="(int) $category->videos_count"
                />
            </div>
        @else
            <div class="avd-empty-state text-center p-4 p-md-5">
                <i class="fa-solid fa-video-slash fa-3x text-muted mb-3"></i>
                <h2 class="h4 fw-black">{{__('No videos found')}}</h2>
                <p class="text-muted mb-0">{{__('Sorry, no videos are available yet. Please check back later for updates.')}}</p>
            </div>
        @endif
    </div>
@endsection
