{{--
    One month of daily designs as a page (GET /admin/appearance/calendar without JavaScript; the Themes page loads
    the same month as JSON). Each day links to its preview on the real site.
--}}
@extends('layouts.admin')
@section('title', __('Daily designs'))
@section('admin_page', 'appearance.themes')
@section('admin_width', 'wide')

@push('head')
    @vite('resources/admin/appearance/appearance.ts')
@endpush

@section('content')
    <x-admin.page-header page="appearance.themes" :title="__('Daily designs')" :description="$name" />

    <nav class="ad-row ad-gallery-pages" aria-label="{{ __('Months') }}">
        @if ($month > 1)<a class="ad-btn" href="{{ route('admin.appearance.calendar', ['month' => $month - 1]) }}">{{ __('Previous month') }}</a>@endif
        @if ($month < 12)<a class="ad-btn" href="{{ route('admin.appearance.calendar', ['month' => $month + 1]) }}">{{ __('Next month') }}</a>@endif
        <a class="ad-btn is-ghost" href="{{ route('admin.appearance.index', ['tab' => 'daily']) }}">{{ __('Back to Themes') }}</a>
    </nav>

    <ol class="ad-cal-grid is-page">
        @foreach ($days as $day)
            <li @class(['ad-cal-day', 'is-today' => $day['today']])>
                <a href="{{ $day['preview'] }}" target="_blank" rel="noopener">
                    <span class="ad-cal-n">{{ $day['day_of_month'] }}</span>
                    @include('admin.appearance.partials.swatch', ['colours' => $day['colours']])
                    <span class="ad-cal-l">{{ $day['label'] }}</span>
                    @if ($day['holiday'])<small>{{ $day['holiday'] }}</small>@endif
                </a>
            </li>
        @endforeach
    </ol>
@endsection
