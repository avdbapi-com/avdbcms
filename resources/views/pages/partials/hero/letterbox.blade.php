{{-- Letterbox (Silver Screen): the featured video in a 2.39:1 frame over centred credits. --}}
@php
    $letterboxVideo = $heroVideos->first();
    $letterboxYear = ($letterboxVideo->published_at ?? $letterboxVideo->created_at)?->format('Y');
@endphp
@push('preload')
    <link rel="preload" as="image" href="{{ $letterboxVideo->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--letterbox" aria-labelledby="avdLetterboxTitle">
    <div class="avd-lb">
        <div class="avd-frame">
            <a class="d-block h-100" href="{{ $letterboxVideo->route }}" tabindex="-1" aria-hidden="true">
                <img src="{{ $letterboxVideo->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async">
            </a>
        </div>
    </div>
    <div class="avd-credits">
        <div class="avd-page-kicker mb-0">{{ __(\App\Support\ThemeStructure::voice('kicker')) }}</div>
        <h2 @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($letterboxVideo->title)]) id="avdLetterboxTitle">
            <a class="text-reset text-decoration-none" href="{{ $letterboxVideo->route }}">{{ $letterboxVideo->title }}</a>
        </h2>
        <div class="avd-by">
            @if($letterboxVideo->user)
                {{ __('By :channel', ['channel' => $letterboxVideo->user->username]) }}
            @endif
            @if($letterboxYear)
                <span aria-hidden="true">·</span> {{ $letterboxYear }}
            @endif
            @if($letterboxVideo->duration)
                <span aria-hidden="true">·</span> {{ $letterboxVideo->duration }}
            @endif
        </div>
        <div class="avd-hbtns">
            <a class="btn btn-primary" href="{{ $letterboxVideo->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Watch') }}</a>
            <button type="button" class="btn btn-outline-secondary avd-btn2" data-watch-later-id="{{ $letterboxVideo->id }}"
                    onclick="window.avdQuickSave && window.avdQuickSave('{{ $letterboxVideo->id }}');">
                <i class="fa-regular fa-bookmark" aria-hidden="true"></i> {{ __('Save') }}
            </button>
        </div>
    </div>
</section>
