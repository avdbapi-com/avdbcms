@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
    $searchLabel = trim((string) ($search ?: (request()->input('order') === 'random' ? __('Random Videos') : __('Explore Videos'))));
    $searchSeoValues = [
        'search' => $searchLabel,
        'title' => $searchLabel,
    ];
    $resultsCount = isset($results)
        ? (method_exists($results, 'total') ? $results->total() : $results->count())
        : 0;
@endphp

@section('title', $seoService->renderTemplate('search_meta_title_template', $searchSeoValues, $searchLabel, 180))
@section('description', $seoService->renderTemplate('search_meta_description_template', $searchSeoValues, __('Search videos on :site.', ['site' => config('app.name')]), 500))
@if(!config('site.search_pages_indexable', false))
    @section('robots', 'noindex, follow')
@endif

@section('class', 'avd-search-page px-3 mt-3')

@section('content')
    <div x-data="{ filters: {{!empty($filters) ? 'true' : 'false'}} }" class="avd-section-card avd-search-panel p-3 p-md-4 mb-4">
        <div class="avd-search-panel-header">
            <div class="avd-search-panel-title">
                <div class="avd-page-kicker mb-1">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>{{ __('Search') }}</span>
                </div>
                <h1 class="h4 fw-black mb-0 text-break">
                    {{ $search ? __('Results for').' "'.$search.'"' : (request()->input('order') === 'random' ? __('Random Videos') : __('Explore Videos')) }}
                </h1>
            </div>
            <div class="avd-search-panel-actions">
                @if($resultsCount)
                    <span class="avd-meta-chip">{{trans_choice('results', $resultsCount)}}</span>
                @endif
                <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-2 avd-search-filter-toggle" @click="filters = !filters" aria-controls="search-filters" :aria-expanded="filters">
                    <i class="fa-solid fa-filter"></i>
                    <span>{{ __('Filters') }}</span>
                </button>
            </div>
        </div>

        <form id="search-filters" class="mt-4 row align-items-end gx-2 gy-3" method="GET" x-show.important="filters" x-cloak>
            <input type="hidden" name="q" value="{{$search}}">
            @if(request()->filled('order'))
                <input type="hidden" name="order" value="{{ request()->input('order') }}">
            @endif
            <div class="col-12 col-sm-6 col-lg">
                <label for="date" class="form-label fw-bold">{{ __('Upload Date') }}</label>
                <select id="date" name="date" class="form-select" aria-label="{{__('Upload Date')}}">
                    <option selected value="">{{ __('All') }}</option>
                    @foreach(['hour' => __('Last hour') ,'today' => __('Today'), 'week' => __('This week'), 'month' => __('This month'), 'year' => __('This year')] as $value => $label)
                        <option @selected(($filters['date'] ?? null) === $value) value="{{$value}}">{{$label}}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg">
                <label for="duration" class="form-label fw-bold">{{ __('Duration') }}</label>
                <select id="duration" name="duration" class="form-select" aria-label="{{__('Duration')}}">
                    <option selected value="">{{ __('All') }}</option>
                    @foreach(['4' => __('Under 4 minutes') , '4-20' => __('4-20 minutes'), '20' => __('Over 20 minutes')] as $value => $label)
                        <option @selected(($filters['duration'] ?? null) == $value) value="{{$value}}">{{$label}}</option>
                    @endforeach
                </select>
            </div>
            <div class="btn-group col-auto">
                <button type="submit" class="btn btn-outline-secondary" title="{{__('Search')}}" aria-label="{{__('Search')}}">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                </button>
                <a href="{{ (function_exists('locale_url') ? locale_url(route('search.index')) : route('search.index')) }}" class="btn btn-outline-secondary" title="{{__('Clear')}}" aria-label="{{__('Clear')}}">
                    <i class="fa-solid fa-eraser" aria-hidden="true"></i>
                </a>
            </div>
        </form>
    </div>

    <div class="row">
        <div class="col-12 col-xl-10 offset-xl-1">
            @if($resultsCount)
                @foreach($results as $result)
                    @include('search.'.$result->type, [$result->type => $result])
                    @if(\App\Support\FeedAdSlots::listAdAfter($loop->iteration, $loop->count, \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6))))
                        <x-feed-ad-slot context="search_results" class="mb-3" />
                    @endif
                @endforeach
                @if(method_exists($results, 'links'))
                    {{ $results->links() }}
                @endif
            @else
                <div class="avd-empty-state text-center p-4 p-md-5">
                    <i class="fa-solid fa-search fa-3x text-muted mb-3"></i>
                    <h2 class="h4 fw-black text-break">{{ __('No results found') }} {{ $search ? __('for'). ' "' . $search . '"' : '' }}</h2>
                    <div class="text-muted">{{ __('Try different keywords or remove search filters') }}</div>
                </div>
            @endif
        </div>
    </div>
@endsection
