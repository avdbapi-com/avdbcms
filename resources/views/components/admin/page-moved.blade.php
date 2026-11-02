{{--
    "What moved where" (sidebar footer): every page of the old admin menu and where it is now, then every tab of the old
    settings page with the pages its settings are on now (how many on each). A developer page says so (it opens the page
    that turns Developer mode on).
    Rendered by AdminSearchController::moved() from config/admin_nav.php old_menu and config/admin_settings.php old_tabs.
--}}
@extends('layouts.admin', ['adminPage' => null])
@section('title', __('What moved where'))
@section('content')
    <x-admin.page-header :title="__('What moved where')" :description="__('The admin menu is now grouped in 8 sections. Every old address still works; here is where each old page lives now.')" :crumbs="[]" />
    <x-admin.table :label="__('What moved where')">
        <x-slot:head>
            <th>{{ __('Old menu') }}</th>
            <th>{{ __('Now in') }}</th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['old'] }}</td>
                <td>
                    @if ($row['url'])
                        <a href="{{ $row['url'] }}">{{ $row['section'] }} &rsaquo; {{ $row['page'] }}</a>
                    @else
                        {{ $row['section'] }} &rsaquo; {{ $row['page'] }}
                    @endif
                    @if ($row['developer'] ?? false)
                        <span class="ad-badge">{{ __('Developer mode') }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    <h2 class="ad-h2" id="old-settings-tabs" style="margin-top:32px">{{ __('Tabs of the old Settings page') }}</h2>
    <p class="ad-muted" style="margin:4px 0 12px">{{ __('Each tab of the old Settings page and where its settings are now, with how many on each page.') }} {{ __('Search finds every setting by its old name too.') }}</p>
    <x-admin.table :label="__('Tabs of the old Settings page')">
        <x-slot:head>
            <th>{{ __('Old tab') }}</th>
            <th>{{ __('Now in') }}</th>
        </x-slot:head>
        @foreach ($tabs ?? [] as $tab)
            <tr data-old-tab="{{ $tab['tab'] }}">
                <td>{{ __('Settings') }} &rsaquo; {{ $tab['label'] }}</td>
                <td>
                    @foreach ($tab['pages'] as $page)
                        <span class="ad-moved-page">
                            @if ($page['url'])<a href="{{ $page['url'] }}">{{ $page['label'] }}</a>@else{{ $page['label'] }}@endif
                            @if ($page['count'] > 0)({{ $page['count'] }})@endif
                            @if ($page['developer'])<span class="ad-badge">{{ __('Developer mode') }}</span>@endif
                        </span>
                    @endforeach
                </td>
            </tr>
        @endforeach
    </x-admin.table>
    {{-- One page per line, each link at least 24 px tall: the lines were 19 px links right under each other (WCAG
         2.5.8, R2A-058). --}}
    <style>
        .ad-moved-page{display:flex;flex-wrap:wrap;align-items:center;column-gap:6px;min-block-size:24px}
        .ad-moved-page > a{display:inline-flex;align-items:center;min-block-size:24px}
    </style>
@endsection
