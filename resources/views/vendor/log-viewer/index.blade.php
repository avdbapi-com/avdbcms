<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset(mix('img/log-viewer-32.png', config('log-viewer.assets_path'))) }}">

    <title>Log Viewer{{ config('app.name') ? ' - ' . config('app.name') : '' }}</title>

    <!-- Style sheets-->
    <link href="{{ asset(mix('app.css', config('log-viewer.assets_path'))) }}" rel="stylesheet" onerror="alert('app.css failed to load. Please refresh the page, re-publish Log Viewer assets, or fix routing for vendor assets.')">
    {{-- AVDBCMS: the package's page (opcodesio/log-viewer v3.21.1) unchanged but for this block; tests/Feature/AdminRound3FixesTest
         checks it stays equal to the package's template apart from it. In light mode the small grey words read 4.4:1
         on the page's grey and the search box's edge 1.4:1: one shade darker each (7:1; 4.4:1). In dark mode the search
         box's edge read 2.3:1: one shade lighter (3.7:1); the rest of dark mode is the package's own (lane AD, pass 2). --}}
    <style id="avd-log-viewer-contrast">
        html:not(.dark) .text-gray-500 { color: rgb(82 82 91); }
        html:not(.dark) .search { border-color: rgb(113 113 122); }
        html.dark .search { border-color: rgb(113 113 122); }
    </style>
</head>

<body class="h-full px-3 lg:px-5 bg-gray-100 dark:bg-gray-900">
<div id="log-viewer" class="flex h-full max-h-screen max-w-full">
    <router-view></router-view>
</div>

<!-- Global LogViewer Object -->
<script>
    window.LogViewer = @json($logViewerScriptVariables);

    // Add additional headers for LogViewer requests like so:
    // window.LogViewer.headers['Authorization'] = 'Bearer xxxxxxx';
</script>
<script src="{{ asset(mix('app.js', config('log-viewer.assets_path'))) }}" onerror="alert('app.js failed to load. Please refresh the page, re-publish Log Viewer assets, or fix routing for vendor assets.')"></script>
</body>
</html>
