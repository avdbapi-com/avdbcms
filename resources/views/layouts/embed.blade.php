@php
    $locale = str_replace('_', '-', (string) app()->getLocale());
    $languageConfig = (array) config('languages.' . app()->getLocale(), []);
    $baseLocale = strtolower((string) explode('-', $locale)[0]);
    $isRtl = array_key_exists('rtl', $languageConfig)
        ? (bool) $languageConfig['rtl']
        : in_array($baseLocale, ['ar', 'fa', 'he', 'ur'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>{{config('app.name')}}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="language" content="{{ str_replace('_', '-', app()->getLocale()) }}" />
    <meta name="copyright" content="{{config('app.url')}}" />
    <meta name="author" content="Anton Bourtnik" />

    <meta name="theme-color" content="#0b0e15" />
    <meta name="msapplication-navbutton-color" content="#0b0e15" />
    <meta name="apple-mobile-web-app-status-bar-style" content="#0b0e15" />

    <link rel="icon" href="{{ config('site.favicon_url') ? site_asset_url((string) config('site.favicon_url')) : asset('/icons/favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{asset('/icons/apple-touch-icon.png')}}">

    <meta name="robots" content="noindex">

    @vite(['resources/js/app.ts'])
</head>
<body class="w-100 h-100 avd-embed-shell">
    <div id="player" class="w-100 h-100 position-relative bg-dark">
        <div class="position-absolute top-50 w-100 start-50 translate-middle text-white d-flex gap-4 align-items-center justify-content-center">
            <i class="fa-solid fa-circle-exclamation fa-4x" aria-hidden="true"></i>
            <span class="fs-4">@yield('message')</span>
        </div>
        <a href="{{route('pages.home')}}" class="position-absolute text-white bg-dark bg-opacity-75 bottom-0 right-0 p-2 text-decoration-none text-sm">
            {{config('app.name')}}
        </a>
    </div>
    <script>
        const LANG = '{{app()->getLocale()}}'
    </script>
</body>
</html>
