{{-- Bento hero. Tiles (bento, bento_glass, bento_holo, bento_pulse): a big lead tile, a second video, the lead
     video's channel and a wide third video. bento_swiss: lead + a numbered list of four; bento_gallery: framed prints
     beside the exhibition text. --}}
@php
    $bentoVideos = $heroVideos->take($heroDeco === 'bento_swiss' ? 5 : 4)->values();
    $bentoLead = $bentoVideos->first();
    $bentoKicker = __(\App\Support\ThemeStructure::voice('kicker'));
@endphp
@push('preload')
    <link rel="preload" as="image" href="{{ $bentoLead->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--bento" aria-label="{{ __('Featured videos') }}">
    @if($heroDeco === 'bento_swiss')
        <div class="avd-bento">
            <a class="avd-swl text-reset text-decoration-none" href="{{ $bentoLead->route }}" data-avd-card>
                <div class="avd-ph"><img src="{{ $bentoLead->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async"></div>
                <div @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($bentoLead->title)])>{{ $bentoLead->title }}</div>
                <div class="avd-hmeta"><span class="avd-badge">{{ $bentoKicker }}</span><span>{{ $heroMeta($bentoLead, true) }}</span></div>
            </a>
            <div class="avd-swr">
                <span class="avd-page-kicker mb-0">{{ __(\App\Support\ThemeStructure::voice('side')) }}</span>
                @foreach($bentoVideos->slice(1) as $index => $video)
                    <a class="avd-swi text-reset text-decoration-none" href="{{ $video->route }}" data-avd-rank-row>
                        <span class="avd-n" aria-hidden="true">{{ sprintf('%02d', $index) }}</span>
                        <div class="min-w-0"><b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</b><span>{{ $heroMeta($video, true) }}</span></div>
                    </a>
                @endforeach
            </div>
        </div>
    @elseif($heroDeco === 'bento_gallery')
        <div class="avd-gallery">
            {{-- The print's caption is hidden on phones (bento_gallery.css): the link keeps its name. --}}
            <a class="avd-print text-reset text-decoration-none" href="{{ $bentoLead->route }}" aria-label="{{ __('Play :title', ['title' => $bentoLead->title]) }}" data-avd-card>
                <div class="avd-ph"><img src="{{ $bentoLead->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async"></div>
                <div @class(['avd-cp', 'line-clamp-2', \App\Support\TitleScript::cssClass($bentoLead->title)])>{{ $bentoLead->title }}</div>
            </a>
            <div class="avd-gal-r">
                <div class="avd-exh">
                    <span class="avd-page-kicker mb-0">{{ $bentoKicker }}</span>
                    <h2 @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($bentoLead->title)])><a class="text-reset text-decoration-none" href="{{ $bentoLead->route }}">{{ $bentoLead->title }}</a></h2>
                    <div class="avd-hmeta">{{ $heroMeta($bentoLead, true) }}</div>
                    <div class="avd-hbtns">
                        <a class="btn btn-primary" href="{{ $bentoLead->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Watch Now') }}</a>
                    </div>
                </div>
                <div class="avd-duo">
                    @foreach($bentoVideos->slice(1, 2) as $video)
                        <a class="avd-print text-reset text-decoration-none" href="{{ $video->route }}" aria-label="{{ __('Play :title', ['title' => $video->title]) }}" data-avd-card>
                            <div class="avd-ph"><img src="{{ $video->thumbnail_url }}" alt="" width="640" height="360" loading="lazy" decoding="async"></div>
                            <div @class(['avd-cp', 'line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <div class="avd-bento">
            @foreach($bentoVideos->take(2) as $index => $video)
                <a @class(['avd-bt', 'big' => $index === 0, 'text-reset text-decoration-none']) href="{{ $video->route }}" data-avd-card>
                    <img src="{{ $video->thumbnail_url }}" alt="" width="1280" height="720" decoding="async"
                         loading="{{ $index === 0 ? 'eager' : 'lazy' }}" @if($index === 0) fetchpriority="high" @endif>
                    <div class="avd-cap2">
                        <span class="avd-badge">{{ $index === 0 ? $bentoKicker : ($video->category ? __($video->category->title) : __('Trending')) }}</span>
                        <div @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</div>
                        @if($index === 0)
                            <div class="avd-hmeta">{{ $heroMeta($video, true) }}{{ $video->duration ? ' · '.$video->duration : '' }}</div>
                        @endif
                    </div>
                </a>
            @endforeach
            @if($bentoLead->user)
                <div class="avd-bt">
                    <a class="avd-pad text-reset text-decoration-none" href="{{ $bentoLead->user->route }}">
                        <span class="avd-pad-av"><img class="avd-channel-avatar" src="{{ $bentoLead->user->avatar_url }}" alt="" width="54" height="54" loading="lazy" decoding="async"></span>
                        <b>{{ $bentoLead->user->username }}</b>
                        @if($bentoLead->category)
                            <span class="avd-video-meta">{{ __($bentoLead->category->title) }}</span>
                        @endif
                        <span class="avd-pad-cta mt-auto align-self-start">{{ __('Visit channel') }}</span>
                    </a>
                </div>
            @endif
            @if($wide = $bentoVideos->get(2))
                <a class="avd-bt wide text-reset text-decoration-none" href="{{ $wide->route }}" data-avd-card>
                    <img src="{{ $wide->thumbnail_url }}" alt="" width="1280" height="720" loading="lazy" decoding="async">
                    <div class="avd-cap2">
                        <div @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($wide->title)])>{{ $wide->title }}</div>
                    </div>
                </a>
            @endif
        </div>
    @endif
</section>
