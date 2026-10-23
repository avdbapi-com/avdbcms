@php
    $currentRoute = request()->route()?->getName();
    $activeCategorySlug = isset($activeCategory) ? $activeCategory->slug : (isset($category) && is_object($category) && isset($category->slug) ? $category->slug : null);
    $isHome = ($currentRoute === 'pages.home' || $currentRoute === 'home') && empty($activeCategorySlug);
    $isTrend = ($currentRoute === 'pages.trend' || $currentRoute === 'trend');
    $categoriesList = $categories ?? collect();
    if ($categoriesList->isEmpty()) {
        try {
            $categoriesList = \Illuminate\Support\Facades\Cache::remember('categories_chips_all', 3600, fn() => \App\Models\Category::query()->where('in_menu', true)->ordered()->get());
        } catch (\Throwable) {
            $categoriesList = collect();
        }
    }
@endphp

@if(config('site.header_category_chips_enabled', true))
<div class="avd-chips-scroller-wrapper position-relative {{ $chipsSpacing ?? 'mb-4' }}" data-avd-chips-scroller data-avd-home-widget="category_chips" data-avd-motion-direction="right">
    <!-- Left Navigation Arrow with Fade Mask -->
    <button type="button" class="avd-chips-nav avd-chips-nav-left" data-chips-prev aria-label="{{ __('Scroll left') }}" aria-controls="category-tabs">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
    </button>

    <!-- Scrollable Chips Track (Hardware-Accelerated Momentum Scrolling for Mobile & iOS) -->
    <div class="avd-category-tabs d-flex align-items-center gap-2 overflow-x-auto scrollbar-none px-1 py-1" id="category-tabs" tabindex="0" role="region" aria-label="{{ __('Categories') }}" style="scrollbar-width: none; -ms-overflow-style: none; -webkit-overflow-scrolling: touch; touch-action: pan-x pan-y; overscroll-behavior-x: contain;">
        <a href="{{ locale_url(route('pages.home')) }}" class="avd-tag {{ $isHome ? 'active' : '' }}" @if($isHome) aria-current="page" @endif>
            <i class="fa-solid fa-fire" aria-hidden="true"></i>
            <span>{{ __('All') }}</span>
        </a>
        @if(config('site.show_trending', true))
        <a href="{{ locale_url(route('pages.trend')) }}" class="avd-tag {{ $isTrend ? 'active' : '' }}" @if($isTrend) aria-current="page" @endif>
            <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i>
            <span>{{ __('Trending') }}</span>
        </a>
        @endif
        @foreach($categoriesList as $catItem)
            @php
                $isActiveCat = ($activeCategorySlug && $activeCategorySlug === $catItem->slug) || (isset($activeCategory) && $activeCategory->id === $catItem->id) || (isset($category) && is_object($category) && isset($category->id) && $category->id === $catItem->id);
            @endphp
            <a href="{{ locale_url(route('pages.category', $catItem->slug)) }}" class="avd-tag {{ $isActiveCat ? 'active' : '' }}" @if($isActiveCat) aria-current="page" @endif>
                <i class="fa-solid fa-{{ $catItem->icon_name ?: 'tag' }}" aria-hidden="true"></i>
                <span>{{ __($catItem->title) }}</span>
            </a>
        @endforeach
    </div>

    <!-- Right Navigation Arrow with Fade Mask -->
    <button type="button" class="avd-chips-nav avd-chips-nav-right" data-chips-next aria-label="{{ __('Scroll right') }}" aria-controls="category-tabs">
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    </button>
</div>
@endif
