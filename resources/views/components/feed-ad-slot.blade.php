@props([
    'context',
    'as' => 'div',
])

@php
    $context = (string) $context;
    $adCode = trim((string) config('site.ad_code_grid_' . $context, ''));
    $adsEnabled = (bool) config('site.ads_enabled', false);
    $hideAds = $adsEnabled && config('site.ads_hide_for_premium', true) && auth()->user()?->is_premium;
    $layout = (string) config('site.ad_grid_layout_' . $context, 'inherit');
    $layout = $layout === 'inherit' ? (string) config('site.ad_grid_layout', 'grid_card') : $layout;
    $isWide = $layout === 'wide_row';
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
