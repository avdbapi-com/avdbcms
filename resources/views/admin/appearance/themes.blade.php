{{--
    Appearance > Themes (spec/admin/mockups/05-appearance-themes.html): what the site shows now, the 12 Complete
    looks, the 32 themes with their colour styles (family filter, Dark/Light, search that finds old names too), dark
    or light for first visits, and the daily designs. Picking anything is a draft shown in the preview on the right
    until Publish.
--}}
@extends('layouts.admin')
@section('title', __('Themes'))
@section('admin_page', 'appearance.themes')
@section('admin_width', 'wide')

@push('head')
    @vite('resources/admin/appearance/appearance.ts')
@endpush

@php
    $flavorsOfShown = $shownTheme['flavors'] ?? [];
    $action = $draftState['action'] ?? null;
    $layouts = (bool) ($action['layouts'] ?? false);
    $modeField = null;
    $autofixField = null;
@endphp

@section('content')
    <x-admin.page-header page="appearance.themes">
        <x-slot:actions>
            <a class="ad-btn" href="{{ route('admin.appearance.history') }}" data-ad-history-open>{{ \App\Support\AdminTheme::icon('clock') }}{{ __('History') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- The settings rows (admin/settings/partials/field.blade.php) and their switch / segmented / radio shapes. --}}
    @include('admin.settings.partials.styles')

    <div class="ad-split ad-appearance" data-ad-appearance data-page="appearance.themes">
        <form id="ad-appearance-form" class="ad-settings-form ad-appearance-form" method="POST" action="{{ $urls['publish'] }}" autocomplete="off" novalidate data-ad-appearance-form>
            @csrf

            <section class="ad-panel ad-current" aria-labelledby="ad-current-title">
                @if ($current['shot'])
                    <img src="{{ $current['shot'] }}" alt="" width="132" height="82" decoding="async">
                @endif
                <div class="ad-current-text">
                    <span class="ad-badge is-ok">{{ __('On your site now') }}</span>
                    <h2 class="ad-h2" id="ad-current-title">{{ $current['look'] ?? $current['theme'] }}</h2>
                    <p class="ad-small ad-muted">
                        {{ __('Theme :theme, colour style :flavor.', ['theme' => $current['theme'], 'flavor' => $current['flavor']]) }}
                        @if ($current['last'])
                            {{ $current['last']['by_you'] ? __('Changed :date by you.', ['date' => $current['last']['when']]) : __('Changed :date.', ['date' => $current['last']['when']]) }}
                            <a href="{{ route('admin.appearance.history') }}" data-ad-history-open>{{ __('See changes') }}</a>
                        @endif
                    </p>
                    @if ($current['look'] && $current['differences'] > 0)
                        <p class="ad-small ad-muted">{{ trans_choice('1 setting differs from the look|:count settings differ from the look', $current['differences'], ['count' => $current['differences']]) }}</p>
                    @endif
                    <p class="ad-current-actions">
                        @if ($current['last'] && $current['last']['restorable'])
                            <button type="button" class="ad-link" data-ad-undo="{{ route('admin.appearance.restore', ['revision' => $current['last']['id']]) }}">{{ __('Undo the last change') }}</button>
                        @endif
                        @if ($current['snapshot'])
                            <button type="button" class="ad-link" data-ad-restore-snapshot="{{ route('admin.appearance.restore', ['revision' => $current['snapshot']['id']]) }}">{{ __('Restore my previous look') }}</button>
                            <span class="ad-muted">{{ trans_choice('(saved before the update, 1 day left)|(saved before the update, :count days left)', (int) $current['snapshot']['days'], ['count' => (int) $current['snapshot']['days']]) }}</span>
                        @endif
                    </p>
                </div>
            </section>

            <nav class="ad-tabs ad-gallery-tabs" aria-label="{{ __('Gallery') }}" data-ad-gallery-tabs>
                <a href="{{ route('admin.appearance.index', ['tab' => 'looks']) }}" data-tab="looks" @class(['is-active' => $tab === 'looks']) @if($tab === 'looks') aria-current="page" @endif>{{ __('Complete looks') }} <span class="ad-count">{{ count($looks) }}</span></a>
                <a href="{{ route('admin.appearance.index', ['tab' => 'themes']) }}" data-tab="themes" @class(['is-active' => $tab === 'themes']) @if($tab === 'themes') aria-current="page" @endif>{{ __('Themes') }} <span class="ad-count">{{ $themeCount }}</span></a>
                <a href="{{ route('admin.appearance.index', ['tab' => 'daily']) }}" data-tab="daily" @class(['is-active' => $tab === 'daily']) @if($tab === 'daily') aria-current="page" @endif>{{ __('Daily designs') }}</a>
            </nav>

            {{-- Complete looks --}}
            <div class="ad-gallery-panel" data-panel="looks" @if ($tab !== 'looks') hidden @endif>
                <p class="ad-help">{{ __('Colours, fonts, homepage, video page and cards in one click. Your logo, texts, menus, ads and custom code stay.') }}</p>
                <ul class="ad-looks" role="list">
                    @foreach ($looks as $look)
                        <li @class(['ad-look', 'is-on' => ($action['type'] ?? null) === 'look' && ($action['id'] ?? null) === $look['id']])>
                            <button type="button" class="ad-look-pick" data-ad-look="{{ $look['id'] }}" aria-pressed="{{ ($action['type'] ?? null) === 'look' && ($action['id'] ?? null) === $look['id'] ? 'true' : 'false' }}">
                                @if ($look['thumb'])
                                    <img @if ($loop->index < 3) src="{{ $look['thumb'] }}" loading="lazy" @else data-ad-src="{{ $look['thumb'] }}" @endif alt="" width="320" height="200" decoding="async">
                                @else
                                    <span class="ad-theme-ph" aria-hidden="true">@include('admin.appearance.partials.swatch', ['colours' => $look['colours']])</span>
                                @endif
                                <span class="ad-theme-b">
                                    <b>{{ $look['label'] }}
                                        @if ($look['current'])<span class="ad-badge is-ok">{{ __('On your site now') }}</span>
                                        @elseif ($look['default'])<span class="ad-badge">{{ __('Default') }}</span>
                                        @elseif ($look['is_new'])<span class="ad-badge is-info">{{ __('New') }}</span>@endif
                                    </b>
                                    <small>{{ $look['theme_name'] }} · {{ $look['flavor_name'] }}</small>
                                    <span class="ad-look-best">{{ $look['best_for'] }}</span>
                                    @if ($look['formerly'])<small>{{ $look['formerly'] }}</small>@endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Themes and colour styles --}}
            <div class="ad-gallery-panel" data-panel="themes" @if ($tab !== 'themes') hidden @endif>
                <p class="ad-help">{{ __(':themes designs in :families families, :flavors colour styles. Each theme has its own layout, fonts, shapes and a light and dark version.', ['themes' => $themeCount, 'families' => count($families), 'flavors' => $flavorCount]) }}</p>
                <div class="ad-chips ad-families" role="group" aria-label="{{ __('Families') }}" data-ad-families>
                    <button type="button" class="ad-chip is-on" data-family="" aria-pressed="true">{{ __('All :count', ['count' => $themeCount]) }}</button>
                    @foreach ($families as $family)
                        <button type="button" class="ad-chip" data-family="{{ $family['id'] }}" aria-pressed="false">{{ $family['name'] }}</button>
                    @endforeach
                </div>
                <div class="ad-gallery-search">
                    <label class="ad-search-field">
                        {{ \App\Support\AdminTheme::icon('search', 'sm') }}
                        <span class="ad-sr">{{ __('Search themes') }}</span>
                        <input type="search" class="ad-input" placeholder="{{ __('Search themes - old names work too, e.g. "Tokyo Neon Horizon"') }}" data-ad-theme-search data-ad-ignore>
                    </label>
                    <div class="ad-seg" role="radiogroup" aria-label="{{ __('Dark or light themes') }}" data-ad-theme-mode>
                        <label><input type="radio" class="ad-sr" name="ad_theme_mode_filter" value="" checked data-ad-ignore>{{ __('All') }}</label>
                        <label><input type="radio" class="ad-sr" name="ad_theme_mode_filter" value="dark" data-ad-ignore>{{ __('Dark') }}</label>
                        <label><input type="radio" class="ad-sr" name="ad_theme_mode_filter" value="light" data-ad-ignore>{{ __('Light') }}</label>
                    </div>
                </div>
                <ul class="ad-themes" role="list" data-ad-themes data-total="{{ $themeCount }}">
                    @foreach ($themes as $theme)
                        @include('admin.appearance.partials.theme-card', ['theme' => $theme, 'selected' => $shownTheme['id'] ?? null, 'deferred' => $loop->index >= 3])
                    @endforeach
                </ul>
                <p class="ad-gallery-more">
                    <a class="ad-btn" href="{{ route('admin.appearance.gallery', ['tab' => 'themes']) }}" data-ad-themes-more>{{ __('Show all :count themes', ['count' => $themeCount]) }}</a>
                </p>

                <section class="ad-panel ad-flavors" aria-labelledby="ad-flavors-title" data-ad-flavors data-theme="{{ $shownTheme['id'] ?? '' }}">
                    <div class="ad-panel-h">
                        <div>
                            <h2 id="ad-flavors-title">{{ __('Colour style for :name', ['name' => $shownTheme['name'] ?? '']) }}</h2>
                            <p>{{ __('Every style keeps text readable in dark and light mode.') }}</p>
                        </div>
                    </div>
                    <div class="ad-panel-b">
                        <div class="ad-swatches" role="radiogroup" aria-labelledby="ad-flavors-title" data-ad-swatches>
                            @foreach ($flavorsOfShown as $flavor)
                                <label class="ad-swatch" @if ($flavor['formerly']) title="{{ $flavor['formerly'] }}" @endif><input type="radio" class="ad-sr" name="ad_flavor" value="{{ $flavor['id'] }}" data-ad-ignore @checked($flavor['id'] === $shownFlavor)>@include('admin.appearance.partials.swatch', ['colours' => $flavor['colours']])<span>{{ $flavor['name'] }}</span></label>
                            @endforeach
                        </div>
                        <label class="ad-check-line"><input type="checkbox" class="ad-check" data-ad-theme-layouts data-ad-ignore @checked($layouts)> {{ __('Also use this theme\'s homepage, video page and card layouts') }}</label>
                        @include('admin.appearance.partials.colour-note', ['note' => $colourNote, 'onThemes' => true])
                        <p class="ad-small"><a href="{{ route('admin.appearance.page', ['page' => 'brand']) }}">{{ __('Custom colours') }}</a> {{ __('are in Logo, colours & fonts.') }}</p>
                    </div>
                </section>

                @foreach ($modeFields as $f)
                    <section class="ad-sec" id="card-mode" aria-labelledby="card-mode-title">
                        <div class="ad-sec-intro"><h2 id="card-mode-title">{{ __('Dark or light') }}</h2><p>{{ __('What first-time visitors see. Visitors can switch if the switch is on in Header, menus & footer.') }}</p></div>
                        <div class="ad-sec-body">@include('admin.appearance.partials.field', ['f' => $f])</div>
                    </section>
                @endforeach
                @foreach ($autofixFields as $f)
                    <div class="ad-sec-body ad-autofix">@include('admin.appearance.partials.field', ['f' => $f])</div>
                @endforeach
            </div>

            {{-- Daily designs --}}
            <div class="ad-gallery-panel" data-panel="daily" @if ($tab !== 'daily') hidden @endif>
                <section class="ad-sec" id="card-daily" aria-labelledby="card-daily-title">
                    <div class="ad-sec-intro"><h2 id="card-daily-title">{{ __('A new design every day') }}</h2><p>{{ __('Your site can change its colours, or its whole theme, every day of the year. Click a day to preview it.') }}</p></div>
                    <div class="ad-sec-body">
                        @foreach ($daily as $f)
                            @include('admin.appearance.partials.field', ['f' => $f])
                        @endforeach
                    </div>
                </section>
                {{-- Daily designs belong to this year (a day's preview is ?daily_design=<day of the year>): the month
                     buttons stop at January and December. --}}
                <section class="ad-panel ad-cal" aria-labelledby="ad-cal-title" data-ad-calendar data-month="{{ $month }}">
                    <div class="ad-panel-h">
                        <button type="button" class="ad-iconbtn" data-ad-cal-step="-1" aria-label="{{ __('Previous month') }}" @disabled($month <= 1)>{{ \App\Support\AdminTheme::icon('chev-left', 'flip-rtl') }}</button>
                        <h2 id="ad-cal-title" data-ad-cal-title>{{ now()->translatedFormat('F Y') }}</h2>
                        <button type="button" class="ad-iconbtn" data-ad-cal-step="1" aria-label="{{ __('Next month') }}" @disabled($month >= 12)>{{ \App\Support\AdminTheme::icon('chev-right', 'flip-rtl') }}</button>
                    </div>
                    <div class="ad-panel-b">
                        <div class="ad-cal-head" aria-hidden="true">@foreach (range(0, 6) as $weekday)<span>{{ now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->addDays($weekday)->translatedFormat('D') }}</span>@endforeach</div>
                        <ol class="ad-cal-grid" data-ad-cal-days aria-live="polite"></ol>
                        <p class="ad-small"><a href="{{ route('admin.appearance.calendar', ['month' => $month]) }}" data-ad-cal-link>{{ __('Open the month as a page') }}</a></p>
                    </div>
                </section>
            </div>

            @php($manifest = [])
            @foreach (array_merge($modeFields, $autofixFields, $daily) as $f)
                @php($manifest[$f['group']][] = $f['key'])
            @endforeach
            @foreach ($manifest as $group => $keys)
                <input type="hidden" name="fields[{{ $group }}]" value="{{ implode(',', $keys) }}">
            @endforeach
        </form>

        <aside class="ad-appearance-side" aria-label="{{ __('Preview') }}">
            <x-admin.monitor :pages="$monitorPages" :shot="$shot" shot-page="/">
                <x-slot:publish>@include('admin.appearance.partials.publish-bar', ['publishLabel' => $action ? (($action['type'] ?? '') === 'look' ? __('Publish this look') : __('Publish this theme')) : null])</x-slot:publish>
            </x-admin.monitor>
        </aside>
    </div>

    @include('admin.appearance.partials.history')
    @include('admin.appearance.partials.state')
@endsection
