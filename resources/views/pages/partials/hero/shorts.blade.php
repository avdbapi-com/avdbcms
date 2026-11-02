{{-- Vertical shelf (shorts, stories_shorts): six trending videos as 9:16 tiles with their views; stories_shorts
     adds a row of the creators behind them, filled up from the home's creator spotlight. Phones keep three tiles
     (CSS). --}}
@php
    // watch-32: the shelf calls its tiles shorts only when they are (3 minutes or less, from the hero's list, then from
    // the trending shorts the feed service keeps for 3 minutes per language: the query reads every public video, so it
    // never runs on each home view). With fewer than three short videos it shows the trending videos under "Trending",
    // with their length, instead of three-hour videos in short-style tiles.
    $shortMax = \App\Services\PublicVideoFeedService::SHORT_MAX_SECONDS;
    $shortCount = \App\Services\PublicVideoFeedService::HERO_SHORTS_COUNT;
    $isShort = fn ($video): bool => ($seconds = (int) $video->getRawOriginal('duration')) > 0 && $seconds <= $shortMax;
    $shortsVideos = $heroVideos->filter($isShort)->values();
    if ($shortsVideos->count() < $shortCount) {
        $shortsVideos = $shortsVideos
            ->concat(app(\App\Services\PublicVideoFeedService::class)->heroShorts())
            ->unique('id')
            ->take($shortCount)
            ->values();
    }
    $shelfIsShorts = $shortsVideos->count() >= 3;
    $shortsVideos = ($shelfIsShorts ? $shortsVideos : $heroVideos)->take(6)->values();
    $shortsCreators = $heroDeco === 'stories_shorts'
        ? $heroVideos->pluck('user')->filter()->concat($creatorSpotlightCreators ?? collect())->unique('id')->take(8)->values()
        : collect();
@endphp
<section class="avd-hero avd-hero--shorts" aria-labelledby="avdShortsTitle">
    @if($shortsCreators->isNotEmpty())
        <div class="avd-stories">
            @foreach($shortsCreators as $creator)
                <a class="avd-story text-reset text-decoration-none" href="{{ $creator->route }}">
                    <span class="avd-ring"><img class="avd-channel-avatar" src="{{ $creator->avatar_url }}" alt="" width="64" height="64" loading="lazy" decoding="async"></span>
                    {{ Str::limit($creator->username, 12) }}
                </a>
            @endforeach
        </div>
    @endif
    <div class="avd-sh-head">
        <span class="avd-sec-ico" aria-hidden="true"><i class="fa-solid fa-bolt"></i></span>
        <h2 class="avd-ttl" id="avdShortsTitle">{{ $shelfIsShorts ? __(\App\Support\ThemeStructure::voice('shorts')) : __('Trending') }}</h2>
        @if(config('site.show_trending', true))
            <a class="avd-discovery-link" href="{{ locale_url(route('pages.trend')) }}">{{ __('See all') }}</a>
        @endif
    </div>
    <div class="avd-shorts">
        @foreach($shortsVideos as $index => $video)
            <a class="avd-sh text-reset text-decoration-none" href="{{ $video->route }}" data-avd-card>
                <img src="{{ $video->thumbnail_url }}" alt="" width="360" height="640" decoding="async"
                     loading="{{ $index < 3 ? 'eager' : 'lazy' }}" @if($index === 0) fetchpriority="high" @endif>
                <span class="avd-views"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ \App\Helpers\Number::humanize((int) $video->views) }}@if(! $shelfIsShorts && $video->duration) <span aria-hidden="true">&middot;</span> {{ $video->duration }}@endif</span>
                <div class="avd-cap2">
                    <b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</b>
                    @if($video->user)
                        <span>{{ '@'.$video->user->username }}</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
</section>
