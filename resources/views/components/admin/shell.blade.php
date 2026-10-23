{{--
    The redesigned admin document (layouts/admin.blade.php renders it when the new admin is on).
    Its own assets only: no public header, footer, theme CSS, theme fonts, effects tier or public scripts.
    Props: page (nav page id override), width (form|wide), legacy (true/false: an old view - its scripts and its
    compatibility styles; default: detected from the page).
--}}
@use('App\Support\AdminNav')
@use('App\Support\AdminPreferences')
@use('App\Support\AdminTheme')
@props(['page' => null, 'width' => null, 'legacy' => null])
@php
    $request = request();
    $user = auth()->user();

    if (is_string($page) && $page !== '') {
        $request->attributes->set('admin.page', $page);
    }

    $content = $__env->yieldContent('content');
    $scripts = $__env->yieldPushContent('scripts');
    $current = AdminNav::current($request);
    $sidebar = AdminNav::sidebar($user, $current);
    $scheme = AdminTheme::colorScheme($user);
    $routeName = (string) $request->route()?->getName();

    // The old views use Bootstrap's scripts, Alpine, confirm() and enhanced selects: their scripts load only there.
    $oldScripts = $legacy ?? (bool) preg_match('/data-bs-|x-data|bootstrap\.|confirm\(|select-multiple|ajax-button|categories_list|class="[^"]*\bmodal\b/', $content.$scripts);
    $iconsAll = \Illuminate\Support\Str::is(['admin.categories.*'], $routeName);
    $width = in_array($width, ['form', 'wide'], true) ? $width : null;

    // @section('title', __('...')) stores the title escaped already ('&' -> '&amp;'): decode it once, it is escaped
    // again where it is printed.
    $pageTitle = html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: ($current !== null ? __($current['label']) : __('Admin'));
    $siteName = (string) config('app.name');
    $siteHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    $logoUrl = (string) config('site.logo_url', '');
    $faviconUrl = (string) config('site.favicon_url', '');
    $status = AdminNav::siteStatus();
    $prefs = AdminPreferences::all($user);
    $flashSuccess = session('success');
    $headerRendered = $request->attributes->get('admin.header') === true;
    // The shell's own markup goes out without the template's indentation and with same-site links as paths (the
    // sidebar alone has 60+ links); the page's content, pushed head tags and scripts go out untouched.
    $origin = $request->getSchemeAndHttpHost();
    $compact = static fn (string $html): string => str_replace(' href="'.$origin.'/', ' href="/', (string) preg_replace('/\n\s+/', "\n", $html));
    // Old views (pages that do not draw their own <x-admin.page-header>) get the compatibility styles of
    // components/_legacy.scss, also when they need no script.
    $oldView = $legacy ?? ($oldScripts || ! $headerRendered);
    $previousGroup = null;
@endphp
@php(ob_start())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ AdminTheme::direction() }}" data-admin-ui="v2" data-bs-theme="{{ $scheme === 'dark' ? 'dark' : 'light' }}"@if($scheme !== 'system') data-theme="{{ $scheme }}"@endif>
<head>
    <meta charset="utf-8">
    @if ($scheme === 'system')
        {{-- The old views style themselves with [data-bs-theme]: follow the device before the first paint. --}}
        <script>if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.setAttribute('data-bs-theme', 'dark');</script>
    @endif
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>{{ $pageTitle }} · {{ __('Admin') }} · {{ $siteName }}</title>
    <link rel="icon" href="{{ $faviconUrl !== '' ? site_asset_url($faviconUrl) : asset('/icons/favicon.ico') }}" sizes="any">
    {{ AdminTheme::styleTag($user) }}
    {{-- Category pages show icons the owner picked from all of Font Awesome: that stylesheet comes after the subset,
         so its font face wins and the subset font is never downloaded there. --}}
    @vite(array_values(array_filter(['resources/admin/admin.scss', $iconsAll ? 'resources/admin/lib/fa-all.scss' : null, $oldScripts ? 'resources/admin/modules/legacy.ts' : null, 'resources/admin/admin.ts'])))
    <script type="speculationrules">{"prefetch":[{"where":{"selector_matches":".ad-nav a[href], .ad-tabs a[href]"},"eagerness":"moderate"}]}</script>
{!! $compact(ob_get_clean()) !!}
    @stack('head')
@php(ob_start())
</head>
<body class="ad-body" data-ad-legacy="{{ $oldScripts ? 'on' : 'off' }}"@if($iconsAll) data-ad-icons="all"@endif>
<a class="ad-skip" href="#ad-main">{{ __('Skip to content') }}</a>
<div class="ad-shell">
    <aside id="ad-side" class="ad-side" aria-label="{{ __('Admin menu') }}">
        <a class="ad-brand" href="{{ route('admin.index') }}">
            <span class="ad-brand-logo" aria-hidden="true">
                @if ($logoUrl !== '')
                    <img src="{{ site_asset_url($logoUrl) }}" alt="" width="28" height="28" loading="lazy" decoding="async">
                @else
                    {{ AdminTheme::icon('brand-play') }}
                @endif
            </span>
            <span class="ad-brand-name">{{ $siteName }}<small>{{ $siteHost }}</small></span>
        </a>
        <nav class="ad-nav" aria-label="{{ __('Admin') }}">
            @foreach ($sidebar as $section)
                @if ($previousGroup !== null && $previousGroup !== $section['group'])
                    <div class="ad-nav-sep" role="separator"></div>
                @endif
                @php($previousGroup = $section['group'])
                @php($badge = $section['badge'])
                <div @class(['ad-nav-group', 'is-open' => $section['open']])>
                    <div class="ad-nav-row">
                        <a href="{{ $section['url'] }}" @class([
                            'ad-nav-item',
                            'is-active' => $section['current'],
                            'is-open' => $section['active'],
                            'has-attn' => $badge !== null,
                            'has-warn' => ($badge['type'] ?? '') === 'warning',
                        ]) @if($section['current']) aria-current="page" @endif>
                            {{ AdminTheme::icon($section['icon']) }}
                            <span class="ad-label-t">{{ __($section['label']) }}</span>
                            @if (($badge['type'] ?? '') === 'attention')
                                <span class="ad-count is-attn">{{ $badge['count'] }}<span class="ad-vh"> {{ __($badge['label']) }}</span></span>
                            @elseif (($badge['type'] ?? '') === 'warning')
                                <span class="ad-dot-warn"><span class="ad-vh">{{ __($badge['label']) }}</span></span>
                            @elseif (($badge['type'] ?? '') === 'running')
                                <span class="ad-dot-run"><span class="ad-vh">{{ __($badge['label']) }}</span></span>
                            @endif
                        </a>
                        @if (! $section['single'])
                            <button type="button" class="ad-nav-toggle" data-ad-nav-toggle aria-controls="ad-nav-{{ $section['id'] }}" aria-expanded="{{ $section['open'] ? 'true' : 'false' }}">
                                {{ AdminTheme::icon('chev-right', 'flip-rtl') }}<span class="ad-vh">{{ __('Show the pages of :section', ['section' => __($section['label'])]) }}</span>
                            </button>
                        @endif
                    </div>
                    @if (! $section['single'])
                        {{-- Collapsed lists use a class, not [hidden]: the tablet rail shows them as flyouts on hover. --}}
                        <ul @class(['ad-nav-sub', 'is-collapsed' => ! $section['open']]) id="ad-nav-{{ $section['id'] }}">
                            <li class="ad-flyout-title" aria-hidden="true">{{ __($section['label']) }}</li>
                            @foreach ($section['pages'] as $item)
                                <li><a href="{{ $item['url'] }}"@if($item['current']) class="is-active" aria-current="page"@endif>{{ __($item['label']) }}</a></li>
                            @endforeach
                            @foreach ($section['more'] as $item)
                                <li data-ad-more-item hidden><a href="{{ $item['url'] }}">{{ __($item['label']) }}</a></li>
                            @endforeach
                            @if (count($section['more']) > 0)
                                <li><button type="button" class="ad-more" data-ad-nav-more>{{ trans_choice(':count more|:count more', count($section['more']), ['count' => count($section['more'])]) }}</button></li>
                            @endif
                        </ul>
                    @endif
                </div>
            @endforeach
        </nav>
        <div class="ad-side-foot">
            <a href="{{ route('admin.shell.moved') }}">{{ AdminTheme::icon('layers', 'sm') }}{{ __('What moved where') }}</a>
            <button type="button" class="ad-foot-search" data-ad-palette>{{ AdminTheme::icon('search', 'sm') }}{{ __('Search everything') }} <span class="ad-kbd" aria-hidden="true">/</span></button>
            <span>AVDBCMS</span>
        </div>
    </aside>
    <button type="button" class="ad-scrim-nav" data-ad-drawer-close tabindex="-1" aria-label="{{ __('Close menu') }}"></button>

    <div class="ad-main">
        <header class="ad-top">
            <button type="button" class="ad-iconbtn ad-top-menu" data-ad-drawer-open aria-controls="ad-side" aria-expanded="false" aria-label="{{ __('Open menu') }}">{{ AdminTheme::icon('menu') }}</button>
            <button type="button" class="ad-search" data-ad-palette aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K">
                {{ AdminTheme::icon('search', 'sm') }}
                <span class="ad-search-t">{{ __('Search pages, settings, members') }}</span>
                <span class="ad-kbd" aria-hidden="true">Ctrl</span><span class="ad-kbd" aria-hidden="true">K</span>
            </button>
            <span class="ad-spacer"></span>

            <x-admin.site-status :status="$status" />

            <a class="ad-btn is-ghost hide-phone" href="{{ locale_url(route('pages.home')) }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('View site') }}</a>

            <x-admin.account-menu :user="$user" :prefs="$prefs" />
        </header>

        <main id="ad-main" @class(['ad-content', 'is-form' => $width === 'form', 'is-wide' => $width === 'wide']) tabindex="-1">
            @unless ($headerRendered)
                <x-admin.page-header :page="$current" />
            @endunless

            @if (isset($errors) && $errors->any())
                <x-admin.callout type="danger" class="ad-flash" :title="$errors->first()" role="alert" />
            @endif
{!! $compact(ob_get_clean()) !!}
            @if ($oldView)
                <div class="ad-legacy">{!! $content !!}</div>
            @else
                {!! $content !!}
            @endif
            {{ $slot }}
@php(ob_start())
        </main>
    </div>
</div>

<x-admin.palette />
<x-admin.confirm />
<x-admin.toast :success="is_string($flashSuccess) ? $flashSuccess : null" />
@if ($oldScripts)
    @include('admin.modals.confirm-action')
@endif
<div id="ad-i18n" hidden
     data-undo="{{ __('Undo') }}" data-close="{{ __('Close') }}" data-cancel="{{ __('Cancel') }}" data-confirm="{{ __('Confirm') }}"
     data-confirm-title="{{ __('Are you sure you want to continue?') }}" data-saving="{{ __('Saving...') }}"
     data-one-change="{{ __('1 unsaved change') }}" data-many-changes="{{ __(':count unsaved changes') }}"
     data-pref-error="{{ __('Could not save this choice. Try again.') }}" data-pages="{{ __('Pages') }}" data-settings="{{ __('Settings') }}"
     data-members="{{ __('Members') }}" data-videos="{{ __('Videos') }}" data-more-options="{{ __('More options') }}"
     data-no-results="{{ __('Nothing found for ":query". Try another word.') }}"
     data-search-hint="{{ __('Type to search pages, settings, members and videos.') }}"
     data-contrast-good="{{ __('Text on this colour: :ratio:1 Good') }}" data-contrast-low="{{ __('Text on this colour: :ratio:1 Hard to read') }}"></div>
{!! $compact(ob_get_clean()) !!}
@if ($oldView && trim($scripts) !== '')
    {{-- Old views push markup (dialogs, toasts) with their scripts: it gets the same compatibility styles. --}}
    <div class="ad-legacy">{!! $scripts !!}</div>
@else
    {!! $scripts !!}
@endif
{{ AdminTheme::sprite() }}
</body>
</html>
