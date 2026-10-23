@php
    // Structure: header_style decides the bar (solid, slim, glass, island, two-row, transparent over a hero),
    // the theme's header deco adds its piece (masthead, crest...). See App\Support\ThemeStructure.
    $structure = \App\Support\ThemeStructure::resolve();
    $headerStyle = \App\Support\ThemeStructure::headerStyle();
    $headerDeco = $structure['header_deco'];
    // Back-office pages (account, v1 admin) keep their always-pinned menu and the v1 rule.
    $sidebarPinned = ($isBackOfficeLayout ?? false) ? $show_sidebar : \App\Support\ThemeStructure::sidebarPinned();
    $twoRow = $headerStyle === 'full_megabar' && ! ($isBackOfficeLayout ?? false);
    $searchIcon = in_array($headerStyle, ['glass_island', 'transparent_overlay'], true) || in_array($headerDeco, ['masthead', 'crest'], true);
    $headerLink = function (string $label, string $url, bool $newTab = false): array {
        $href = \App\Support\ThemeStructure::menuHref($url);

        return ['label' => $label, 'url' => $href, 'new_tab' => $newTab, 'active' => \App\Support\ThemeStructure::menuLinkIsCurrent($href)];
    };
    $headerLinks = [];
    if ((\App\Support\ThemeStructure::headerLinksVisible() && ! ($isBackOfficeLayout ?? false)) || $twoRow) {
        foreach (\App\Support\NavigationSettings::parseMenuLinks(config('site.sidebar_primary_links'), 6) as $menu) {
            $headerLinks[] = $headerLink(__($menu['label']), $menu['url'], $menu['external'] || $menu['new_tab']);
        }
        if (config('site.sidebar_explore_enabled', true) && ! $twoRow) {
            $headerLinks[] = $headerLink(__('Categories'), locale_url(route('pages.categories')));
        }
    }
    if ($twoRow && config('site.sidebar_explore_enabled', true)) {
        try {
            $headerCategories = \Illuminate\Support\Facades\Cache::rememberForever('categories', fn () => \App\Models\Category::query()->where('in_menu', true)->ordered()->get());
        } catch (\Throwable) {
            $headerCategories = collect();
        }
        foreach ($headerCategories->take(max(0, 9 - count($headerLinks))) as $category) {
            $headerLinks[] = $headerLink(__($category->title), locale_url(route('pages.category', $category->slug)));
        }
    }
@endphp
<nav @class([
    'navbar avd-header header px-2',
    'avd-header--'.$headerStyle,
    'sticky-top' => ($cssStickyHeaderEnabled ?? true) && $headerStyle !== 'transparent_overlay',
    'navbar-expand-md' => $sidebarPinned,
]) x-data="{search:false}" data-avd-header>
    <div class="container-fluid avd-header-inner" x-show.important="!search" x-cloak>
        {{-- Every bar is two blocks (start, actions): below 992px the header is a two-column grid (header.scss). --}}
        @if($headerDeco === 'masthead')
            {{-- Newspaper masthead: date line, centred title, actions; the sections follow in the second row. --}}
            <div class="avd-header-start d-flex align-items-center gap-2 flex-grow-1 min-w-0">
                @include('layouts.menus.headers.toggle')
                <span class="avd-dateline d-none d-lg-block">
                    <time datetime="{{ now()->toDateString() }}">{{ now()->translatedFormat('l, j F Y') }}</time><br>
                    {{ __("Today's edition") }}
                </span>
                @include('layouts.menus.headers.brand', ['brandClass' => 'avd-masthead-title', 'brandZoneClass' => 'mx-lg-auto', 'withToggle' => false])
            </div>
            @include('layouts.menus.headers.actions')
        @elseif($headerDeco === 'crest')
            {{-- Centred crest: links on one side, the brand in the middle, actions on the other. --}}
            <div class="avd-header-start d-flex align-items-center gap-2 flex-grow-1 min-w-0">
                @include('layouts.menus.headers.toggle')
                @include('layouts.menus.headers.nav', ['links' => $headerLinks, 'navClass' => 'avd-hnav d-none d-lg-flex'])
                @include('layouts.menus.headers.brand', ['brandClass' => 'avd-brand--crest', 'brandZoneClass' => 'mx-lg-auto', 'withToggle' => false])
            </div>
            @include('layouts.menus.headers.actions')
        @else
            @include('layouts.menus.headers.brand', ['brandClass' => $headerDeco === 'terminal' ? 'avd-brand--prompt' : ''])
            @if(! $twoRow)
                @include('layouts.menus.headers.nav', ['links' => $headerLinks, 'navClass' => 'avd-hnav d-none d-lg-flex'])
            @endif
            @if(config('site.header_search_enabled', true) && ! $searchIcon)
                <search-bar class="col-6 col-xl-5 col-xxl-4 d-none d-lg-block" searchurl="{{ locale_url(route('search.index')) }}" responsive></search-bar>
            @endif
            @include('layouts.menus.headers.actions')
            @if($headerDeco === 'titlebar')
                <span class="avd-win-btns d-none d-lg-flex" aria-hidden="true"><i></i><i></i><i></i></span>
            @endif
        @endif
    </div>

    @if($twoRow)
        @include('layouts.menus.headers.nav', ['links' => $headerLinks, 'navLabel' => 'Sections', 'navClass' => 'avd-hrow2 container-fluid d-none d-lg-flex'])
    @endif

    @if(config('site.header_search_enabled', true))
        <div id="mobile-search-panel" class="container-fluid avd-mobile-search gap-2" role="search" aria-label="{{__('Search')}}" x-show.important="search" x-cloak>
            <button id="mobile-search-close" @click="search = false" class="avd-icon-button flex-shrink-0" type="button" aria-controls="mobile-search-panel" aria-label="{{__('Close search')}}">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            </button>
            <search-bar id="mobile-search-bar" class="w-100" searchurl="{{ locale_url(route('search.index')) }}"></search-bar>
        </div>
    @endif
</nav>
