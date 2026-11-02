@props([
    'context',
    'as' => 'div',
])

@php
    // The list ad of a server-rendered list (search results, playlists, Up next, the channel home sections): its code
    // and shape by ad context, as window.AD_CONFIG has them (App\Support\ListAdContexts).
    $context = (string) $context;
    $adCode = trim(\App\Support\ListAdContexts::code($context));
    $adsEnabled = (bool) config('site.ads_enabled', false);
    $hideAds = $adsEnabled && config('site.ads_hide_for_premium', true) && auth()->user()?->is_premium;
    $isWide = \App\Support\ListAdContexts::shape($context) === 'wide_row';
    $classes = [
        'ad-slot',
        'ad-slot-grid',
        'ad-slot-grid--wide' => $isWide,
        'avd-grid-ad-col--wide' => $isWide,
    ];
@endphp

@if($adsEnabled && !$hideAds && $adCode !== '')
    @if($as === 'li')
        <li {{ $attributes->class($classes) }} role="complementary" aria-label="{{__('Advertisement')}}">
            {!! $adCode !!}
        </li>
    @else
        <div {{ $attributes->class($classes) }} role="complementary" aria-label="{{__('Advertisement')}}">
            {!! $adCode !!}
        </div>
    @endif
@endif
