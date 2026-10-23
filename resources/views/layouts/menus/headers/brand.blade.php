{{-- Menu toggle + site brand. $withToggle = false leaves the toggle to the caller; $brandClass adds a deco variant,
     $brandZoneClass places the zone (the crest and masthead centre it on wide screens). --}}
@php
    $headerBrandText = trim((string) config('site.header_brand_text', '')) ?: config('app.name');
    $headerPremiumChipEnabled = config('site.header_premium_chip_enabled', true);
    $headerPremiumLabel = trim((string) config('site.header_premium_label', 'Premium')) ?: 'Premium';
    $brandLogo = $logoUrl ?? config('site.logo_url');
@endphp
<div @class(['avd-header-brand-zone d-flex align-items-center gap-2', $brandZoneClass ?? '', 'justify-content-md-center' => $sidebarPinned])>
    @if($withToggle ?? true)
        @include('layouts.menus.headers.toggle')
    @endif
    <a @class(['navbar-brand avd-brand me-1 d-flex align-items-center gap-2', $brandClass ?? '', 'is-premium' => Auth::user()?->is_premium, 'show_sidebar' => $sidebarPinned]) href="{{locale_url(route('pages.home'))}}">
        @if($brandLogo)
            <img class="avd-brand-logo" src="{{ site_asset_url($brandLogo) }}" alt="{{config('app.name')}}">
        @else
            <span class="avd-brand-mark" aria-hidden="true"><i class="fa-solid fa-play"></i></span>
            <span class="avd-brand-text">{{ __($headerBrandText) }}</span>
        @endif
        @if($headerPremiumChipEnabled && Auth::user()?->is_premium)
            <span class="avd-premium-chip"><i class="fa-solid fa-crown" aria-hidden="true"></i>{{ __($headerPremiumLabel) }}</span>
        @endif
    </a>
</div>
