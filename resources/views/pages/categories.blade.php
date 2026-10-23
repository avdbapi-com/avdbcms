@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
    $totalCategories = $categories->count();
    $totalVideos = $categories->sum('videos_count');
    $pageTitle = __('All Categories') . ' - ' . config('app.name');
    $pageDescription = __('Explore categories') . ' - ' . config('app.name');
@endphp

@section('title', $pageTitle)
@section('description', $pageDescription)
@section('class', 'px-0 mt-0')

@section('content')
    {{-- Page head in the theme's ink on the page (the trend page's head): readable in both modes, no photo island. --}}
    <div class="avd-home-topbar px-3 px-md-4 pt-3 mb-4">
        <div>
            <div class="avd-page-kicker">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <span>{{ __('Directory') }}</span>
            </div>
            <h1 class="avd-home-title">{{ __('All Categories') }}</h1>
            <p class="avd-home-subtitle">{{ __('Explore categories') }}</p>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="avd-meta-chip">
                    <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                    {{ $totalCategories }} {{ __('Categories') }}
                </span>
                <span class="avd-meta-chip">
                    <i class="fa-solid fa-video" aria-hidden="true"></i>
                    {{ number_format($totalVideos) }} {{ __('Total Videos') }}
                </span>
            </div>
        </div>
    </div>

    @php
        // Card style (html[data-avd-category-card-style]): photo_overlay and gradient_mesh are photo cards with white
        // labels on the theme's bottom shade (gradient_mesh shows its mesh when a category has no photo); glass_card and
        // minimal_flat are plain surfaces in the theme's ink, without a photo.
        $categoryCardStyle = (string) request()->query('category_card_style', config('site.category_card_style', 'gradient_mesh'));
        $categoryCardStyle = array_key_exists($categoryCardStyle, \App\Support\AppearanceSettings::categoryCardStyles()) ? $categoryCardStyle : 'gradient_mesh';
        $categoryPhotoCards = in_array($categoryCardStyle, ['photo_overlay', 'gradient_mesh'], true);
    @endphp
    <div class="container-fluid px-3 px-md-4 mb-5">
        <div class="row row-cols-2 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3 g-md-4">
            @forelse($categories as $category)
                @php
                    $categoryTitle = decode_html_entities($category->title);
                    $translatedTitle = __($categoryTitle);
                    $bgUrl = $category->background_url ?: ($categoryCardStyle === 'photo_overlay' ? asset('images/default_category.jpg') : null);
                    $categoryVideoCount = (int) $category->videos_count;
                    $categoryPhoto = $categoryPhotoCards && $bgUrl;
                @endphp
                <div class="col">
                    {{-- The photo sits under the card's shade (::before) in the card's own stack; the labels inherit the card's ink;
                         the card style draws (or drops) the border. --}}
                    <a href="{{ locale_url(route('pages.category', $category->slug)) }}"
                       class="card h-100 text-decoration-none overflow-hidden avd-category-card position-relative z-0">
                        @if($categoryPhoto)
                            <div class="position-absolute top-0 start-0 w-100 h-100 overflow-hidden z-n1 bg-black">
                                <img src="{{ $bgUrl }}"
                                     alt=""
                                     class="w-100 h-100 object-fit-cover opacity-75 avd-cat-thumb"
                                     loading="lazy"
                                     decoding="async">
                            </div>
                        @endif

                        <div class="card-body text-reset position-relative z-1 d-flex flex-column justify-content-between p-3 h-100">
                            <div class="d-flex justify-content-between align-items-start">
                                <span @class(['avd-cat-icon-badge rounded-circle d-flex align-items-center justify-content-center p-2', 'bg-black bg-opacity-50' => $categoryPhoto, 'border' => ! $categoryPhoto]) aria-hidden="true">
                                    <i class="fa-solid fa-fw fa-{{ $category->icon_name ?: 'film' }}"></i>
                                </span>
                                @if(config('site.category_video_count_pill_enabled', true))
                                    <span @class(['badge rounded-pill px-2 py-1', 'bg-black bg-opacity-75 text-white' => $categoryPhoto, 'border text-reset' => ! $categoryPhoto])>
                                        {{ number_format($categoryVideoCount) }}
                                    </span>
                                @endif
                            </div>

                            <div class="mt-4">
                                <h3 class="h6 fw-bold mb-1 text-truncate text-reset" title="{{ $translatedTitle }}">
                                    {{ $translatedTitle }}
                                </h3>
                                @if(config('site.category_video_count_pill_enabled', true))
                                    <small class="d-flex align-items-center gap-1">
                                        <span>{{ trans_choice('videos', $categoryVideoCount, ['count' => number_format($categoryVideoCount)]) }}</span>
                                        <i class="fa-solid fa-chevron-right ms-auto small" aria-hidden="true"></i>
                                    </small>
                                @endif
                            </div>
                        </div>
                    </a>
                </div>
            @empty
                <div class="col-12">
                    <div class="text-center p-5 bg-body-tertiary rounded-4">
                        <i class="fa-solid fa-folder-open fa-3x text-muted mb-3"></i>
                        <h4>{{ __('No categories found') }}</h4>
                        <p class="text-muted">{{ __('Categories will appear here once configured.') }}</p>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
@endsection
