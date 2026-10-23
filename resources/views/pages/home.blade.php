@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
@endphp

@section('title', $seoService->renderTemplate('home_meta_title_template', [], __('Share and Watch Amazing Videos'), 180))
@section('description', $seoService->renderTemplate('home_meta_description_template', [], __('Enjoy the videos and music you love, upload original content, and share it all with friends, family, and the world on :site.', ['site' => config('app.name')]), 500))

@section('class', 'avd-feed-shell px-0 px-sm-3 mt-3')

@section('content')
    @php
        $homeIntroEnabled = config('site.home_intro_enabled', true);
        $homeCategoryTabsEnabled = config('site.home_category_tabs_enabled', true);
        $homeRailLinksEnabled = config('site.home_rail_links_enabled', true);
        $homeFallbackRailsEnabled = config('site.home_fallback_rails_enabled', true);
        $homeFeedSources = \App\Support\AppearanceSettings::homeFeedSources();
        $homeFeedLoadModes = \App\Support\AppearanceSettings::homeFeedLoadModes();
        $makeVideoFeedConfig = function (string $prefix, string $enabledKey, array $defaults, bool $limitByRows = true) use ($homeFeedSources, $homeFeedLoadModes): array {
            $enabledValue = config('site.' . $enabledKey, $defaults[$enabledKey] ?? '1');
            $enabled = is_bool($enabledValue) ? $enabledValue : (string) $enabledValue === '1';
            $source = (string) config('site.' . $prefix . '_source', $defaults[$prefix . '_source'] ?? 'newest');
            $source = array_key_exists($source, $homeFeedSources) ? $source : 'newest';
            $categoryId = max(0, (int) config('site.' . $prefix . '_category_id', $defaults[$prefix . '_category_id'] ?? 0));
            $columns = max(
                \App\Support\AppearanceSettings::HOME_FEED_MIN_COLUMNS,
                min(\App\Support\AppearanceSettings::HOME_FEED_MAX_COLUMNS, (int) config('site.' . $prefix . '_columns', $defaults[$prefix . '_columns'] ?? 4))
            );
            $rows = $limitByRows
                ? max(
                    \App\Support\AppearanceSettings::HOME_FEED_MIN_ROWS,
                    min(\App\Support\AppearanceSettings::HOME_FEED_MAX_ROWS, (int) config('site.' . $prefix . '_rows', $defaults[$prefix . '_rows'] ?? 2))
                )
                : 0;
            $maxItems = $limitByRows ? min(\App\Support\AppearanceSettings::HOME_FEED_MAX_ITEMS, $columns * $rows) : 0;
            $perPage = $limitByRows ? $maxItems : max(1, min(\App\Support\AppearanceSettings::HOME_FEED_MAX_ITEMS, (int) config('site.per_page_videos', 24)));
            $loadMode = (string) config('site.' . $prefix . '_load_mode', $defaults[$prefix . '_load_mode'] ?? 'infinite');
            $loadMode = array_key_exists($loadMode, $homeFeedLoadModes) ? $loadMode : 'infinite';
            $query = [
                'source' => $source,
                'per_page' => $perPage,
                'locale' => app()->getLocale(),
            ];

            if ($source === 'category' && $categoryId > 0) {
                $query['category'] = $categoryId;
            }

            return [
                'enabled' => $enabled,
                'url' => route('videos.index', $query),
                'columns' => $columns,
                'rows' => $rows,
                'maxItems' => $maxItems,
                'loadMode' => $loadMode,
            ];
        };
        $homeMainFeed = $makeVideoFeedConfig('home_feed', 'home_main_feed_enabled', \App\Support\AppearanceSettings::defaults(), false);
        $homeWidgetVideoDefaults = \App\Support\HomeWidgetVideoSettings::defaults();
        $homeWidgetText = function (string $key, string $fallback) use ($homeWidgetVideoDefaults): string {
            $value = config('site.' . $key, $homeWidgetVideoDefaults[$key] ?? $fallback);
            $value = trim((string) $value);

            return $value !== '' ? $value : $fallback;
        };
        $homeWidgetFeeds = [
            'smart_discovery' => $makeVideoFeedConfig('smart_discovery_rail_video', 'smart_discovery_rail_videos_enabled', $homeWidgetVideoDefaults),
            'creator_spotlight' => $makeVideoFeedConfig('creator_spotlight_video', 'creator_spotlight_videos_enabled', $homeWidgetVideoDefaults),
            'trending_collections' => $makeVideoFeedConfig('trending_collections_video', 'trending_collections_videos_enabled', $homeWidgetVideoDefaults),
        ];
        $homeWidgetMotionDirection = function (string $key, string $fallback): string {
            $direction = (string) config('site.home_widget_' . $key . '_motion_direction', $fallback);

            return in_array($direction, ['left', 'right', 'up', 'down', 'none'], true) ? $direction : $fallback;
        };
        $heroLayoutMode = (string) (request()->has('hero') ? request()->query('hero') : config('site.hero_layout_mode', 'snap_slider_carousel'));
        $homeLayoutMode = (string) request()->query('layout', config('site.home_layout_mode', 'grid_modern'));
    @endphp

    @php
        // Grid-ad context and heading icon of the layouts whose page is hero + chips + feed; grid_modern adds
        // the discovery widgets below.
        $homeFeedLayouts = [
            'youtube_spotlight' => ['yt_spotlight_feed', 'compass'],
            'cinema_carousel' => ['cinema_feed', 'film'],
            'bento_mosaic' => ['bento_feed', 'table-cells-large'],
            'tiktok_shorts' => ['shorts_feed', 'fire'],
            'cyberpunk_hud' => ['cyber_feed', 'bolt'],
        ];
    @endphp

    @include('pages.partials.hero')

    @if(isset($homeFeedLayouts[$homeLayoutMode]))
        @include('pages.partials.home-feed', ['feedAdContext' => $homeFeedLayouts[$homeLayoutMode][0], 'feedIcon' => $homeFeedLayouts[$homeLayoutMode][1]])
    @else

    @if($homeCategoryTabsEnabled)
        @include('pages.partials.category-chips-scroller', ['chipsSpacing' => 'mb-2'])
    @endif

    @php
        // Phones: under a photo or list hero (45% of the screen) the discovery rails start at md, so the first
        // screen keeps hero + chips + the first feed row (the theme mockups' phone order); the text banner leaves room.
        $homeHeroSpec = $heroLayoutMode === 'off' ? null : \App\Support\ThemeStructure::hero($heroLayoutMode, $homeLayoutMode);
        $homeRailsPhoneHidden = $homeHeroSpec !== null && $homeHeroSpec['kind'] !== 'banner';
        // The page's first feed is its `early` one (fetched with the page, first row eager, <head> preloads). With the
        // rails hidden on phones that is a rail feed only from 768px (where its first row is the largest picture of the
        // first screen) and the main feed, which phones show under the hero, takes the other widths (it waited for the
        // load event there: weak phone first card ~2.7 s). See <x-videos-area> early-width.
        $homeRailFeedEarlyWidth = $homeRailsPhoneHidden && $homeMainFeed['enabled'] ? 'wide' : null;
    @endphp
    <div @class(['avd-home-rails', 'd-none d-md-block' => $homeRailsPhoneHidden])>
    @php
        $smartDiscoveryEnabled = config('site.smart_discovery_rail_enabled', \App\Services\SmartDiscoveryRailService::DEFAULT_ENABLED);
        $smartDiscoveryTags = $smartDiscoveryTags ?? collect();
        $discoveryRailLimit = max(
            \App\Services\SmartDiscoveryRailService::MIN_LIMIT,
            min(\App\Services\SmartDiscoveryRailService::MAX_LIMIT, (int) config('site.smart_discovery_rail_limit', \App\Services\SmartDiscoveryRailService::DEFAULT_LIMIT))
        );
        $fallbackDiscoveryCategories = $homeFallbackRailsEnabled && $smartDiscoveryTags->isEmpty()
            ? collect($categories ?? [])->take($discoveryRailLimit)
            : collect();
        $discoveryRailItems = $smartDiscoveryTags->isNotEmpty()
            ? $smartDiscoveryTags
            : $fallbackDiscoveryCategories;
        $usingDiscoveryFallback = $smartDiscoveryTags->isEmpty() && $discoveryRailItems->isNotEmpty();
    @endphp
    @if($smartDiscoveryEnabled && $discoveryRailItems->isNotEmpty())
        <section id="home-widget-smart-discovery" class="avd-discovery-rail px-3 px-sm-0 mb-4" aria-label="{{ __($homeWidgetText('smart_discovery_rail_kicker', 'Smart discovery')) }}" data-avd-home-widget="smart_discovery" data-avd-motion-direction="{{ $homeWidgetMotionDirection('smart_discovery', 'left') }}">
            <div class="avd-discovery-head">
                <div>
                    <div class="avd-page-kicker mb-1">
                        <i class="fa-solid fa-compass"></i>
                        <span>{{ __($usingDiscoveryFallback ? $homeWidgetText('smart_discovery_rail_fallback_kicker', 'Browse') : $homeWidgetText('smart_discovery_rail_kicker', 'Smart discovery')) }}</span>
                    </div>
                    <h2>{{ __($usingDiscoveryFallback ? $homeWidgetText('smart_discovery_rail_fallback_title', 'Explore categories') : $homeWidgetText('smart_discovery_rail_title', 'Tags with the most videos')) }}</h2>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @if($homeRailLinksEnabled && config('site.show_trending', true))
                        <a href="{{ locale_url(route('pages.trend')) }}" class="avd-discovery-link">
                            <span>{{ __($homeWidgetText('smart_discovery_rail_link_label', 'View trending')) }}</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    @endif
                    <div class="d-flex align-items-center gap-1 ms-1 ms-sm-2">
                        <button type="button" class="avd-rail-nav-btn avd-rail-nav-prev" onclick="window.avdScrollRail ? window.avdScrollRail(this, -1) : this.closest('section').querySelector('.avd-discovery-track').scrollBy({left: -320, behavior: 'smooth'})" aria-label="{{ __('Previous') }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="avd-rail-nav-btn avd-rail-nav-next" onclick="window.avdScrollRail ? window.avdScrollRail(this, 1) : this.closest('section').querySelector('.avd-discovery-track').scrollBy({left: 320, behavior: 'smooth'})" aria-label="{{ __('Next') }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div id="avdSmartDiscoveryTrack" class="avd-discovery-track" tabindex="0" role="region" aria-label="{{ __($homeWidgetText('smart_discovery_rail_title', 'Tags with the most videos')) }}">
                @foreach($discoveryRailItems as $item)
                    @php
                        $discoveryItemTitle = $usingDiscoveryFallback ? __($item->title) : $item->localized_name;
                        $discoveryItemUrl = $usingDiscoveryFallback
                            ? locale_url(route('pages.category', $item->slug))
                            : locale_url(route('search.index')) . '?q=' . rawurlencode($item->name);
                    @endphp
                    {{-- Only a category's own picture: without one the card style draws the theme's card (video.scss). --}}
                    <a
                        href="{{ $discoveryItemUrl }}"
                        class="avd-discovery-card"
                        @if($usingDiscoveryFallback && filled($item->background))
                            style="--avd-discovery-bg: url('{{ $item->background_url }}')"
                        @endif
                    >
                        <span class="avd-discovery-icon">
                            <i class="fa-solid {{ $usingDiscoveryFallback ? 'fa-' . $item->icon_name : 'fa-hashtag' }}"></i>
                        </span>
                        <span class="avd-discovery-title">{{ $discoveryItemTitle }}</span>
                        <span class="avd-discovery-meta">
                            @if($usingDiscoveryFallback)
                                {{ __('Browse videos') }}
                            @else
                                {{ trans_choice('videos', $item->smart_discovery_videos_count, ['count' => \App\Helpers\Number::humanize($item->smart_discovery_videos_count)]) }}
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
        @if($homeWidgetFeeds['smart_discovery']['enabled'])
            <div class="avd-widget-video-feed px-3 px-sm-0 mb-4" data-avd-widget-feed="smart-discovery">
                <x-videos-area
                    :url="$homeWidgetFeeds['smart_discovery']['url']"
                    :columns="$homeWidgetFeeds['smart_discovery']['columns']"
                    :max-items="$homeWidgetFeeds['smart_discovery']['maxItems']"
                    :max-rows="$homeWidgetFeeds['smart_discovery']['rows'] ?? 2"
                    ad-context="smart_discovery"
                    :early-width="$homeRailFeedEarlyWidth"
                />
            </div>
        @endif
    @endif

    @php
        $creatorSpotlightEnabled = config('site.creator_spotlight_enabled', \App\Services\AdaptiveCreatorSpotlightService::DEFAULT_ENABLED);
        $creatorSpotlightCreators = $creatorSpotlightCreators ?? collect();
        $creatorSpotlightLimit = max(
            \App\Services\AdaptiveCreatorSpotlightService::MIN_LIMIT,
            min(\App\Services\AdaptiveCreatorSpotlightService::MAX_LIMIT, (int) config('site.creator_spotlight_limit', \App\Services\AdaptiveCreatorSpotlightService::DEFAULT_LIMIT))
        );
        $creatorSpotlightFallbackCategories = $homeFallbackRailsEnabled && $creatorSpotlightCreators->isEmpty()
            ? collect($categories ?? [])->take($creatorSpotlightLimit)
            : collect();
        $usingCreatorFallback = $creatorSpotlightCreators->isEmpty() && $creatorSpotlightFallbackCategories->isNotEmpty();
    @endphp
    @if($creatorSpotlightEnabled && ($creatorSpotlightCreators->isNotEmpty() || $creatorSpotlightFallbackCategories->isNotEmpty()))
        <section id="home-widget-creator-spotlight" class="avd-creator-spotlight px-3 px-sm-0 mb-4" aria-label="{{ __($homeWidgetText('creator_spotlight_kicker', 'Creator spotlight')) }}" data-avd-home-widget="creator_spotlight" data-avd-motion-direction="{{ $homeWidgetMotionDirection('creator_spotlight', 'right') }}">
            <div class="avd-discovery-head">
                <div>
                    <div class="avd-page-kicker mb-1">
                        <i class="fa-solid fa-users-viewfinder"></i>
                        <span>{{ __($usingCreatorFallback ? $homeWidgetText('creator_spotlight_fallback_kicker', 'Creator spotlight') : $homeWidgetText('creator_spotlight_kicker', 'Creator spotlight')) }}</span>
                    </div>
                    <h2>{{ __($usingCreatorFallback ? $homeWidgetText('creator_spotlight_fallback_title', 'Find creators by category') : $homeWidgetText('creator_spotlight_title', 'Creators posting now')) }}</h2>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @if($homeRailLinksEnabled)
                        <a href="{{ locale_url(route('subscription.discover')) }}" class="avd-discovery-link">
                            <span>{{ __($homeWidgetText('creator_spotlight_link_label', 'Discover channels')) }}</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    @endif
                    <div class="d-flex align-items-center gap-1 ms-1 ms-sm-2">
                        <button type="button" class="avd-rail-nav-btn avd-rail-nav-prev" onclick="window.avdScrollRail ? window.avdScrollRail(this, -1) : this.closest('section').querySelector('.avd-discovery-track, .row').scrollBy({left: -320, behavior: 'smooth'})" aria-label="{{ __('Previous') }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="avd-rail-nav-btn avd-rail-nav-next" onclick="window.avdScrollRail ? window.avdScrollRail(this, 1) : this.closest('section').querySelector('.avd-discovery-track, .row').scrollBy({left: 320, behavior: 'smooth'})" aria-label="{{ __('Next') }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div id="avdCreatorSpotlightTrack" class="row gx-3 gy-3 avd-creator-track avd-discovery-track" tabindex="0" role="region" aria-label="{{ __($usingCreatorFallback ? $homeWidgetText('creator_spotlight_fallback_title', 'Find creators by category') : $homeWidgetText('creator_spotlight_title', 'Creators posting now')) }}">
                @if($usingCreatorFallback)
                    @foreach($creatorSpotlightFallbackCategories as $category)
                        <article class="col-6 col-sm-4 col-md-3 col-xl-2">
                            <div class="avd-channel-card h-100">
                                <a href="{{ locale_url(route('pages.category', $category->slug)) }}" class="text-decoration-none d-flex flex-column gap-2 align-items-center text-center">
                                    <span class="avd-channel-card-avatar d-inline-flex align-items-center justify-content-center">
                                        <i class="fa-solid fa-{{$category->icon_name}}"></i>
                                    </span>
                                    <strong class="text-body text-truncate w-100">{{ __($category->title) }}</strong>
                                    <span class="text-muted text-sm">{{ __('Browse creators') }}</span>
                                </a>
                            </div>
                        </article>
                    @endforeach
                @else
                    @foreach($creatorSpotlightCreators as $creator)
                        <article class="col-6 col-sm-4 col-md-3 col-xl-2">
                            <div class="avd-channel-card h-100">
                                <a href="{{$creator->route}}" class="text-decoration-none d-flex flex-column gap-2 align-items-center text-center">
                                    <img class="avd-channel-card-avatar avd-avatar-media flex-shrink-0" src="{{$creator->avatar_url}}" alt="{{$creator->username}} avatar" width="104" height="104" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                                    <strong class="text-body text-truncate w-100" title="{{$creator->username}}">{{$creator->username}}</strong>
                                    <span class="text-muted text-sm">
                                        @if((int) $creator->creator_spotlight_videos_count > 0)
                                            {{ trans_choice('videos', $creator->creator_spotlight_videos_count) }} {{ __('recently') }}
                                        @else
                                            {{ __('Discover channel') }}
                                        @endif
                                    </span>
                                    @php
                                        $cSubCount = (int) ($creator->subscribers_count ?? 0);
                                    @endphp
                                    <span class="text-muted text-sm">{{ trans_choice('subscribers', $cSubCount, ['count' => \App\Helpers\Number::humanize($cSubCount)]) }}</span>
                                </a>
                            </div>
                        </article>
                    @endforeach
                @endif
            </div>
        </section>
        @if($homeWidgetFeeds['creator_spotlight']['enabled'])
            <div class="avd-widget-video-feed px-3 px-sm-0 mb-4" data-avd-widget-feed="creator-spotlight">
                <x-videos-area
                    :url="$homeWidgetFeeds['creator_spotlight']['url']"
                    :columns="$homeWidgetFeeds['creator_spotlight']['columns']"
                    :max-items="$homeWidgetFeeds['creator_spotlight']['maxItems']"
                    :max-rows="$homeWidgetFeeds['creator_spotlight']['rows'] ?? 2"
                    ad-context="creator_spotlight"
                    :early-width="$homeRailFeedEarlyWidth"
                />
            </div>
        @endif
    @endif

    @php
        $trendingCollectionsEnabled = config('site.trending_collections_enabled', \App\Services\AdaptiveTrendingCollectionsService::DEFAULT_ENABLED);
        $trendingCollections = $trendingCollections ?? collect();
        $trendingCollectionsLimit = max(
            \App\Services\AdaptiveTrendingCollectionsService::MIN_LIMIT,
            min(\App\Services\AdaptiveTrendingCollectionsService::MAX_LIMIT, (int) config('site.trending_collections_limit', \App\Services\AdaptiveTrendingCollectionsService::DEFAULT_LIMIT))
        );
        $fallbackTrendingCollections = $homeFallbackRailsEnabled && $trendingCollections->isEmpty()
            ? collect($categories ?? [])->take($trendingCollectionsLimit)
            : collect();
        $usingTrendingCollectionsFallback = $trendingCollections->isEmpty() && $fallbackTrendingCollections->isNotEmpty();
    @endphp
    @if($trendingCollectionsEnabled && ($trendingCollections->isNotEmpty() || $fallbackTrendingCollections->isNotEmpty()))
        <section id="home-widget-trending-collections" class="avd-discovery-rail px-3 px-sm-0 mb-4" aria-label="{{ __($homeWidgetText('trending_collections_kicker', 'Trending actors')) }}" data-avd-home-widget="trending_collections" data-avd-motion-direction="{{ $homeWidgetMotionDirection('trending_collections', 'left') }}">
            <div class="avd-discovery-head">
                <div>
                    <div class="avd-page-kicker mb-1">
                        <i class="fa-solid fa-users-viewfinder"></i>
                        <span>{{ __($usingTrendingCollectionsFallback ? $homeWidgetText('trending_collections_fallback_kicker', 'Browse collections') : $homeWidgetText('trending_collections_kicker', 'Trending actors')) }}</span>
                    </div>
                    <h2>{{ __($usingTrendingCollectionsFallback ? $homeWidgetText('trending_collections_fallback_title', 'Collections to start with') : $homeWidgetText('trending_collections_title', 'Actors viewers are watching now')) }}</h2>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @if($homeRailLinksEnabled && config('site.show_trending', true))
                        <a href="{{ locale_url(route('pages.trend')) }}" class="avd-discovery-link">
                            <span>{{ __($homeWidgetText('trending_collections_link_label', 'View trending')) }}</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    @endif
                    <div class="d-flex align-items-center gap-1 ms-1 ms-sm-2">
                        <button type="button" class="avd-rail-nav-btn avd-rail-nav-prev" onclick="window.avdScrollRail ? window.avdScrollRail(this, -1) : this.closest('section').querySelector('.avd-discovery-track').scrollBy({left: -320, behavior: 'smooth'})" aria-label="{{ __('Previous') }}" title="{{ __('Previous') }}">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="avd-rail-nav-btn avd-rail-nav-next" onclick="window.avdScrollRail ? window.avdScrollRail(this, 1) : this.closest('section').querySelector('.avd-discovery-track').scrollBy({left: 320, behavior: 'smooth'})" aria-label="{{ __('Next') }}" title="{{ __('Next') }}">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div id="avdTrendingActorsTrack" class="avd-discovery-track" tabindex="0" role="region" aria-label="{{ __($homeWidgetText('trending_collections_title', 'Actors viewers are watching now')) }}">
                @foreach($trendingCollections->isNotEmpty() ? $trendingCollections : $fallbackTrendingCollections as $collection)
                    @php
                        $trendingActorName = $usingTrendingCollectionsFallback ? null : trim((string) ($collection->actor_name ?? ''));
                        $trendingCollectionUrl = $usingTrendingCollectionsFallback
                            ? locale_url(route('pages.category', $collection->slug))
                            : locale_url(route('search.index')) . '?q=' . rawurlencode($trendingActorName);
                    @endphp
                    <a
                        href="{{ $trendingCollectionUrl }}"
                        class="avd-discovery-card"
                        @if($usingTrendingCollectionsFallback && filled($collection->background))
                            style="--avd-discovery-bg: url('{{ $collection->background_url }}')"
                        @endif
                    >
                        <span class="avd-discovery-icon">
                            <i class="fa-solid {{ $usingTrendingCollectionsFallback ? 'fa-' . $collection->icon_name : 'fa-user' }}"></i>
                        </span>
                        <span class="avd-discovery-title">{{ $usingTrendingCollectionsFallback ? __($collection->title) : $trendingActorName }}</span>
                        <span class="avd-discovery-meta">
                            @if($usingTrendingCollectionsFallback)
                                {{ __('Browse videos') }}
                            @else
                                {{ trans_choice('views', (int) $collection->trending_collections_views_count) }} {{ __('recently') }}
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
        @if($homeWidgetFeeds['trending_collections']['enabled'])
            <div class="avd-widget-video-feed px-3 px-sm-0 mb-4" data-avd-widget-feed="trending-collections">
                <x-videos-area
                    :url="$homeWidgetFeeds['trending_collections']['url']"
                    :columns="$homeWidgetFeeds['trending_collections']['columns']"
                    :max-items="$homeWidgetFeeds['trending_collections']['maxItems']"
                    :max-rows="$homeWidgetFeeds['trending_collections']['rows'] ?? 2"
                    ad-context="trending_actors"
                    :early-width="$homeRailFeedEarlyWidth"
                />
            </div>
        @endif
    @endif

    </div>

    @if($homeMainFeed['enabled'])
        <section class="avd-home-feed px-2 px-sm-0" aria-labelledby="avdHomeFeedTitle">
            <x-theme.section-head
                :title="__(\App\Support\ThemeStructure::voice('feed'))"
                :kicker="filled(\App\Support\ThemeStructure::voice('feed_kicker')) ? __(\App\Support\ThemeStructure::voice('feed_kicker')) : null"
                title-id="avdHomeFeedTitle"
                icon="compass"
            />
            <x-videos-area
                :url="$homeMainFeed['url']"
                :columns="$homeMainFeed['columns']"
                :load-mode="$homeMainFeed['loadMode']"
                ad-context="new_videos"
                :phone-strip-screen="true"
                :early="$homeRailFeedEarlyWidth !== null ? true : null"
            />
        </section>
    @endif
    @endif
    @includeWhen($showPremiumModal, 'modals.premium')
@endsection
