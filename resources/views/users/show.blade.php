@use('Illuminate\Support\Str')

@extends('layouts.default')

@php
    $channel = $user->channelCustomization();
    $featuredColumns = max(1, min(6, (int) ($channel['featured_columns'] ?? 3)));
    $featuredRows = max(1, min(4, (int) ($channel['featured_rows'] ?? 1)));
    $featuredLimit = $featuredColumns * $featuredRows;
    $adPos = \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6));
    $adsActive = config('site.ads_enabled') && (!config('site.ads_hide_for_premium', true) || !auth()->user()?->is_premium);

    $adFeaturedCode = trim((string) config('site.ad_code_grid_channel_home_featured', ''));
    $adFeaturedLayout = (string) config('site.ad_grid_layout_channel_home_featured', 'inherit');
    $adFeaturedLayout = $adFeaturedLayout === 'inherit' ? (string) config('site.ad_grid_layout', 'grid_card') : $adFeaturedLayout;
    $hasFeaturedAd = $adsActive && $adFeaturedCode !== '' && $adFeaturedLayout === 'grid_card';

    $pinnedVideo = $user->pinned_video;
    $featuredVideos = match ($channel['featured_mode']) {
        'popular' => $user->videos->sortByDesc('views'),
        'latest' => $user->videos,
        default => $pinnedVideo
            ? collect([$pinnedVideo])->merge($user->videos->reject(fn ($video) => (int) $video->id === (int) $pinnedVideo->id))
            : $user->videos,
    };
    // "Ad after every N": the card ad takes the place after N videos when the grid has room for it and a video is
    // left to follow it (App\Support\FeedAdSlots::gridAdAfter); the grid then shows one video less.
    $featuredAdAfter = $hasFeaturedAd ? \App\Support\FeedAdSlots::gridAdAfter($featuredLimit, $featuredVideos->count(), $adPos) : 0;
    $featuredVideos = $featuredVideos->take($featuredAdAfter > 0 ? $featuredLimit - 1 : $featuredLimit)->values();
    $featuredVideo = $featuredVideos->first();
    $featuredVideoIds = $featuredVideos->pluck('id')->map(fn ($id) => (int) $id)->all();

    $latestColumns = max(1, min(6, (int) ($channel['latest_uploads_columns'] ?? 3)));
    $latestRows = max(1, min(6, (int) ($channel['latest_uploads_rows'] ?? 1)));
    $latestLimit = $latestColumns * $latestRows;

    $adLatestCode = trim((string) config('site.ad_code_grid_channel_home_latest', ''));
    $adLatestLayout = (string) config('site.ad_grid_layout_channel_home_latest', 'inherit');
    $adLatestLayout = $adLatestLayout === 'inherit' ? (string) config('site.ad_grid_layout', 'grid_card') : $adLatestLayout;
    $hasLatestAd = $adsActive && $adLatestCode !== '' && $adLatestLayout === 'grid_card';

    $latestVideoSource = $channel['dedupe_home_sections'] === '1'
        ? $user->videos->reject(fn ($video) => in_array((int) $video->id, $featuredVideoIds, true))
        : $user->videos;
    $latestAdAfter = $hasLatestAd ? \App\Support\FeedAdSlots::gridAdAfter($latestLimit, $latestVideoSource->count(), $adPos) : 0;
    $latestVideos = $latestVideoSource->take($latestAdAfter > 0 ? $latestLimit - 1 : $latestLimit)->values();
    $playlistColumns = max(1, min(4, (int) ($channel['playlists_columns'] ?? 1)));
    $playlistRows = max(1, min(6, (int) ($channel['playlists_rows'] ?? 3)));
    $playlistLimit = $playlistColumns * $playlistRows;
    $playlistVideoColumns = max(1, min(6, (int) ($channel['playlist_video_columns'] ?? 3)));
    $playlistVideoRows = max(1, min(4, (int) ($channel['playlist_video_rows'] ?? 1)));
    $playlistVideoLimit = $playlistVideoColumns * $playlistVideoRows;
    $channelPlaylists = $user->playlists->take($playlistLimit)->values();
    $showStats = $channel['show_stats'] === '1' && config('site.channel_customization_stats_enabled', true);
    $showFeatured = $channel['show_featured_video'] === '1' && config('site.channel_customization_featured_video_enabled', true) && $featuredVideos->isNotEmpty();
    $showLatestUploads = $channel['show_latest_uploads'] === '1' && config('site.channel_customization_latest_uploads_enabled', true) && $latestVideos->isNotEmpty();
    $showPlaylists = $channel['show_playlists'] === '1' && config('site.channel_customization_playlists_enabled', true) && $channelPlaylists->isNotEmpty();
    $showAboutPanel = $channel['show_about_panel'] === '1' && config('site.channel_customization_sections_enabled', true);
    $showSocialLinks = $channel['show_social_links'] === '1' && config('site.channel_customization_socials_enabled', true);
    $showUploadRhythm = $channel['show_upload_rhythm'] === '1' && config('site.channel_customization_upload_rhythm_enabled', true);
    $showSubscribeCta = $channel['show_subscribe_cta'] === '1' && config('site.channel_customization_cta_enabled', true);
    $totalVideos = (int) ($user->active_videos_count ?? $user->videos->count());
    $totalPlaylists = (int) ($user->active_playlists_count ?? $user->playlists->count());
    $totalViews = (int) ($user->videos_views_count ?? 0);
    $totalComments = (int) ($user->videos_comments_count ?? 0);
    $latestVideo = $user->videos->first();
    $websiteHref = $user->website ? (Str::startsWith($user->website, ['http://', 'https://']) ? $user->website : '//' . $user->website) : null;
    $customAccentStyle = $channel['accent'] === 'custom' ? '--channel-accent:' . \App\Support\ChannelCustomizationSettings::sanitizeColor($channel['custom_accent']) . ';' : '';
    $seoService = app(\App\Services\SeoService::class);
    $profileDescription = Str::limit($user->description ?: $channel['tagline'], 155, '');
    $profileSeoValues = [
        'username' => $user->username,
        'user' => $user->username,
        'title' => $user->username,
        'description' => $profileDescription,
        'videos_count' => (string) $totalVideos,
        'playlists_count' => (string) $totalPlaylists,
        'views_count' => (string) $totalViews,
        'subscribers_count' => (string) (int) ($user->subscribers_count ?? 0),
    ];
@endphp

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

@section('class', 'px-0')
@section('style', 'margin-top: 0 !important')

@section('content')
    <div
        class="avd-channel-page"
        data-channel-layout="{{ $channel['layout'] }}"
        data-channel-theme="{{ $channel['theme'] }}"
        data-channel-accent="{{ $channel['accent'] }}"
        data-channel-surface="{{ $channel['surface'] }}"
        data-channel-banner="{{ $channel['banner_treatment'] }}"
        data-channel-avatar="{{ $channel['avatar_frame'] }}"
        data-channel-motion="{{ config('site.channel_customization_motion_enabled', true) ? $channel['motion'] : 'none' }}"
        data-channel-effects="{{ config('site.channel_customization_effects_enabled', true) ? 'on' : 'off' }}"
        data-channel-pattern="{{ config('site.channel_customization_pattern_enabled', true) ? $channel['pattern'] : 'clean' }}"
        data-channel-shape="{{ config('site.channel_customization_shape_enabled', true) ? $channel['header_shape'] : 'flat' }}"
        data-channel-stats="{{ $channel['stats_style'] }}"
        data-channel-density="{{ $channel['density'] }}"
        data-channel-video-layout="{{ $channel['video_layout'] }}"
        style="{{ $customAccentStyle }}"
    >
        <section class="avd-channel-hero">
            <div class="avd-channel-banner-wrap">
                <img class="avd-channel-banner" src="{{$user->banner_url}}" alt="{{$user->username}} banner" onerror="this.onerror=null;this.src='{{ asset('images/default_banner.jpg') }}';">
                <div class="avd-channel-banner-pattern" aria-hidden="true"></div>
            </div>
            <div class="avd-channel-shell">
                <div class="avd-channel-identity">
                    <a class="avd-channel-avatar-wrap avd-avatar-frame" href="{{$user->avatar_url}}" aria-label="{{ __('Open channel avatar') }}">
                        <img class="avd-channel-avatar-img avd-avatar-media" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar" width="120" height="120" loading="eager" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                    </a>
                    <div class="avd-channel-copy flex-grow-1">
                        <div class="avd-channel-kicker"><i class="fa-solid fa-wand-magic-sparkles"></i>{{ __('Channel') }}</div>
                        <h1 class="avd-channel-title">{{$user->username}}</h1>
                        @if($channel['tagline'])
                            <p class="avd-channel-tagline">{{ $channel['tagline'] }}</p>
                        @elseif($user->description)
                            <p class="avd-channel-tagline">{{ Str::limit($user->description, 150) }}</p>
                        @endif
                        @if($showStats)
                            <div class="avd-channel-stats">
                                @if($user->show_subscribers && config('site.show_subscriber_count', true))
                                    @php
                                        $uSubCount = (int) ($user->subscribers_count ?? 0);
                                    @endphp
                                    <span><i class="fa-solid fa-users"></i>{{ trans_choice('subscribers', $uSubCount, ['count' => \App\Helpers\Number::humanize($uSubCount)]) }}</span>
                                @endif
                                <span><i class="fa-solid fa-video"></i>{{ trans_choice('videos', $totalVideos, ['count' => \App\Helpers\Number::humanize($totalVideos)]) }}</span>
                                <span><i class="fa-solid fa-eye"></i>{{ trans_choice('views', $totalViews, ['count' => \App\Helpers\Number::humanize($totalViews)]) }}</span>
                                <span><i class="fa-solid fa-comment"></i>{{ trans_choice('comments', $totalComments, ['count' => \App\Helpers\Number::humanize($totalComments)]) }}</span>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="avd-channel-actions d-flex flex-wrap gap-2">
                    @if($showSocialLinks && $websiteHref)
                        <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-2" href="{{$websiteHref}}" rel="external nofollow" target="_blank">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            <span>{{ __('Website') }}</span>
                        </a>
                    @endif
                    @if($showSubscribeCta && $channel['cta_label'] && $channel['cta_url'])
                        <a class="btn btn-primary d-inline-flex align-items-center gap-2" href="{{ $channel['cta_url'] }}" rel="external nofollow" target="_blank">
                            <i class="fa-solid fa-bolt"></i>
                            <span>{{ $channel['cta_label'] }}</span>
                        </a>
                    @endif
                    @if(Auth::check() && Auth::user()->isNot($user))
                        <subscribe-button
                            @if(!$is_subscribed) is-subscribe @endif
                            user="{{$user->id}}"
                            class="avd-subscribe-host"
                        ></subscribe-button>
                    @elseif(!Auth::check())
                        <button
                            type="button"
                            tabindex="0"
                            class="avd-subscribe-button btn btn-dark rounded-4 px-3 d-inline-flex align-items-center justify-content-center text-nowrap"
                            data-bs-toggle="popover"
                            data-bs-placement="left"
                            data-bs-title="{{ __('Want to subscribe to this channel ?') }}"
                            data-bs-trigger="focus"
                            data-bs-html="true"
                            data-bs-content="{{ __('Sign in to subscribe to this channel.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{ __('Sign In') }}</a>"
                        >
                            <i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>
                            <span>{{ __('Subscribe') }}</span>
                        </button>
                    @else
                        <a href="{{route('user.edit')}}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <span>{{ __('Customize channel') }}</span>
                        </a>
                        <a href="{{route('user.videos.index')}}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-video"></i>
                            <span>{{ __('Manage videos') }}</span>
                        </a>
                    @endif
                </div>
            </div>
        </section>

        <div class="avd-channel-shell">
            @if($showUploadRhythm || $showAboutPanel)
                <div class="avd-channel-info-strip">
                    @if($showUploadRhythm)
                        <div class="avd-channel-info-card">
                            <i class="fa-solid fa-wave-square"></i>
                            <div>
                                <span>{{ __('Upload rhythm') }}</span>
                                <strong>{{ $latestVideo ? ($latestVideo->published_at?->diffForHumans() ?? $latestVideo->created_at?->diffForHumans() ?? __('Recently')) : __('No videos yet') }}</strong>
                            </div>
                        </div>
                    @endif
                    @if($showAboutPanel)
                        <div class="avd-channel-info-card">
                            <i class="fa-solid fa-calendar"></i>
                            <div>
                                <span>{{ __('Joined') }}</span>
                                <strong>{{$user->created_at->translatedFormat('d F Y')}}</strong>
                            </div>
                        </div>
                        @if($user->country)
                            <div class="avd-channel-info-card">
                                <i class="fa-solid fa-location-dot"></i>
                                <div>
                                    <span>{{ __('Country') }}</span>
                                    <strong>{{$user->country_name}}</strong>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            <div class="avd-channel-tabs-wrap">
                <ul class="nav avd-channel-tabs flex-nowrap overflow-auto" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a id="home-tab" href="#home" class="nav-link user-tabs active" data-bs-toggle="tab" data-bs-target="#home" role="tab" aria-controls="home" aria-selected="true">
                            <i class="fa-solid fa-house" aria-hidden="true"></i>{{ __('Home') }}
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a id="videos_link" href="#videos" class="nav-link user-tabs" data-bs-toggle="tab" data-bs-target="#videos" role="tab" aria-controls="videos" aria-selected="false">
                            <i class="fa-solid fa-play" aria-hidden="true"></i>{{ __('Videos') }}
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a id="playlists_link" href="#playlists" class="nav-link user-tabs" data-bs-toggle="tab" data-bs-target="#playlists" role="tab" aria-controls="playlists" aria-selected="false">
                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>{{ __('Playlists') }}
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a id="about-tab" href="#about" class="nav-link user-tabs" data-bs-toggle="tab" data-bs-target="#about" role="tab" aria-controls="about" aria-selected="false">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>{{ __('About') }}
                        </a>
                    </li>
                </ul>
            </div>

            <div class="tab-content avd-channel-content">
                <div class="tab-pane active" id="home" role="tabpanel" aria-labelledby="home-tab">
                    @if($user->videos->isEmpty() && $user->playlists->isEmpty())
                        <div class="avd-channel-empty">{{ __('Channel without content') }}</div>
                    @elseif(!$showFeatured && !$showLatestUploads && !$showPlaylists)
                        <div class="avd-channel-empty">{{ __('Home sections are hidden') }}</div>
                    @else
                        @if($showFeatured)
                            <section
                                class="avd-channel-featured"
                                data-channel-featured-layout="{{ $channel['featured_layout'] }}"
                                data-channel-featured-columns="{{ $featuredColumns }}"
                                data-channel-featured-rows="{{ $featuredRows }}"
                            >
                                <div class="avd-channel-section-heading">
                                    <div>
                                        <span>{{ __('Featured') }}</span>
                                        <h2>{{ __('Featured videos') }}</h2>
                                    </div>
                                    <a href="{{$featuredVideo->route}}" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2">
                                        <i class="fa-solid fa-play"></i>
                                        <span>{{ __('Watch first') }}</span>
                                    </a>
                                </div>
                                <div class="row gx-3 gy-3 gy-sm-4 avd-channel-featured-grid">
                                    @foreach($featuredVideos as $video)
                                        @include('videos.card', ['video' => $video])
                                        @if($loop->iteration === $featuredAdAfter)
                                            <x-feed-ad-slot context="channel_home_featured" class="col avd-grid-ad-col" />
                                        @endif
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if($showLatestUploads)
                            <section
                                class="avd-channel-section avd-channel-latest"
                                data-channel-latest-layout="{{ $channel['latest_uploads_layout'] }}"
                                data-channel-latest-columns="{{ $latestColumns }}"
                                data-channel-latest-rows="{{ $latestRows }}"
                            >
                                <div class="avd-channel-section-heading">
                                    <div>
                                        <span>{{ __('Latest uploads') }}</span>
                                        <h2>{{ __('Videos') }}</h2>
                                    </div>
                                    <button
                                        class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
                                        onclick="new bootstrap.Tab('#videos_link').show()"
                                    >
                                        <i class="fa-solid fa-play"></i>
                                        <span>{{ __('See All') }}</span>
                                    </button>
                                </div>
                                <div class="row gx-3 gy-3 gy-sm-4 avd-channel-video-grid avd-channel-latest-grid">
                                    @foreach($latestVideos as $video)
                                        @include('videos.card', ['video' => $video])
                                        @if($loop->iteration === $latestAdAfter)
                                            <x-feed-ad-slot context="channel_home_latest" class="col avd-grid-ad-col" />
                                        @endif
                                    @endforeach
                                </div>
                            </section>
                        @endif
                        @if($showPlaylists)
                            <section
                                class="avd-channel-section avd-channel-playlists"
                                data-channel-playlists-layout="{{ $channel['playlists_layout'] }}"
                                data-channel-playlists-columns="{{ $playlistColumns }}"
                                data-channel-playlists-rows="{{ $playlistRows }}"
                                data-channel-playlist-video-columns="{{ $playlistVideoColumns }}"
                                data-channel-playlist-video-rows="{{ $playlistVideoRows }}"
                            >
                                <div class="avd-channel-section-heading">
                                    <div>
                                        <span>{{ __('Curated sets') }}</span>
                                        <h2>{{ __('Playlists') }}</h2>
                                    </div>
                                    <button
                                        class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2"
                                        onclick="new bootstrap.Tab('#playlists_link').show()"
                                    >
                                        <i class="fa-solid fa-layer-group"></i>
                                        <span>{{ __('See All') }}</span>
                                    </button>
                                </div>
                                <div class="avd-channel-playlist-grid">
                                    @foreach($channelPlaylists as $playlist)
                                        <div class="avd-channel-playlist">
                                            <div class="avd-channel-playlist-head">
                                                <div>
                                                    <h3>{{Str::limit($playlist->title, 80)}}</h3>
                                                    <p>{{Str::limit($playlist->description, 200)}}</p>
                                                </div>
                                                @if($playlist->first_video)
                                                    <a class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2" href="{{$playlist->first_video->routeWithParams(['list' => $playlist->uuid])}}">
                                                        <i class="fa-solid fa-play"></i>
                                                        <span>{{ __('See All') }}</span>
                                                    </a>
                                                @endif
                                            </div>
                                            <div class="row gx-3 gy-3 gy-sm-4 avd-channel-video-grid avd-channel-playlist-video-grid">
                                                @foreach($playlist->videos->take($playlistVideoLimit) as $video)
                                                    @include('videos.card', ['video' => $video])
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    @endif
                </div>
                <div class="tab-pane" id="videos" role="tabpanel" aria-labelledby="videos_link">
                    @if($user->videos->isNotEmpty())
                        <user-videos user="{{$user->id}}" />
                    @else
                        <div class="avd-channel-empty">{{ __('This channel has no videos') }}</div>
                    @endif
                </div>
                <div class="tab-pane" id="playlists" role="tabpanel" aria-labelledby="playlists_link">
                    @if($user->playlists->isNotEmpty())
                        <div class="row gx-3 gy-4 mt-0">
                            @each('playlists.card', $user->playlists, 'playlist')
                        </div>
                    @else
                        <div class="avd-channel-empty">{{ __('This channel has no playlists') }}</div>
                    @endif
                </div>
                <div class="tab-pane" id="about" role="tabpanel" aria-labelledby="about-tab">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="avd-channel-panel">
                                <h2>{{ __('Description') }}</h2>
                                <x-expand-item max="1000">
                                    {!! nl2br(e($user->description)) !!}
                                </x-expand-item>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="avd-channel-panel">
                                <h2>{{ __('Information') }}</h2>
                                <ul class="avd-channel-info-list">
                                    <li><span>{{ __('Joined') }}</span><strong>{{$user->created_at->translatedFormat('d F Y')}}</strong></li>
                                    <li><span>{{ __('Views') }}</span><strong>{{trans_choice('views', $totalViews)}}</strong></li>
                                    @if($user->country)
                                        <li><span>{{ __('Country')}}</span><strong>{{$user->country_name}}</strong></li>
                                    @endif
                                    @if($websiteHref)
                                        <li><span>{{ __('Website') }}</span><a rel="external nofollow" target="_blank" href="{{$websiteHref}}">{{$user->website}}</a></li>
                                    @endif
                                    <li><span>{{ __('Channel URL') }}</span><strong>{{ config('app.url') . '/@' . $user->username }}</strong></li>
                                </ul>
                                <div class="mt-3">
                                    @auth
                                        @can('report', $user)
                                            <report-button
                                                button-class="btn bg-light-dark rounded-4 btn-sm px-3"
                                                reported-class="rounded-4 d-flex alert alert-secondary px-3 py-2 align-items-center gap-2 mb-0 text-sm"
                                                id="{{$user->id}}"
                                                type="user"
                                            ></report-button>
                                        @else
                                            @if($user->reportByAuthUser)
                                                <div class="rounded-4 d-flex alert alert-secondary px-3 py-2 align-items-center gap-2 mb-0 text-sm">
                                                    <i class="fa-regular fa-flag"></i>
                                                    <span>{{ __('Reported') }} {{$user->reportByAuthUser->created_at->diffForHumans()}}</span>
                                                </div>
                                            @endif
                                        @endcan
                                    @else
                                        <button
                                            type="button"
                                            tabindex="0"
                                            class="btn bg-light-dark rounded-4 btn-sm px-3"
                                            data-bs-toggle="popover"
                                            data-bs-placement="right"
                                            data-bs-title="{{ __('Need to report this user ?') }}"
                                            data-bs-trigger="focus"
                                            data-bs-html="true"
                                            data-bs-content="{{ __('Sign in to report inappropriate content.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{ __('Sign In') }}</a>"
                                        >
                                            <i class="fa-regular fa-flag"></i>&nbsp;
                                            {{ __('Report') }}
                                        </button>
                                    @endauth
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
