@php
    // sidebar_style: full | rail | floating pin a column on wide screens; hidden keeps only the drawer (the header carries the links).
    $sidebarStyle = \App\Support\ThemeStructure::sidebarStyle();
    $sidebarPinned = \App\Support\ThemeStructure::sidebarPinned();
@endphp
{{-- A pinned menu is part of the page, not a dialog (watch-30): Bootstrap gives the drawer role="dialog" and aria-modal
     while it is open, and takes them off again when it closes. --}}
<div @class(["offcanvas offcanvas-start fixed overflow-auto avd-sidebar", 'avd-sidebar--'.$sidebarStyle, 'show_sidebar' => $sidebarPinned]) id="responsive-sidebar" data-avd-sidebar tabindex="-1" data-bs-keyboard="true" data-bs-backdrop="true" data-bs-scroll="false" aria-labelledby="responsive-sidebar-title">
    <div @class(["offcanvas-header align-items-center border-bottom py-2", 'd-md-none' => $sidebarPinned])>
        <a id="responsive-sidebar-title" href="{{ locale_url(route('pages.home')) }}" class="offcanvas-title text-decoration-none d-flex align-items-center gap-2 h5 mb-0 avd-brand">
            <span class="avd-brand-mark"><i class="fa-solid fa-play" aria-hidden="true"></i></span>
            <span class="avd-drawer-brand-name">{{config('app.name')}}</span>
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
                // R1-034: the members' links start with Library (/library: History, Watch later, Liked videos, playlists and
                // subscriptions on one page; tablets and computers had no way to it), unless the owner's links open it.
                $sidebarLibraryPath = rtrim(site_path((string) parse_url(route('pages.library'), PHP_URL_PATH)), '/');
                if (Auth::check() && ! collect($sidebarAuthLinks)->contains(fn (array $menu): bool => rtrim((string) parse_url((string) ($menu['url'] ?? ''), PHP_URL_PATH), '/') === $sidebarLibraryPath)) {
                    array_unshift($sidebarAuthLinks, ['label' => 'Library', 'url' => $sidebarLibraryPath, 'icon' => 'folder-open', 'external' => false, 'new_tab' => false]);
                }
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
                // "Show 3 more" as one sentence (channel-32: three translated pieces broke the word order in other
                // languages); an owner's own label keeps its words, followed by the number.
                $sidebarMoreText = fn (int $count): string => $sidebarShowLabel === 'Show'
                    ? __('Show :count more', ['count' => $count])
                    : __($sidebarShowLabel).' '.$count;
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
                            @if($loop->index === 1 && Auth::check() && ! collect($submenu)->contains(fn ($menu) => ($menu['route'] ?? '') === 'pages.library'))
                                {{-- R1-034: the members' group starts with Library, as in the static menu above --}}
                                <x-sidebar-item route="{{ route('pages.library') }}">
                                    <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                                    <span class="text-sm">{{ __('Library') }}</span>
                                </x-sidebar-item>
                            @endif
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
                <div class="avd-sidebar-section avd-sidebar-section--playlists" x-data="{ open: false }">
                    {{-- The heading and its link never touch (channel-32): the name truncates, the count keeps its line
                         (R1-036: the count was inside the part that truncated), and the link is a 44px target named for
                         what it opens (watch-46). --}}
                    <div class="avd-sidebar-heading">
                        <span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">{{ __(trim((string) config('site.sidebar_playlists_title', 'Playlists')) ?: 'Playlists') }}</span> <span class="avd-sidebar-heading__count">({{$displayPlaylistsCount}})</span></span>
                        <a class="avd-sidebar-heading__link" href="{{ locale_url(route('playlist.manage')) }}" aria-label="{{ __('See all: :name', ['name' => __(trim((string) config('site.sidebar_playlists_title', 'Playlists')) ?: 'Playlists')]) }}">{{ __('See all') }}</a>
                    </div>
                    <ul class="nav nav-pills flex-column">
                        @foreach($favorite_playlists->slice(0, $sidebarPlaylistsLimit) as $playlist)
                            <x-sidebar-item route="{{$playlist->route}}" class="justify-content-between">
                                <span class="d-flex align-items-center gap-3 min-w-0">
                                    <i class="fa-solid fa-list" aria-hidden="true"></i>
                                    <span class="text-sm text-nowrap overflow-hidden text-truncate"{!! \App\Support\TextDirection::attr($playlist->title) !!}>{{Str::limit($playlist->title, 24)}}</span>
                                </span>
                            </x-sidebar-item>
                        @endforeach
                        @if($favorite_playlists->count() > $sidebarPlaylistsLimit)
                            <li class="nav-item" x-show.important="!open">
                                <button @click="open = true" class="avd-sidebar-link w-100 bg-transparent border-0" type="button" aria-expanded="false" :aria-expanded="open.toString()">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    <small>{{ $sidebarMoreText($favorite_playlists->count() - $sidebarPlaylistsLimit) }}</small>
                                </button>
                            </li>
                        @endif
                    </ul>
                    @if($favorite_playlists->count() > $sidebarPlaylistsLimit)
                        {{-- The rest of the list: its own list, shown by the button (a <div> may not sit inside a <ul>, watch-30). --}}
                        <ul class="nav nav-pills flex-column" x-show="open" x-cloak>
                            @foreach($favorite_playlists->slice($sidebarPlaylistsLimit) as $playlist)
                                <x-sidebar-item route="{{$playlist->route}}">
                                    <i class="fa-solid fa-list" aria-hidden="true"></i>
                                    <span class="text-sm text-nowrap overflow-hidden text-truncate"{!! \App\Support\TextDirection::attr($playlist->title) !!}>{{Str::limit($playlist->title, 24)}}</span>
                                </x-sidebar-item>
                            @endforeach
                            <li class="nav-item">
                                <button @click="open = false" class="avd-sidebar-link w-100 bg-transparent border-0" type="button">
                                    <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                                    <small>{{ __($sidebarLessLabel) }}</small>
                                </button>
                            </li>
                        </ul>
                    @endif
                </div>
            @endif

            @if(config('site.sidebar_subscriptions_enabled', true))
            <div class="avd-sidebar-section avd-sidebar-section--subscriptions" x-data="{ open: false }">
                @if(Auth::check() && $subscriptions->count())
                    @php
                        $displaySubsCount = (int) ($subscriptions_count ?? $subscriptions->count());
                    @endphp
                    <div class="avd-sidebar-heading">
                        <span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">{{ __(trim((string) config('site.sidebar_subscriptions_title', 'Subscriptions')) ?: 'Subscriptions') }}</span> <span class="avd-sidebar-heading__count">({{$displaySubsCount}})</span></span>
                        <a class="avd-sidebar-heading__link" href="{{ locale_url(route('subscription.manage')) }}" aria-label="{{ __('See all: :name', ['name' => __(trim((string) config('site.sidebar_subscriptions_title', 'Subscriptions')) ?: 'Subscriptions')]) }}">{{ __('See all') }}</a>
                    </div>
                    <ul class="nav nav-pills flex-column">
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
                            <li class="nav-item" x-show.important="!open">
                                <button @click="open = true" class="avd-sidebar-link w-100 bg-transparent border-0" type="button" aria-expanded="false" :aria-expanded="open.toString()">
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    <small>{{ $sidebarMoreText($subscriptions->count() - $sidebarSubscriptionsLimit) }}</small>
                                </button>
                            </li>
                            {{-- The rest of the list as list items (a <div> may not sit inside a <ul>, watch-30). --}}
                            @foreach($subscriptions->slice($sidebarSubscriptionsLimit) as $user)
                                @php
                                    // The same item as <x-sidebar-item>, with its <li> hidden until "Show more" (the component puts
                                    // its attributes on the link).
                                    $restRoute = (string) $user->route;
                                    $restActive = str_ends_with(rtrim(URL::current(), '/'), rtrim(parse_url($restRoute, PHP_URL_PATH) ?? $restRoute, '/'));
                                @endphp
                                <li class="nav-item" x-show="open" x-cloak>
                                    <a @class(['nav-link avd-sidebar-link justify-content-between', 'menu-selected' => $restActive]) href="{{ locale_url($restRoute) }}" @if($restActive) aria-current="page" @endif>
                                        <span class="d-flex align-items-center gap-3 min-w-0">
                                            <img style="width: 24px;height:24px" class="rounded-circle flex-shrink-0" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar" loading="lazy" decoding="async">
                                            <span class="text-sm text-nowrap overflow-hidden text-truncate">{{Str::limit($user->username, 24)}}</span>
                                        </span>
                                        @if($user->has_new_video ?? $user->new_videos ?? false)
                                            <span class="avd-sidebar-dot"></span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                            <li class="nav-item" x-show="open" x-cloak>
                                <button @click="open = false" class="avd-sidebar-link w-100 bg-transparent border-0" type="button">
                                    <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                                    <small>{{ __($sidebarLessLabel) }}</small>
                                </button>
                            </li>
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
                {{-- The title box like the headings above (R2A-024: as bare text the heading's flex-end pushed it to the
                     end of its line) --}}
                <div class="avd-sidebar-heading"><span class="avd-sidebar-heading__title"><span class="avd-sidebar-heading__name">{{ __(trim((string) config('site.sidebar_explore_title', 'Explore')) ?: 'Explore') }}</span></span></div>
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
                            <span class="text-sm">{{__('All categories')}}</span>
                        </x-sidebar-item>
                    @endif
                </ul>
            </div>
            @endif

            @if(config('site.sidebar_premium_link_enabled', true) && !Auth::user()?->is_premium)
                @php
                    // The owner's label ("{site_name} Premium" by default). The icon rail has room for a word or two: it
                    // shows the label without the site's name ("Premium"); the full label stays the link's name and its
                    // tooltip (watch-19: the rail cut it to "Sandbox Site...", losing the one word that says what it is).
                    // Translated before the site's name goes in (R2A-043: "Sandbox Site Premium" in every language).
                    $premiumLabelSetting = trim((string) config('site.sidebar_premium_label', '{site_name} Premium')) ?: '{site_name} Premium';
                    $premiumLabel = \App\Support\NavigationSettings::translatedSiteLabel($premiumLabelSetting);
                    $premiumRailLabel = trim((string) preg_replace('/\s+/u', ' ', str_replace('{site_name}', '', $premiumLabelSetting)));
                    $premiumRailLabel = $premiumRailLabel !== '' ? \App\Support\NavigationSettings::translatedSiteLabel($premiumRailLabel) : __('Premium');
                @endphp
                <div class="avd-sidebar-section avd-sidebar-section--premium">
                    <ul class="nav nav-pills flex-column">
                        <x-sidebar-item route="{{route('pages.premium')}}" class="fw-bold" :title="$sidebarStyle === 'rail' && $premiumRailLabel !== $premiumLabel ? $premiumLabel : null">
                            <i class="fa-solid fa-star" aria-hidden="true"></i>
                            <span @class(['text-sm', 'avd-sidebar-label-full' => $premiumRailLabel !== $premiumLabel])>{{ $premiumLabel }}</span>
                            @if($premiumRailLabel !== $premiumLabel)
                                <span class="text-sm avd-sidebar-label-rail" aria-hidden="true">{{ $premiumRailLabel }}</span>
                            @endif
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
                        <button class="avd-locale-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-label="{{ __('Change language') }}">
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
                    <theme-switch id="theme-switcher-global" labelled></theme-switch>
                    @endif
                </div>
                @endif
            </div>
            @endif
        </nav>
    </div>
</div>
