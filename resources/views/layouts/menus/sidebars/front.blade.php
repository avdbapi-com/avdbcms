@php
    // sidebar_style: full | rail | floating pin a column on wide screens; hidden keeps only the drawer (the header carries the links).
    $sidebarStyle = \App\Support\ThemeStructure::sidebarStyle();
    $sidebarPinned = \App\Support\ThemeStructure::sidebarPinned();
@endphp
<div @class(["offcanvas offcanvas-start fixed overflow-auto avd-sidebar", 'avd-sidebar--'.$sidebarStyle, 'show_sidebar' => $sidebarPinned]) id="responsive-sidebar" data-avd-sidebar tabindex="-1" data-bs-keyboard="true" data-bs-backdrop="true" data-bs-scroll="false" aria-labelledby="responsive-sidebar-title" aria-modal="true" role="dialog">
    <div @class(["offcanvas-header align-items-center border-bottom py-2", 'd-md-none' => $sidebarPinned])>
        <a id="responsive-sidebar-title" href="{{ locale_url(route('pages.home')) }}" class="offcanvas-title text-decoration-none d-flex align-items-center gap-2 h5 mb-0 avd-brand">
            <span class="avd-brand-mark"><i class="fa-solid fa-play" aria-hidden="true"></i></span>
            <span>{{config('app.name')}}</span>
            @if(Auth::user()?->is_premium)
                <span class="avd-premium-chip">{{__('Premium')}}</span>
            @endif
        </a>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{__('Close')}}"></button>
        </div>
    </div>

    <div class="offcanvas-body px-0 py-0">
        <nav class="flex-column flex-shrink-0 d-flex overflow-auto h-100 position-relative avd-sidebar-body">
            @php
                $sidebarStaticMenuEnabled = config('site.sidebar_static_menu_enabled', true);
                $sidebarPrimaryLinks = \App\Support\NavigationSettings::parseMenuLinks(config('site.sidebar_primary_links'), 20);
                $sidebarAuthLinks = \App\Support\NavigationSettings::parseMenuLinks(config('site.sidebar_auth_links'), 20);
                $renderSidebarLink = function (array $menu): string {
                    $targetUrl = \App\Support\ThemeStructure::menuHref((string) ($menu['url'] ?? '#'));
                    $isActive = \App\Support\ThemeStructure::menuLinkIsCurrent($targetUrl);
                    $class = 'nav-link avd-sidebar-link' . ($isActive ? ' menu-selected' : '');
                    $target = ($menu['external'] ?? false) || ($menu['new_tab'] ?? false) ? ' target="_blank" rel="noopener"' : '';
                    $aria = $isActive ? ' aria-current="page"' : '';
                    $icon = e($menu['icon'] ?? 'circle');
                    $label = e(__($menu['label'] ?? 'Menu'));

                    return '<li class="nav-item"><a class="' . $class . '" href="' . e($targetUrl) . '"' . $target . $aria . '><i class="fa-solid fa-' . $icon . '" aria-hidden="true"></i><span class="text-sm">' . $label . '</span></a></li>';
                };
                $sidebarPlaylistsLimit = max(1, min(50, (int) config('site.sidebar_playlists_limit', 5)));
                $sidebarSubscriptionsLimit = max(1, min(50, (int) config('site.sidebar_subscriptions_limit', 5)));
                $sidebarExploreLimit = max(0, min(100, (int) config('site.sidebar_explore_limit', 24)));
                $sidebarShowLabel = trim((string) config('site.sidebar_more_label', 'Show')) ?: 'Show';
                $sidebarLessLabel = trim((string) config('site.sidebar_less_label', 'Show less')) ?: 'Show less';
            @endphp
            @if($sidebarStaticMenuEnabled && count($sidebarPrimaryLinks) > 0)
                <div class="avd-sidebar-section avd-sidebar-section--primary">
                    <ul class="nav nav-pills flex-column">
                        @foreach($sidebarPrimaryLinks as $menu)
                            {!! $renderSidebarLink($menu) !!}
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($sidebarStaticMenuEnabled && Auth::check() && count($sidebarAuthLinks) > 0)
                <div class="avd-sidebar-section avd-sidebar-section--auth">
                    <ul class="nav nav-pills flex-column">
                        @foreach($sidebarAuthLinks as $menu)
                            {!! $renderSidebarLink($menu) !!}
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(! $sidebarStaticMenuEnabled)
                @foreach(config('menu.front.top') as $submenu)
                    @unless($loop->index === 1 && !Auth::check())
                    <div class="avd-sidebar-section avd-sidebar-section--menu">
                        <ul class="nav nav-pills flex-column">
                            @foreach($submenu as $menu)
                                @continue((($menu['route'] ?? '') === 'pages.trend' && !config('site.show_trending', true)) || (($menu['route'] ?? '') === 'pages.history' && !config('site.show_history', true)))
                                <x-sidebar-item route="{{route($menu['route'], $menu['params'] ?? [])}}" newTab="{{$menu['new_tab'] ?? false}}">
                                    <i class="fa-solid fa-{{$menu['icon']}}" aria-hidden="true"></i>
                                    <span class="text-sm">{{ __($menu['title']) }}</span>
                                </x-sidebar-item>
                            @endforeach
                        </ul>
                    </div>
                    @endunless
                @endforeach
            @endif

            @if(config('site.sidebar_playlists_enabled', true) && Auth::check() && $favorite_playlists->count())
                @php
                    $displayPlaylistsCount = (int) ($favorite_playlists_count ?? $favorite_playlists->count());
                @endphp
                <div class="avd-sidebar-section avd-sidebar-section--playlists">
                    <div class="avd-sidebar-heading">
                        <span>{{ __(trim((string) config('site.sidebar_playlists_title', 'Playlists')) ?: 'Playlists') }} ({{$displayPlaylistsCount}})</span>
                        <a class="text-decoration-none text-primary" href="{{ locale_url(route('playlist.manage')) }}">{{__('See')}}</a>
                    </div>
                    <ul class="nav nav-pills flex-column" x-data="{ open: false }">
                        @foreach($favorite_playlists->slice(0, $sidebarPlaylistsLimit) as $playlist)
                            <x-sidebar-item route="{{$playlist->route}}" class="justify-content-between">
                                <span class="d-flex align-items-center gap-3 min-w-0">
                                    <i class="fa-solid fa-list" aria-hidden="true"></i>
                                    <span class="text-sm text-nowrap overflow-hidden text-truncate">{{Str::limit($playlist->title, 24)}}</span>
                                </span>
                            </x-sidebar-item>
                        @endforeach
                        @if($favorite_playlists->count() > $sidebarPlaylistsLimit)
                            <li class="nav-item">
                                <button @click="open = true" class="avd-sidebar-link w-100 bg-transparent border-0" x-show.important="!open" x-cloak type="button">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    <small>{{ __($sidebarShowLabel) }} {{ $favorite_playlists->count() - $sidebarPlaylistsLimit }} {{ __('more') }}</small>
                                </button>
                            </li>
                            <div x-show="open" x-cloak>
                                @foreach($favorite_playlists->slice($sidebarPlaylistsLimit) as $playlist)
                                    <x-sidebar-item route="{{$playlist->route}}">
                                        <i class="fa-solid fa-list" aria-hidden="true"></i>
                                        <span class="text-sm text-nowrap overflow-hidden text-truncate">{{Str::limit($playlist->title, 24)}}</span>
                                    </x-sidebar-item>
                                @endforeach
                                <li class="nav-item">
                                    <button @click="open = false" class="avd-sidebar-link w-100 bg-transparent border-0" type="button">
                                        <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                                        <small>{{ __($sidebarLessLabel) }}</small>
                                    </button>
                                </li>
                            </div>
                        @endif
                    </ul>
                </div>
            @endif

            @if(config('site.sidebar_subscriptions_enabled', true))
            <div class="avd-sidebar-section avd-sidebar-section--subscriptions">
                @if(Auth::check() && $subscriptions->count())
                    @php
                        $displaySubsCount = (int) ($subscriptions_count ?? $subscriptions->count());
                    @endphp
                    <div class="avd-sidebar-heading">
                        <span>{{ __(trim((string) config('site.sidebar_subscriptions_title', 'Subscriptions')) ?: 'Subscriptions') }} ({{$displaySubsCount}})</span>
                        <a class="text-decoration-none text-primary" href="{{ locale_url(route('subscription.manage')) }}">{{__('See')}}</a>
                    </div>
                    <ul class="nav nav-pills flex-column" x-data="{ open: false }">
                        @foreach($subscriptions->slice(0, $sidebarSubscriptionsLimit) as $user)
                            <x-sidebar-item route="{{$user->route}}" class="justify-content-between">
                                <span class="d-flex align-items-center gap-3 min-w-0">
                                    <img style="width: 24px;height:24px" class="rounded-circle flex-shrink-0" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar">
                                    <span class="text-sm text-nowrap overflow-hidden text-truncate">{{Str::limit($user->username, 24)}}</span>
                                </span>
                                @if($user->has_new_video ?? $user->new_videos ?? false)
                                    <span class="avd-sidebar-dot"></span>
                                @endif
                            </x-sidebar-item>
                        @endforeach
                        @if($subscriptions->count() > $sidebarSubscriptionsLimit)
                            <li class="nav-item">
                                <button @click="open = true" class="avd-sidebar-link w-100 bg-transparent border-0" x-show.important="!open" x-cloak type="button">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    <small>{{ __($sidebarShowLabel) }} {{ $subscriptions->count() - $sidebarSubscriptionsLimit }} {{ __('more') }}</small>
                                </button>
                            </li>
                            <div x-show="open" x-cloak>
                                @foreach($subscriptions->slice($sidebarSubscriptionsLimit) as $user)
                                    <x-sidebar-item route="{{$user->route}}" class="justify-content-between">
                                        <span class="d-flex align-items-center gap-3 min-w-0">
                                            <img style="width: 24px;height:24px" class="rounded-circle flex-shrink-0" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar">
                                            <span class="text-sm text-nowrap overflow-hidden text-truncate">{{Str::limit($user->username, 24)}}</span>
                                        </span>
                                        @if($user->has_new_video ?? $user->new_videos ?? false)
                                            <span class="avd-sidebar-dot"></span>
                                        @endif
                                    </x-sidebar-item>
                                @endforeach
                                <li class="nav-item">
                                    <button @click="open = false" class="avd-sidebar-link w-100 bg-transparent border-0" type="button">
                                        <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                                        <small>{{ __($sidebarLessLabel) }}</small>
                                    </button>
                                </li>
                            </div>
                        @endif
                        @if(config('site.sidebar_discover_channels_enabled', true))
                        <x-sidebar-item route="{{route('subscription.discover')}}">
                            <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                            <span class="text-sm">{{ __(trim((string) config('site.sidebar_discover_channels_label', 'Discover channels')) ?: 'Discover channels') }}</span>
                        </x-sidebar-item>
                        @endif
                    </ul>
                @else
                    @if(config('site.sidebar_discover_channels_enabled', true))
                    <ul class="nav nav-pills flex-column">
                        <x-sidebar-item route="{{route('subscription.discover')}}">
                            <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                            <span class="text-sm">{{ __(trim((string) config('site.sidebar_discover_channels_label', 'Discover channels')) ?: 'Discover channels') }}</span>
                        </x-sidebar-item>
                    </ul>
                    @endif
                @endif
            </div>
            @endif

            @if(config('site.sidebar_explore_enabled', true))
            <div class="avd-sidebar-section avd-sidebar-section--explore">
                <div class="avd-sidebar-heading">{{ __(trim((string) config('site.sidebar_explore_title', 'Explore')) ?: 'Explore') }}</div>
                <ul class="nav nav-pills flex-column">
                    @foreach($categories->take($sidebarExploreLimit) as $category)
                        <x-sidebar-item route="{{route('pages.category', $category->slug)}}" class="avd-nav-cat">
                            <i class="fa-solid fa-{{$category->icon_name}}" aria-hidden="true"></i>
                            <span class="text-sm">{{__($category->title)}}</span>
                        </x-sidebar-item>
                    @endforeach
                    @if($categories->count() > $sidebarExploreLimit)
                        <x-sidebar-item route="{{route('pages.categories')}}">
                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                            <span class="text-sm">{{__('All Categories')}}</span>
                        </x-sidebar-item>
                    @endif
                </ul>
            </div>
            @endif

            @if(config('site.sidebar_premium_link_enabled', true) && !Auth::user()?->is_premium)
                <div class="avd-sidebar-section avd-sidebar-section--premium">
                    <ul class="nav nav-pills flex-column">
                        <x-sidebar-item route="{{route('pages.premium')}}" class="fw-bold">
                            <i class="fa-solid fa-star" aria-hidden="true"></i>
                            <span class="text-sm">{{ __(\App\Support\NavigationSettings::siteLabel(trim((string) config('site.sidebar_premium_label', '{site_name} Premium')) ?: '{site_name} Premium')) }}</span>
                        </x-sidebar-item>
                    </ul>
                </div>
            @endif

            @php
                $currentLocale = App::currentLocale();
                $currentLanguage = config("languages.$currentLocale", ['label' => Str::upper($currentLocale)]);
                $showSidebarLocale = config('site.allow_language_switch', true) && config('site.sidebar_locale_switcher_enabled', true);
                $showSidebarTheme = config('site.sidebar_theme_switcher_enabled', true);
                $showSidebarControls = $showSidebarLocale || $showSidebarTheme;
            @endphp
            @if($showSidebarControls)
            <div class="avd-sidebar-footer mt-auto">
                @if($showSidebarLocale || $showSidebarTheme)
                <div class="avd-sidebar-controls">
                    @if($showSidebarLocale)
                    <div class="dropdown dropup avd-locale-picker">
                        <button class="avd-locale-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-label="{{__('Locale')}}">
                            <span class="avd-locale-code">{{Str::upper($currentLocale)}}</span>
                            <span class="avd-locale-label">{{$currentLanguage['label'] ?? $currentLocale}}</span>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu avd-locale-menu">
                            @foreach(config('languages') as $lang => $info)
                                @php($label = is_array($info) ? ($info['label'] ?? Str::upper($lang)) : $info)
                                <a href="{{ locale_switch_url($lang) }}" @class(['dropdown-item avd-locale-option', 'is-active' => $currentLocale === $lang]) @if($currentLocale === $lang) aria-current="true" @endif>
                                    <span class="avd-locale-option-code">{{Str::upper($lang)}}</span>
                                    <span class="avd-locale-option-label">{{$label}}</span>
                                    @if($currentLocale === $lang)
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    @if($showSidebarTheme)
                    <theme-switch id="theme-switcher-global"/>
                    @endif
                </div>
                @endif
            </div>
            @endif
        </nav>
    </div>
</div>
