@use('Illuminate\Support\Str')

@extends('layouts.default')

@php
    /* The channel page /@channel (member design 6.10, 6.19; data from App\Http\Controllers\UserController@show).
       The creator's theme paints only the header band (banner, identity, actions); everything below it follows the
       look. Home is printed with the page; Videos and Playlists load their cards when first opened. */
    $isOwner = auth()->check() && auth()->user()->is($user);
    $effectsOn = (bool) config('site.channel_customization_effects_enabled', true);
    $bandTheme = $channel['theme'];
    $bandPattern = config('site.channel_customization_pattern_enabled', true) ? $channel['pattern'] : 'clean';
    $bandShape = config('site.channel_customization_shape_enabled', true) ? $channel['header_shape'] : 'flat';
    $bandMotion = ($effectsOn && config('site.channel_customization_motion_enabled', true)) ? $channel['motion'] : 'none';
    $accentStyle = \App\Support\ChannelAccent::style((string) $channel['accent'], (string) $channel['custom_accent']);

    $totalVideos = (int) ($user->active_videos_count ?? 0);
    $totalPlaylists = (int) ($user->active_playlists_count ?? 0);
    $subscriberCount = (int) ($user->subscribers_count ?? 0);
    $showStats = $channel['show_stats'] === '1' && config('site.channel_customization_stats_enabled', true);
    $countShown = $showStats && $user->show_subscribers && config('site.show_subscriber_count', true);
    $handle = '@' . ($user->slug ?: $user->username);
    $tagline = $channel['tagline'] !== '' ? $channel['tagline'] : Str::limit((string) $user->description, 150);

    // Featured (6.10, 6.19): the creator's featured mode picks the videos (pinned first, the latest or the most viewed) and
    // their columns x rows how many; one video is the lead card, more are a card grid in the creator's featured layout,
    // with the channel_home_featured list ad where "Ad after every N" puts it (App\Support\FeedAdSlots).
    $newest = $user->videos;
    $featuredColumns = max(1, min(6, (int) ($channel['featured_columns'] ?? 3)));
    $featuredLimit = $featuredColumns * max(1, min(4, (int) ($channel['featured_rows'] ?? 1)));
    $featuredPool = match ($channel['featured_mode']) {
        'popular' => $user->videos()->active()->forLocale()->with($videoRelations ?? ['user'])->orderByDesc('views')->orderByDesc('id')->limit($featuredLimit + 1)->get(),
        'latest' => $newest,
        default => $user->pinned_video
            ? collect([$user->pinned_video])->merge($newest->reject(fn ($video) => (int) $video->id === (int) $user->pinned_video->id))
            : $newest,
    };
    $adPos = \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6));
    $adsActive = config('site.ads_enabled') && (!config('site.ads_hide_for_premium', true) || !auth()->user()?->is_premium);
    $hasFeaturedAd = $adsActive && trim(\App\Support\ListAdContexts::code('channel_home_featured')) !== '' && \App\Support\ListAdContexts::shape('channel_home_featured') !== 'wide_row';
    $featuredAdAfter = $hasFeaturedAd ? \App\Support\FeedAdSlots::gridAdAfter($featuredLimit, $featuredPool->count(), $adPos) : 0;
    $featuredVideos = $featuredPool->take($featuredAdAfter > 0 ? $featuredLimit - 1 : $featuredLimit)->values();
    $showFeatured = $channel['show_featured_video'] === '1' && config('site.channel_customization_featured_video_enabled', true) && $featuredVideos->isNotEmpty();
    $featuredLead = $showFeatured && $featuredLimit === 1 ? $featuredVideos->first() : null;
    $featuredLayout = match ($channel['featured_layout']) {
        'hero' => 'showcase',
        'rail' => 'rail',
        'compact' => 'compact',
        default => 'grid',
    };
    $featuredIds = $showFeatured ? $featuredVideos->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

    // Latest uploads, newest first; without the featured videos when the creator keeps the sections apart
    $latestSource = ($showFeatured && $channel['dedupe_home_sections'] === '1')
        ? $newest->reject(fn ($video) => in_array((int) $video->id, $featuredIds, true))->values()
        : $newest;
    $hasLatestAd = $adsActive && trim(\App\Support\ListAdContexts::code('channel_home_latest')) !== '' && \App\Support\ListAdContexts::shape('channel_home_latest') !== 'wide_row';
    $latestAdAfter = $hasLatestAd ? \App\Support\FeedAdSlots::gridAdAfter($latestLimit, $latestSource->count(), $adPos) : 0;
    $latestVideos = $latestSource->take($latestAdAfter > 0 ? $latestLimit - 1 : $latestLimit)->values();
    $showLatest = $channel['show_latest_uploads'] === '1' && config('site.channel_customization_latest_uploads_enabled', true) && $latestVideos->isNotEmpty();
    $homePlaylists = $user->playlists;
    $showPlaylists = $channel['show_playlists'] === '1' && config('site.channel_customization_playlists_enabled', true) && $homePlaylists->isNotEmpty();
    $showAbout = $channel['show_about_panel'] === '1' && config('site.channel_customization_sections_enabled', true);
    $showWebsite = $channel['show_social_links'] === '1' && config('site.channel_customization_socials_enabled', true) && $user->website;
    $websiteHref = $user->website ? (Str::startsWith($user->website, ['http://', 'https://']) ? $user->website : 'https://' . $user->website) : null;
    $showCta = $channel['show_subscribe_cta'] === '1' && config('site.channel_customization_cta_enabled', true) && $channel['cta_label'] !== '' && preg_match('#^https?://#i', $channel['cta_url']);
    $showUploadDate = $channel['show_upload_rhythm'] === '1' && config('site.channel_customization_upload_rhythm_enabled', true);
    $lastUpload = $newest->first()?->published_at ?? $newest->first()?->created_at;
    $lastUploadText = null;
    if ($lastUpload) {
        $lastUploadDay = $lastUpload->copy()->timezone(config('app.timezone'))->startOfDay();
        $todayStart = now()->timezone(config('app.timezone'))->startOfDay();
        $lastUploadText = $lastUploadDay->equalTo($todayStart) ? __('today') : ($lastUploadDay->equalTo($todayStart->copy()->subDay()) ? __('yesterday') : $lastUpload->diffForHumans());
    }
    $isEmpty = $totalVideos === 0 && $homePlaylists->isEmpty();
    $tabs = array_values(array_filter(['home', 'videos', 'playlists', $showAbout ? 'about' : null]));
    $latestLayout = in_array($channel['latest_uploads_layout'], ['rail', 'compact', 'showcase'], true) ? $channel['latest_uploads_layout'] : 'grid';
    $playlistsLayout = in_array($channel['playlists_layout'], ['rail', 'compact'], true) ? $channel['playlists_layout'] : 'grid';
    $videosLayout = in_array($channel['video_layout'], ['compact', 'list', 'showcase'], true) ? $channel['video_layout'] : 'grid';

    $seoService = app(\App\Services\SeoService::class);
    $profileDescription = Str::limit($user->description ?: $channel['tagline'], 155, '');
    $profileSeoValues = [
        'username' => $user->username,
        'user' => $user->username,
        'title' => $user->username,
        'description' => $profileDescription,
        'videos_count' => (string) $totalVideos,
        'playlists_count' => (string) $totalPlaylists,
        'views_count' => (string) (int) ($user->videos_views_count ?? 0),
        'subscribers_count' => (string) $subscriberCount,
    ];
    $subsText = fn (int $n) => trans_choice('subscribers', $n, ['count' => \App\Support\LocalNumber::short(max(0, $n))]);
    // the first section's first cards are on the first screen: they load at once (R1-067: the lazy thumbnails made the
    // largest picture of a channel without a banner of its own come seconds after the page on a weak phone)
    $firstScreenCards = 4;
    // From 576px the first section's first row is pictures of one size, so the browser's "largest picture" can be any
    // of them: without a banner, the whole row loads with a high priority there (a phone's lead card is the largest;
    // it has the high priority on its own picture)
    $firstRowVideos = $user->banner || $isEmpty || ($showFeatured && $featuredLead) ? collect()
        : ($showFeatured ? $featuredVideos : ($showLatest ? $latestVideos : collect()));
    $firstRowCount = $showFeatured ? $featuredColumns : (int) $latestColumns;
@endphp

@if($firstRowVideos->count() > 1 && $firstRowCount > 1)
    @push('preload')
        @foreach($firstRowVideos->slice(1, $firstRowCount - 1) as $firstRowVideo)
            <link rel="preload" as="image" href="{{ \App\Support\PageUrl::local($firstRowVideo->thumbnail_url) }}" fetchpriority="high" media="(min-width: 576px)">
        @endforeach
    @endpush
@endif

{{-- The channel page's own rules (resources/sass/channel-page.scss; 1.2.3 round 3, R1-070 = R2R-10: out of the member
     entry, which every page of a signed-in visitor loads), after the member entry. A build without the entry (an older
     public/build) renders the page without it, as the layout does with the member entry. --}}
@push('head-styles')
    @php
        try {
            $channelPageCss = app(\Illuminate\Foundation\Vite::class)('resources/sass/channel-page.scss');
        } catch (\Illuminate\Foundation\ViteException) {
            $channelPageCss = null;
        }
    @endphp
    {{ $channelPageCss }}
@endpush

@section('title', $seoService->renderTemplate('profile_meta_title_template', $profileSeoValues, $user->username, 180))
@section('description', $seoService->renderTemplate('profile_meta_description_template', $profileSeoValues, $profileDescription, 500))
@section('image', site_asset_url($user->banner_url))
@section('canonical', $user->route)

@push('structured-data')
    @php
        $jsonScriptFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        $profileSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            'url' => $user->route,
            'name' => $user->username,
            'about' => [
                '@type' => 'Person',
                'name' => $user->username,
                'url' => $user->route,
            ],
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($profileSchema, $jsonScriptFlags) !!}</script>
@endpush

@section('content')
    {{-- the page root with the whitespace between tags, and between a tag's attributes, collapsed to one space (the content
         budget, 4.3: <= 16 KB raw / 3 KB gzip / 250 nodes, measured on the root as it is sent) --}}
    @php ob_start(); @endphp
    <div class="avd-mk mk-page mk-channel-page"
         x-data="mkChannel"
         data-tabs='@json($tabs)'
         data-part-url="{{ \App\Support\PageUrl::local(locale_url($user->route)) }}">
        <div class="mk-channel"
             data-channel-layout="{{ $channel['layout'] }}"
             data-channel-motion="{{ $bandMotion }}"
             data-channel-density="{{ $channel['density'] }}"
             data-channel-video-layout="{{ $channel['video_layout'] }}"
             data-channel-accent="{{ $channel['accent'] }}"
             @if($accentStyle !== '') style="{{ $accentStyle }}" @endif>

            <section class="mk-channel__band"
                     data-channel-theme="{{ $bandTheme }}"
                     data-channel-surface="{{ $channel['surface'] }}"
                     data-channel-accent="{{ $channel['accent'] }}"
                     data-channel-avatar="{{ $channel['avatar_frame'] }}"
                     data-channel-shape="{{ $bandShape }}"
                     data-channel-pattern="{{ $bandPattern }}"
                     data-channel-banner="{{ $channel['banner_treatment'] }}"
                     data-channel-stats="{{ $channel['stats_style'] }}"
                     aria-label="{{ __('Channel') }}">
                @if($user->banner)
                    <div class="mk-channel__banner"><img src="{{ $user->banner_url }}" alt="" width="2048" height="640" fetchpriority="high" decoding="async" onerror="this.onerror=null;this.remove();"></div>
                @else
                    {{-- no banner of the creator's own: the look's colours, never the stock picture (account-35) --}}
                    <div class="mk-channel__banner mk-channel__banner--token" aria-hidden="true"></div>
                @endif
                <div class="mk-channel__id mk-channel__glass">
                    <span class="mk-channel__avatar"><img class="mk-avatar mk-avatar--96" src="{{ \App\Support\PageUrl::local($user->avatar_url) }}" alt="" width="96" height="96" decoding="async" onerror="this.onerror=null;this.src='{{ \App\Support\PageUrl::local(asset('images/default_men.png')) }}';">@if($channel['avatar_frame'] === 'badge' && config('site.creator_verified_badge_enabled', true))<span class="mk-channel__badge" title="{{ __('Creator') }}"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span class="mk-sr">{{ __('Creator') }}</span></span>@endif</span>
                    <div class="mk-channel__who">
                        <h1 class="mk-channel__name"{!! \App\Support\TextDirection::attr($user->username) !!}>{{ $user->username }}</h1>
                        <div class="mk-channel__facts">
                            <span><bdi dir="ltr">{{ $handle }}</bdi></span>
                            @if($countShown)
                                <span data-subscriber-count="{{ $user->getKey() }}" data-count-on="{{ $subsText($subscriberCount + ($is_subscribed ? 0 : 1)) }}" data-count-off="{{ $subsText($subscriberCount - ($is_subscribed ? 1 : 0)) }}">{{ $subsText($subscriberCount) }}</span>
                            @endif
                            @if($showStats && $totalVideos > 0)
                                <span>{{ trans_choice('videos', $totalVideos, ['count' => \App\Support\LocalNumber::short($totalVideos)]) }}</span>
                            @endif
                            @if($totalVideos === 0)
                                {{-- "No videos yet" says it once (not "0 videos · No videos yet") --}}
                                <span>{{ __('No videos yet') }}</span>
                            @elseif($showUploadDate && $lastUploadText)
                                <span>{{ __('Last upload: :when', ['when' => $lastUploadText]) }}</span>
                            @endif
                        </div>
                        @if($tagline !== '')
                            {{-- QA channel-20: a tagline that may take two lines keeps both from the first paint, so the band does not grow when
                                 the web font arrives (the fallback font is narrower) --}}
                            <p @class(['mk-channel__tagline', 'mk-clamp-2', 'mk-channel__tagline--two' => mb_strlen($tagline) > 40]){!! \App\Support\TextDirection::attr($tagline) !!}>{{ $tagline }}</p>
                        @endif
                    </div>
                    <div @class(['mk-channel__actions', 'mk-channel__actions--owner' => $isOwner])>
                        @if($isOwner)
                            <a class="mk-btn mk-btn--secondary mk-channel__secondary" href="{{ route('user.edit', ['section' => 'channel']) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i><span>{{ __('Customize channel') }}</span></a>
                            <a class="mk-btn mk-btn--secondary" href="{{ route('user.videos.index') }}"><i class="fa-solid fa-clapperboard" aria-hidden="true"></i><span>{{ __('Manage videos') }}</span></a>
                            <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--outlined" data-share-dialog="#mkShareChannel" aria-label="{{ __('Share channel') }}" title="{{ __('Share channel') }}"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
                        @else
                            @include('library.subscribe', ['channel' => $user, 'subscribed' => $is_subscribed])
                            @if($showCta)
                                <a class="mk-btn mk-btn--secondary mk-channel__cta" href="{{ $channel['cta_url'] }}" target="_blank" rel="external nofollow noopener" title="{{ $channel['cta_label'] }}" aria-label="{{ __(':label (opens another site)', ['label' => $channel['cta_label']]) }}"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span{!! \App\Support\TextDirection::attr($channel['cta_label']) !!}>{{ $channel['cta_label'] }}</span></a>
                            @endif
                            <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--outlined" data-share-dialog="#mkShareChannel" aria-label="{{ __('Share channel') }}" title="{{ __('Share channel') }}"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
                            <div class="dropdown mk-channel__more">
                                <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--outlined" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('More actions') }}" title="{{ __('More actions') }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
                                <div class="dropdown-menu dropdown-menu-end mk-menu">
                                    @if($showWebsite)
                                        <a class="mk-menu__item" href="{{ $websiteHref }}" target="_blank" rel="external nofollow noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>{{ __('Visit website') }}</a>
                                    @endif
                                    @auth
                                        @can('report', $user)
                                            <report-button button-class="mk-menu__item" reported-class="mk-menu__note" id="{{ $user->id }}" type="user"></report-button>
                                        @else
                                            @if($user->reportByAuthUser)
                                                <span class="mk-menu__note"><i class="fa-regular fa-flag" aria-hidden="true"></i> {{ __('You reported this channel :when.', ['when' => $user->reportByAuthUser->created_at->diffForHumans()]) }}</span>
                                            @endif
                                        @endcan
                                    @else
                                        <a class="mk-menu__item" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}"><i class="fa-regular fa-flag" aria-hidden="true"></i>{{ __('Sign in to report this channel') }}</a>
                                    @endauth
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            {{-- the open tab is marked by the script (markTabs: is-active + aria-current="page"); Home as the page starts --}}
            <div class="mk-channel__tabs" x-ref="tabs" @click="retryActive($event)">
                <nav class="mk-tabs" aria-label="{{ __('Channel') }}">
                    <a class="mk-tab is-active" href="#home" aria-controls="channelHome" aria-current="page">{{ __('Home') }}</a>
                    <a class="mk-tab" href="#videos" aria-controls="channelVideos">{{ __('Videos') }}</a>
                    <a class="mk-tab" href="#playlists" aria-controls="channelPlaylists">{{ __('Playlists') }}</a>
                    @if($showAbout)
                        <a class="mk-tab" href="#about" aria-controls="channelAbout">{{ __('About') }}</a>
                    @endif
                </nav>
            </div>


            {{-- Home --}}
            <div class="mk-channel__pane" id="channelHome" x-show="tab === 'home'">
                @if($isEmpty)
                    <section class="mk-panel">
                        @if($isOwner)
                            <x-mk.empty icon="clapperboard" :title="__('Upload your first video')" :text="__('Your channel is ready. Videos you make public appear here, newest first.')">
                                @if(config('site.allow_uploads', true))
                                    <button type="button" class="mk-btn mk-btn--primary" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload video') }}</span></button>
                                @endif
                                <a class="mk-btn mk-btn--secondary" href="{{ route('user.edit', ['section' => 'channel']) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i><span>{{ __('Customize channel') }}</span></a>
                            </x-mk.empty>
                        @else
                            <x-mk.empty compact icon="video-slash" :title="__('No videos yet')" :text="__('This channel has not shared any videos. Subscribe to hear when it does.')" />
                        @endif
                    </section>
                @elseif(!$showFeatured && !$showLatest && !$showPlaylists)
                    <section class="mk-panel">
                        <x-mk.empty compact icon="layer-group" :title="__('This channel shows its videos in the Videos tab')" :text="__('Open Videos or Playlists to see everything it shares.')">
                            <a class="mk-btn mk-btn--secondary" href="#videos"><span>{{ __('Videos') }}</span></a>
                        </x-mk.empty>
                    </section>
                @else
                    @if($showFeatured)
                        @if($featuredLead)
                            @php
                                $featuredViews = (int) ($featuredLead->views ?? 0);
                                $featuredWhen = ($featuredLead->published_at ?? $featuredLead->created_at)?->diffForHumans();
                            @endphp
                            <section class="mk-channel__featured avd-channel-featured" aria-labelledby="mkFeatTitle" data-channel-featured-layout="{{ $channel['featured_layout'] }}" data-channel-featured-columns="{{ $featuredColumns }}">
                                <a class="mk-feature" href="{{ $featuredLead->route }}">
                                    <span class="mk-thumb"><img src="{{ $featuredLead->thumbnail_url }}" alt="" width="640" height="360"@if(!$user->banner) fetchpriority="high"@endif decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/placeholders/no-image.svg') }}';">@if($featuredLead->duration)<span class="mk-thumb__dur">{{ $featuredLead->duration }}</span>@endif</span>
                                    <span class="mk-feature__text">
                                        <span class="mk-head__kicker">{{ __('Featured') }}</span>
                                        <span class="mk-feature__title" id="mkFeatTitle"{!! \App\Support\TextDirection::attr($featuredLead->title) !!}>{{ $featuredLead->title }}</span>
                                        <span class="mk-muted">{{ trans_choice('views', $featuredViews, ['count' => \App\Support\LocalNumber::format($featuredViews)]) }}@if($featuredWhen)&nbsp;· {{ $featuredWhen }}@endif</span>
                                        @if($featuredLead->description)
                                            <span class="mk-feature__desc mk-clamp-2"{!! \App\Support\TextDirection::attr(strip_tags((string) $featuredLead->description)) !!}>{{ Str::limit(strip_tags((string) $featuredLead->description), 240) }}</span>
                                        @endif
                                    </span>
                                </a>
                            </section>
                        @else
                            <section class="mk-channel__section mk-channel__featured avd-channel-featured" aria-labelledby="mkFeatTitle" data-channel-featured-layout="{{ $channel['featured_layout'] }}" data-channel-featured-columns="{{ $featuredColumns }}">
                                <div class="mk-section-head">
                                    <h2 class="mk-panel__title" id="mkFeatTitle">{{ __('Featured videos') }}</h2>
                                </div>
                                {{-- one row of the creator's columns stays one row (it scrolls when they do not fit): no lone card under it --}}
                                <div @class(['mk-cards', 'mk-cards--cols', 'mk-cards--' . $featuredLayout, 'mk-cards--one-row' => $featuredLimit === $featuredColumns && in_array($featuredLayout, ['grid', 'compact'], true), 'avd-channel-featured-grid']) style="--mk-cols: {{ $featuredColumns }}">
                                    @include('library.cards', ['videos' => $featuredVideos, 'showChannel' => false, 'adContext' => 'channel_home_featured', 'adAfter' => $featuredAdAfter > 0 ? [$featuredAdAfter] : [], 'eagerFirst' => $firstScreenCards, 'highFirst' => !$user->banner])
                                </div>
                            </section>
                        @endif
                    @endif

                    @if($showLatest)
                        <section class="mk-channel__section avd-channel-latest" aria-labelledby="mkLatestTitle" data-channel-latest-layout="{{ $channel['latest_uploads_layout'] }}" data-channel-latest-columns="{{ $latestColumns }}">
                            <div class="mk-section-head">
                                <h2 class="mk-panel__title" id="mkLatestTitle">{{ __('Latest uploads') }}</h2>
                                <a class="mk-btn mk-btn--quiet mk-btn--sm" href="#videos" aria-label="{{ __('See all: :name', ['name' => __('Videos')]) }}"><span>{{ __('See all') }}</span></a>
                            </div>
                            <div class="mk-cards mk-cards--cols mk-cards--{{ $latestLayout }}" style="--mk-cols: {{ $latestColumns }}">
                                @include('library.cards', ['videos' => $latestVideos, 'showChannel' => false, 'adContext' => 'channel_home_latest', 'adAfter' => $latestAdAfter > 0 ? [$latestAdAfter] : [], 'eagerFirst' => $showFeatured ? 0 : $firstScreenCards, 'highFirst' => !$user->banner])
                            </div>
                        </section>
                    @endif

                    @if($showPlaylists)
                        <section class="mk-channel__section avd-channel-playlists" aria-labelledby="mkPlaylistsTitle" data-channel-playlists-layout="{{ $channel['playlists_layout'] }}" data-channel-playlists-columns="{{ $playlistColumns }}">
                            <div class="mk-section-head">
                                <h2 class="mk-panel__title" id="mkPlaylistsTitle">{{ __('Playlists') }}</h2>
                                <a class="mk-btn mk-btn--quiet mk-btn--sm" href="#playlists" aria-label="{{ __('See all: :name', ['name' => __('Playlists')]) }}"><span>{{ __('See all') }}</span></a>
                            </div>
                            <div class="mk-cards mk-cards--cols mk-cards--{{ $playlistsLayout }}" style="--mk-cols: {{ max(3, $playlistColumns) }}">
                                @include('library.playlist-cards', ['playlists' => $homePlaylists, 'eagerFirst' => $showFeatured || $showLatest ? 0 : $firstScreenCards, 'highFirst' => !$user->banner])
                            </div>
                        </section>
                    @endif
                @endif
            </div>

            {{-- Videos: the cards load when the tab is first opened, then page with "Show more videos" --}}
            <div class="mk-channel__pane" id="channelVideos" x-show="tab === 'videos'" style="display: none">
                @if($totalVideos > 0)
                    <div class="mk-section-head">
                        {{-- one click handler for the three sorts (pickSort); the script marks the chosen one (markSort) --}}
                        <div class="mk-seg" role="group" aria-label="{{ __('Sort videos') }}" @click="pickSort($event)">
                            <button type="button" class="mk-seg__item is-active" aria-pressed="true" data-sort="latest">{{ __('Latest') }}</button>
                            <button type="button" class="mk-seg__item" aria-pressed="false" data-sort="popular">{{ __('Popular') }}</button>
                            <button type="button" class="mk-seg__item" aria-pressed="false" data-sort="oldest">{{ __('Oldest') }}</button>
                        </div>
                        <span class="mk-muted mk-channel__count">{{ trans_choice('videos', $totalVideos, ['count' => \App\Support\LocalNumber::short($totalVideos)]) }}</span>
                    </div>
                    {{-- the admin's "Videos per row" (grid_columns, the layout's --avd-grid-columns), as 1.2.2's Videos tab used the site's grid --}}
                    <div class="mk-cards mk-cards--cols mk-cards--{{ $videosLayout }}" style="--mk-cols: var(--avd-grid-columns, 4)" data-part-list="videos" x-show="loaded.videos" :aria-busy="busy.videos ? 'true' : 'false'"></div>
                    {{-- skeleton cards while the first page loads: the script draws data-skel of them from #mkChannelSkel --}}
                    <div class="mk-cards mk-cards--cols mk-channel__skeleton" style="--mk-cols: var(--avd-grid-columns, 4)" x-show="!loaded.videos && failed !== 'videos'" aria-hidden="true" data-skel="{{ min(8, $totalVideos) }}"></div>
                    <div class="mk-loadmore" x-show="loaded.videos && next.videos" style="display: none">
                        <button type="button" class="mk-btn mk-btn--secondary" @click="more('videos')" :aria-busy="busy.videos ? 'true' : 'false'"><span>{{ __('Show more videos') }}</span></button>
                    </div>
                @else
                    <section class="mk-panel"><x-mk.empty compact icon="video-slash" :title="__('No videos yet')" :text="$isOwner ? __('Videos you make public appear here, newest first.') : __('This channel has not shared any videos. Subscribe to hear when it does.')" /></section>
                @endif
            </div>

            {{-- Playlists: every public playlist, a page at a time (QA channel-2) --}}
            <div class="mk-channel__pane" id="channelPlaylists" x-show="tab === 'playlists'" style="display: none">
                @if($totalPlaylists > 0)
                    <div class="mk-section-head">
                        <h2 class="mk-panel__title">{{ trans_choice('playlists', $totalPlaylists, ['count' => \App\Support\LocalNumber::short($totalPlaylists)]) }}</h2>
                    </div>
                    <div class="mk-cards mk-cards--cols" style="--mk-cols: {{ max(3, $playlistColumns) }}" data-part-list="playlists" x-show="loaded.playlists"></div>
                    <div class="mk-cards mk-cards--cols mk-channel__skeleton" style="--mk-cols: {{ max(3, $playlistColumns) }}" x-show="!loaded.playlists && failed !== 'playlists'" aria-hidden="true" data-skel="{{ min(6, $totalPlaylists) }}"></div>
                    <div class="mk-loadmore" x-show="loaded.playlists && next.playlists" style="display: none">
                        <button type="button" class="mk-btn mk-btn--secondary" @click="more('playlists')" :aria-busy="busy.playlists ? 'true' : 'false'"><span>{{ __('Show more playlists') }}</span></button>
                    </div>
                @else
                    <section class="mk-panel"><x-mk.empty compact icon="rectangle-list" :title="__('No playlists yet')" :text="$isOwner ? __('Public playlists you make in the studio appear here.') : __('This channel has not shared a playlist yet.')" /></section>
                @endif
            </div>

            @if($showAbout)
                {{-- About: loaded when it is first opened (?part=about: library/channel-about), like Videos and Playlists --}}
                <div class="mk-channel__pane" id="channelAbout" x-show="tab === 'about'" style="display: none">
                    <div data-part-list="about" x-show="loaded.about"></div>
                    <div class="mk-channel__wait" x-show="!loaded.about && failed !== 'about'" aria-hidden="true"><span class="mk-spinner"></span></div>
                </div>
            @endif
        </div>

    </div>
    @php
        // between tags: one space; inside a tag: the template's line breaks and indents between attributes become one
        // space (quoted values are kept exactly as they are, so no text or address changes)
        $channelRoot = preg_replace('/>\s+</', '> <', (string) ob_get_clean());
        $channelRoot = preg_replace_callback(
            '/<[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>/',
            static fn (array $tag): string => (string) preg_replace('/\s+(\/?>)$/', '$1', (string) preg_replace_callback(
                '/("[^"]*"|\'[^\']*\')|\s+/',
                static fn (array $part): string => isset($part[1]) && $part[1] !== '' ? $part[1] : ' ',
                $tag[0]
            )),
            (string) $channelRoot
        );
    @endphp
    {!! $channelRoot !!}
    {{-- the page's script (its tabs): resources/js/member/library-pages.js, one cached file (R1-069) --}}
    @include('library.pages-script')
@endsection

{{-- The share dialog sits at the end of the page, as Bootstrap wants a dialog (no ancestor of the page can trap it), and
     outside the page root, whose content budget (4.3) it would use up --}}
@push('scripts')
    <x-library.share id="mkShareChannel" :url="$user->route" :title="$user->username" :heading="__('Share channel')" :label="__('Channel address')" />
    {{-- A tab whose first page could not load says so with Try again (6.15), instead of keeping its skeleton: the script
         puts this under the tab strip. It carries the page's generic error sentence (data-message-error, also read by
         library/actions-script); both are kept out of the page root, like the skeleton card below: its content budget,
         4.3 --}}
    <template id="mkChannelFailed" data-message-error="{{ __('That did not work. Check your connection and try again.') }}"><div class="mk-alert mk-alert--danger mk-channel__failed" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><div class="mk-alert__body"><span data-failed-text></span></div><button type="button" class="mk-btn mk-btn--secondary mk-btn--sm" data-failed-retry><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{{ __('Try again') }}</span></button></div></template>
    <template id="mkChannelSkel"><span class="mk-card mk-card--skel"><span class="mk-thumb mk-skel"></span><span class="mk-skel mk-skel--title"></span><span class="mk-skel mk-skel--line"></span></span></template>
@endpush

