{{--
    Preview monitor of the Appearance pages: device switch (Computer/Phone), page switch, a static screenshot
    until the admin asks for the live preview (then an iframe of the real site with the draft applied), and the
    "Not published" bar (slot "publish").
    <x-admin.monitor :pages="[$homePreviewUrl => 'Homepage', $videoPreviewUrl => 'Video page']" :shot="$thumbUrl">
        <x-slot:publish>...Discard / Publish...</x-slot:publish>
    </x-admin.monitor>
    Props: pages (preview URL => label: each URL is what the iframe loads, e.g. a signed draft-preview link per page),
    src (the URL to load first; default: the first page), shot (static image URL), shot-page (the path the static
    image shows, e.g. "/": for the other pages the screen asks for the live preview instead; default: every page),
    device (computer|phone).
    Behaviour (modules/monitor.ts): the device switch (radios ad_monitor_device_*, one group per monitor) resizes
    the screen; "Show live preview" loads the picked page in a sandboxed same-origin iframe at the real device width
    (1280 / 390 px), scaled to fit, and then becomes "Reload preview"; picking another page loads it when live. After
    a visual setting changes, send "ad:monitor-refresh" (on the monitor or on document): it shows the live preview, or
    reloads it. The icon link opens the picked page in a new tab; when the site's own security headers refuse the
    frame (a custom CSP frame-ancestors), the screen offers that link instead.
--}}
@props(['pages' => [], 'src' => null, 'shot' => null, 'shotPage' => null, 'device' => 'computer'])
@php($src ??= array_key_first($pages))
@php($shotShown = $shot && ($shotPage === null || parse_url((string) $src, PHP_URL_PATH) === $shotPage))
@php($deviceName = 'ad_monitor_device_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)))
<div {{ $attributes->class(['ad-monitor']) }} data-ad-monitor @if($src) data-src="{{ $src }}" @endif data-frame-title="{{ __('Preview of your site') }}" data-blocked="{{ __('Your site does not allow its preview inside this page (its security settings). Open it in a new tab instead.') }}" data-open-label="{{ __('Open the preview in a new tab') }}">
    <div class="ad-monitor-bar">
        <x-admin.segmented :name="$deviceName" :value="$device" :label="__('Preview on')" :options="['computer' => __('Computer'), 'phone' => __('Phone')]" data-ad-ignore />
        @if (count($pages) > 1)
            <select class="ad-select" aria-label="{{ __('Page to preview') }}" data-ad-monitor-page data-ad-ignore>
                @foreach ($pages as $url => $label)
                    <option value="{{ $url }}" @selected($url === $src)>{{ $label }}</option>
                @endforeach
            </select>
        @endif
        <span class="ad-spacer"></span>
        @if ($src)
            <button type="button" class="ad-btn sm" data-ad-monitor-live data-reload-label="{{ __('Reload preview') }}">{{ \App\Support\AdminTheme::icon('eye') }}<span>{{ __('Show live preview') }}</span></button>
            <a class="ad-iconbtn" href="{{ $src }}" target="_blank" rel="noopener" data-ad-monitor-open aria-label="{{ __('Open the preview in a new tab') }}" data-ad-tip="{{ __('Open the preview in a new tab') }}">{{ \App\Support\AdminTheme::icon('external') }}</a>
        @endif
    </div>
    <div class="ad-monitor-stage">
        <div @class(['ad-monitor-screen', 'is-phone' => $device === 'phone']) data-ad-monitor-screen>
            @if ($shot)
                <img src="{{ $shot }}" alt="{{ __('Preview of your site') }}" loading="lazy" decoding="async" data-ad-monitor-shot @if ($shotPage !== null) data-page="{{ $shotPage }}" @endif @unless ($shotShown) hidden @endunless>
            @endif
            @if ($src)
                <span class="ad-monitor-empty" @if ($shotShown) hidden @endif>{{ __('Show the live preview to see your site here.') }}</span>
            @endif
        </div>
    </div>
    @isset($publish)
        <div class="ad-publish">{{ $publish }}</div>
    @endisset
</div>
