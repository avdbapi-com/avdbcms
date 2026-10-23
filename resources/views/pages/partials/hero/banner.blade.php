{{-- Headline banner (banner, banner_soft, banner_zen, banner_pop, banner_window): the owner's intro copy
     (home_intro_*) beside the trending videos (wider screens). banner (Daylight Studio) lists two of them; the
     other decorations show the first one as their picture (soft card, framed print with a seal, tilted sticker
     card, "Now playing" window) with a Watch / Browse pair under the copy. Off when the intro is switched off. --}}
@if($homeIntroEnabled ?? config('site.home_intro_enabled', true))
    @php
        $bannerVideos = $heroVideos->take(2)->values();
        $bannerLead = $bannerVideos->first();
        $bannerKicker = __(config('site.home_intro_kicker', 'Fresh picks'));
        $bannerTitle = __(config('site.home_intro_title', 'Watch what is moving now'));
        $bannerSubtitle = __(config('site.home_intro_subtitle', 'A fast, clean feed for new videos, creators, playlists, and trending moments.'));
        $bannerPicture = $bannerLead !== null && $heroDeco !== 'banner';
        $bannerBrowse = config('site.show_trending', true) ? locale_url(route('pages.trend')) : locale_url(route('pages.categories'));
    @endphp
    <section
        class="avd-home-topbar avd-hero avd-hero--banner"
        data-avd-home-widget="fresh_picks"
        data-avd-motion-direction="{{ isset($homeWidgetMotionDirection) ? $homeWidgetMotionDirection('fresh_picks', 'right') : 'right' }}"
        aria-labelledby="avdHomeTitle"
    >
        <div class="avd-banner">
            @if($heroDeco === 'banner_zen')
                <div class="avd-vkick" aria-hidden="true">{{ __(\App\Support\ThemeStructure::voice('kicker')) }}</div>
            @endif
            @if($heroDeco === 'banner_window')
                <div class="avd-window">
                    <div class="avd-wt"><span>{{ $bannerKicker }}</span><span class="avd-win-btns" aria-hidden="true"><i></i><i></i><i></i></span></div>
                    <div class="avd-wb">
                        <h1 class="avd-home-title avd-htitle" id="avdHomeTitle">{{ $bannerTitle }}</h1>
                        <p class="avd-home-subtitle">{{ $bannerSubtitle }}</p>
                        @if($bannerPicture)
                            @include('pages.partials.hero.banner-buttons')
                        @endif
                    </div>
                </div>
            @else
                <div>
                    <span class="avd-page-kicker mb-0">{{ $bannerKicker }}</span>
                    <h1 class="avd-home-title avd-htitle" id="avdHomeTitle">{{ $bannerTitle }}</h1>
                    <p class="avd-home-subtitle">{{ $bannerSubtitle }}</p>
                    @if($bannerPicture)
                        @include('pages.partials.hero.banner-buttons')
                    @endif
                    @if($heroDeco === 'banner_zen')
                        <div class="avd-seal d-none d-md-grid" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) config('app.name'), 0, 1)) }}</div>
                    @endif
                </div>
            @endif
            @if($bannerPicture)
                @if($heroDeco === 'banner_pop')
                    <div class="avd-pop d-none d-md-block">
                        <a class="avd-pop-card d-block text-reset text-decoration-none" href="{{ $bannerLead->route }}">
                            <div class="avd-ph"><img src="{{ $bannerLead->thumbnail_url }}" alt="" width="640" height="360" loading="lazy" decoding="async"></div>
                            <b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($bannerLead->title)])>{{ $bannerLead->title }}</b>
                        </a>
                        <span class="avd-sticker">{{ $bannerLead->category ? __($bannerLead->category->title) : __('Trending') }}</span>
                        <span class="avd-sticker">{{ trans_choice('views', (int) $bannerLead->views, ['count' => \App\Helpers\Number::humanize((int) $bannerLead->views)]) }}</span>
                    </div>
                @elseif($heroDeco === 'banner_window')
                    <div class="avd-window d-none d-md-block">
                        <div class="avd-wt"><span>{{ __('Now playing') }}</span><span class="avd-win-btns" aria-hidden="true"><i></i><i></i><i></i></span></div>
                        <a class="avd-wnow d-block text-reset text-decoration-none" href="{{ $bannerLead->route }}">
                            <div class="avd-ph"><img src="{{ $bannerLead->thumbnail_url }}" alt="" width="640" height="360" loading="lazy" decoding="async"></div>
                            <b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($bannerLead->title)])>{{ $bannerLead->title }}</b>
                        </a>
                    </div>
                @else
                    <a @class(['d-none d-md-block', 'avd-soft-img' => $heroDeco === 'banner_soft', 'avd-zen-img' => $heroDeco !== 'banner_soft'])
                       href="{{ $bannerLead->route }}" aria-label="{{ __('Play :title', ['title' => $bannerLead->title]) }}">
                        <img src="{{ $bannerLead->thumbnail_url }}" alt="" width="1280" height="720" loading="lazy" decoding="async">
                    </a>
                @endif
            @elseif($bannerVideos->isNotEmpty())
                <div class="avd-cw d-none d-md-grid">
                    <span class="avd-page-kicker mb-0">{{ __('Trending Now') }}</span>
                    @foreach($bannerVideos as $video)
                        <a class="avd-row text-reset text-decoration-none" href="{{ $video->route }}" data-avd-rank-row>
                            <div class="avd-tt">
                                <img src="{{ $video->thumbnail_url }}" alt="" width="120" height="68" loading="lazy" decoding="async">
                            </div>
                            <div class="min-w-0">
                                <b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</b>
                                <span>{{ collect([$video->user?->username, $video->duration])->filter()->implode(' · ') }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
