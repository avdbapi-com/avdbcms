@php
    $locale = str_replace('_', '-', (string) app()->getLocale());
    $languageConfig = (array) config('languages.' . app()->getLocale(), []);
    $baseLocale = strtolower((string) explode('-', $locale)[0]);
    $isRtl = array_key_exists('rtl', $languageConfig)
        ? (bool) $languageConfig['rtl']
        : in_array($baseLocale, ['ar', 'fa', 'he', 'ur'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-bs-theme="{{ \App\Models\Setting::getGroup('appearance')['theme'] ?? 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} | {{ config('app.name') }}</title>
    @vite(['resources/js/app.ts'])
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">
    <div class="text-center px-4" style="max-width: 600px;">
        <div class="mb-4">
            <i class="fa-solid fa-screwdriver-wrench fa-4x text-warning"></i>
        </div>
        <h1 class="display-5 fw-bold">{{ $title }}</h1>
        <p class="lead text-muted mt-3">{{ $message }}</p>
        <hr>
        <p class="text-muted small">&mdash; {{ config('app.name') }} {{__('Team')}}</p>
    </div>
</body>
</html>
