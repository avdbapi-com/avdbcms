{{--
    The theme gallery as a page (what GET /admin/appearance/gallery shows without JavaScript; the Themes page loads
    the same items as JSON). Picking a theme here previews it like on the Themes page.
--}}
@extends('layouts.admin')
@section('title', $tab === 'looks' ? __('Complete looks') : __('All themes'))
@section('admin_page', 'appearance.themes')
@section('admin_width', 'wide')

@push('head')
    @vite('resources/admin/appearance/appearance.ts')
@endpush

@section('content')
    <x-admin.page-header page="appearance.themes" :title="$tab === 'looks' ? __('Complete looks') : __('All themes')" :description="__(':count items. Open Themes to preview one on your site.', ['count' => $total])" />

    @if ($tab === 'themes')
        <form class="ad-gallery-search ad-appearance-filter" method="GET" action="{{ route('admin.appearance.gallery') }}">
            <input type="hidden" name="tab" value="themes">
            <label class="ad-search-field">{{ \App\Support\AdminTheme::icon('search', 'sm') }}<span class="ad-sr">{{ __('Search themes') }}</span>
                <input type="search" class="ad-input" name="q" value="{{ $query }}" placeholder="{{ __('Search themes - old names work too, e.g. "Tokyo Neon Horizon"') }}"></label>
            <select class="ad-select" name="family" aria-label="{{ __('Families') }}">
                <option value="">{{ __('All families') }}</option>
                @foreach ($families as $id => $f)
                    <option value="{{ $id }}" @selected($family === $id)>{{ __($f['name']) }}</option>
                @endforeach
            </select>
            <select class="ad-select" name="mode" aria-label="{{ __('Dark or light themes') }}">
                <option value="">{{ __('Dark and light') }}</option>
                <option value="dark" @selected($mode === 'dark')>{{ __('Dark') }}</option>
                <option value="light" @selected($mode === 'light')>{{ __('Light') }}</option>
            </select>
            <button type="submit" class="ad-btn">{{ __('Search') }}</button>
        </form>
        <ul class="ad-themes is-page" role="list">
            @forelse ($items as $theme)
                <li @class(['ad-theme', 'is-on' => $theme['current']])>
                    <div class="ad-theme-pick">
                        @if ($theme['thumb'])<img src="{{ $theme['thumb'] }}" alt="" width="320" height="200" loading="lazy" decoding="async">@else<span class="ad-theme-ph" aria-hidden="true">@include('admin.appearance.partials.swatch', ['colours' => $theme['flavors'][0]['colours'] ?? []])</span>@endif
                        <span class="ad-theme-b"><b>{{ $theme['name'] }}</b><small>{{ $theme['family_name'] }} · {{ trans_choice('1 colour style|:count colour styles', count($theme['flavors']), ['count' => count($theme['flavors'])]) }}@if (! empty($theme['match'])) · {{ $theme['match'] }}@elseif ($theme['formerly']) · {{ $theme['formerly'] }}@endif</small></span>
                    </div>
                    {{-- Without scripts there is no preview: this publishes the theme at once (the settings' theme apply). --}}
                    <form method="POST" action="{{ route('admin.settings.apply-theme') }}" class="ad-theme-apply" data-ad-confirm="{{ __('Use :name on your site now?', ['name' => $theme['name']]) }}" data-ad-confirm-tone="primary">
                        @csrf
                        <input type="hidden" name="theme_preset" value="{{ $theme['id'] }}">
                        <button type="submit" class="ad-btn sm">{{ __('Use this theme now') }}</button>
                    </form>
                </li>
            @empty
                <li class="ad-muted">{{ __('No theme matches. Try another word, or an old theme name.') }}</li>
            @endforelse
        </ul>
    @else
        <ul class="ad-looks is-page" role="list">
            @foreach ($items as $look)
                <li @class(['ad-look', 'is-on' => $look['current']])>
                    <div class="ad-look-pick">
                        @if ($look['thumb'])<img src="{{ $look['thumb'] }}" alt="" width="320" height="200" loading="lazy" decoding="async">@endif
                        <span class="ad-theme-b"><b>{{ $look['label'] }}</b><small>{{ $look['theme_name'] }} · {{ $look['flavor_name'] }}</small><span class="ad-look-best">{{ $look['best_for'] }}</span></span>
                    </div>
                    {{-- Without scripts there is no preview: this publishes the look at once (the settings' look apply). --}}
                    <form method="POST" action="{{ route('admin.settings.apply-experience') }}" class="ad-theme-apply" data-ad-confirm="{{ __('Use :name on your site now?', ['name' => $look['label']]) }}" data-ad-confirm-tone="primary">
                        @csrf
                        <input type="hidden" name="experience_key" value="{{ $look['id'] }}">
                        <button type="submit" class="ad-btn sm">{{ __('Use this look now') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($total > $perPage)
        <nav class="ad-row ad-gallery-pages" aria-label="{{ __('Pages') }}">
            @if ($page > 1)<a class="ad-btn" href="{{ route('admin.appearance.gallery', array_filter(['tab' => $tab, 'q' => $query, 'family' => $family, 'mode' => $mode, 'page' => $page - 1])) }}">{{ __('Previous page') }}</a>@endif
            @if ($page * $perPage < $total)<a class="ad-btn" href="{{ route('admin.appearance.gallery', array_filter(['tab' => $tab, 'q' => $query, 'family' => $family, 'mode' => $mode, 'page' => $page + 1])) }}">{{ __('Next page') }}</a>@endif
        </nav>
    @endif
@endsection
