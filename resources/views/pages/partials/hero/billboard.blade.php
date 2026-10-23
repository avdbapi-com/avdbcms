{{-- Billboard carousel (billboard_inset, billboard_full, billboard_fade, billboard_leaf, billboard_horizon with its
     striped sun; split_orbit = Interstellar's starfield billboard on the cinema-rows home, the Deep Space Theater
     look). The full and fade variants sit under a transparent header, so they declare the overlay hero; the theme's
     rank shelf (Top 10) follows as the after-hero on wider screens. Slides and dots are driven by
     resources/js/layout/core.ts ([data-avd-carousel]); without script the first slide shows and the track scrolls. --}}
@php
    $billboardSlides = $heroVideos->take(5)->values();
    $billboardKicker = \App\Support\ThemeStructure::voice('kicker');
    $billboardAutoplay = filter_var(config('site.hero_slider_autoplay_enabled', true), FILTER_VALIDATE_BOOLEAN)
        ? max(3, min(15, (int) config('site.hero_slider_autoplay_seconds', 5)))
        : 0;
    $billboardRankShelf = \App\Support\ThemeStructure::get('rank_shelf') ? $heroVideos->take(10)->values() : collect();
    $billboardQuality = function ($video): string {
        $height = (int) ($video->height ?? 0);

        return $video->xvid_quality ?: ($height >= 2160 ? '4K' : ($height >= 720 ? 'HD' : ''));
    };
@endphp
@if(in_array($heroDeco, ['billboard_full', 'billboard_fade'], true))
    @section(\App\Support\ThemeStructure::OVERLAY_SECTION, '1')
@endif
@push('preload')
    <link rel="preload" as="image" href="{{ $billboardSlides->first()->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--billboard" aria-roledescription="{{ __('carousel') }}" aria-label="{{ __('Featured videos') }}"
         data-avd-carousel data-avd-carousel-autoplay="{{ $billboardAutoplay }}" tabindex="0">
    <div class="avd-bb-track" data-avd-carousel-track>
        @foreach($billboardSlides as $index => $video)
            <article class="avd-hero-photo avd-bb" data-avd-carousel-slide aria-roledescription="{{ __('slide') }}"
                     aria-label="{{ __(':current of :total', ['current' => $index + 1, 'total' => $billboardSlides->count()]) }}">
                <img src="{{ $video->thumbnail_url }}" alt="" width="1280" height="720" decoding="async"
                     loading="{{ $index === 0 ? 'eager' : 'lazy' }}" @if($index === 0) fetchpriority="high" @endif>
                <div class="avd-scrim" aria-hidden="true"></div>
                @if($heroDeco === 'billboard_horizon')
                    <span class="avd-sunstripes" aria-hidden="true"></span>
                @endif
                <div class="avd-content">
                    <div class="avd-hmeta">
                        <span class="avd-badge">{{ __($billboardKicker) }}</span>
                        <span>{{ trans_choice('views', (int) $video->views, ['count' => \App\Helpers\Number::humanize((int) $video->views)]) }}</span>
                        @if($video->duration)
                            <span aria-hidden="true">·</span><span>{{ $video->duration }}</span>
                        @endif
                        @if($quality = $billboardQuality($video))
                            <span aria-hidden="true">·</span><span>{{ $quality }}</span>
                        @endif
                    </div>
                    <h2 @class(['avd-htitle', 'line-clamp-2', 'text-reset', \App\Support\TitleScript::cssClass($video->title)])>
                        <a class="text-reset text-decoration-none" href="{{ $video->route }}">{{ $video->title }}</a>
                    </h2>
                    @if($video->description)
                        <p class="avd-desc2 d-none d-md-block">{{ Str::limit(strip_tags($video->description), 150) }}</p>
                    @endif
                    <div class="avd-hbtns">
                        <a @class(['btn btn-lg', 'avd-play-btn' => $heroDeco === 'billboard_full', 'btn-primary' => $heroDeco !== 'billboard_full']) href="{{ $video->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Play') }}</a>
                        @if($video->user)
                            <a class="btn btn-lg btn-outline-secondary avd-btn2" href="{{ $video->user->route }}"><i class="fa-solid fa-circle-user" aria-hidden="true"></i> {{ $video->user->username }}</a>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>
    @if($billboardSlides->count() > 1)
        <div class="avd-dots" role="group" aria-label="{{ __('Choose a slide') }}">
            @foreach($billboardSlides as $index => $video)
                <button type="button" class="avd-dot-btn" data-avd-carousel-dot="{{ $index }}" aria-label="{{ __('Show slide :number', ['number' => $index + 1]) }}"
                        @if($index === 0) aria-current="true" @endif><i @class(['active' => $index === 0])></i></button>
            @endforeach
        </div>
    @endif
</section>
@if($billboardRankShelf->count() >= 3)
    <div class="avd-after-hero d-none d-md-block">
        <x-theme.section-head :title="__('Top 10 today')" />
        <div class="avd-rank-shelf">
            @foreach($billboardRankShelf as $index => $video)
                <a class="avd-rk-it text-reset text-decoration-none" href="{{ $video->route }}" title="{{ $video->title }}" data-avd-card>
                    <span class="avd-num" aria-hidden="true">{{ $index + 1 }}</span>
                    <div class="avd-post"><img src="{{ $video->thumbnail_url }}" alt="{{ $video->title }}" width="128" height="192" loading="lazy" decoding="async"></div>
                </a>
            @endforeach
        </div>
    </div>
@endif
