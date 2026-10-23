@php
    $locale = str_replace('_', '-', (string) app()->getLocale());
    $languageConfig = (array) config('languages.' . app()->getLocale(), []);
    $baseLocale = strtolower((string) explode('-', $locale)[0]);
    $isRtl = array_key_exists('rtl', $languageConfig)
        ? (bool) $languageConfig['rtl']
        : in_array($baseLocale, ['ar', 'fa', 'he', 'ur'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title')</title>

        <!-- Styles -->
        <style>
            :root {
                color-scheme: light dark;
                --error-bg: #f8fafc;
                --error-text: #0f172a;
                --error-muted: #475569;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --error-bg: #090b10;
                    --error-text: #f8fafc;
                    --error-muted: #aab3c5;
                }
            }

            html, body {
                background-color: var(--error-bg);
                color: var(--error-muted);
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
                font-weight: 500;
                height: 100vh;
                margin: 0;
            }

            .full-height {
                height: 100vh;
            }

            .flex-center {
                align-items: center;
                display: flex;
                justify-content: center;
            }

            .position-ref {
                position: relative;
            }

            .content {
                text-align: center;
            }

            .title {
                color: var(--error-text);
                font-size: 36px;
                font-weight: 800;
                padding: 20px;
            }
        </style>
    </head>
    <body>
        <div class="flex-center position-ref full-height">
            <div class="content">
                <div class="title">
                    @yield('message')
                </div>
            </div>
        </div>
    </body>
</html>
