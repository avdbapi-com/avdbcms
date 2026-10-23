{{-- Vertical shelf (shorts, stories_shorts): six trending videos as 9:16 tiles with their views; stories_shorts
     adds a row of the creators behind them, filled up from the home's creator spotlight. Phones keep three tiles
     (CSS). --}}
@php
    $shortsVideos = $heroVideos->take(6)->values();
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
        <h2 class="avd-ttl" id="avdShortsTitle">{{ __(\App\Support\ThemeStructure::voice('shorts')) }}</h2>
        @if(config('site.show_trending', true))
            <a class="avd-discovery-link" href="{{ locale_url(route('pages.trend')) }}">{{ __('See All') }}</a>
        @endif
    </div>
    <div class="avd-shorts">
        @foreach($shortsVideos as $index => $video)
            <a class="avd-sh text-reset text-decoration-none" href="{{ $video->route }}" data-avd-card>
                <img src="{{ $video->thumbnail_url }}" alt="" width="360" height="640" decoding="async"
                     loading="{{ $index < 3 ? 'eager' : 'lazy' }}" @if($index === 0) fetchpriority="high" @endif>
                <span class="avd-views"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ \App\Helpers\Number::humanize((int) $video->views) }}</span>
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
