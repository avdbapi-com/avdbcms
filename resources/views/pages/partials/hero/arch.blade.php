{{-- Arch (Desert Dune, billboard_arch): the story beside the featured video in an arched frame. --}}
@php
    $archVideo = $heroVideos->first();
@endphp
@push('preload')
    <link rel="preload" as="image" href="{{ $archVideo->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--arch" aria-labelledby="avdArchTitle">
    <div>
        <span class="avd-page-kicker mb-0">{{ $archVideo->category ? __($archVideo->category->title) : __(\App\Support\ThemeStructure::voice('kicker')) }}</span>
        <h2 @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($archVideo->title)]) id="avdArchTitle">
            <a class="text-reset text-decoration-none" href="{{ $archVideo->route }}">{{ $archVideo->title }}</a>
        </h2>
        @if($archVideo->description)
            <p>{{ Str::limit(strip_tags($archVideo->description), 150) }}</p>
        @endif
        <div class="avd-hmeta">
            @if($archVideo->user)
                <span class="d-flex flex-shrink-0"><img class="avd-channel-avatar" src="{{ $archVideo->user->avatar_url }}" alt="" width="34" height="34" loading="lazy" decoding="async"></span>
                <b>{{ $archVideo->user->username }}</b>
            @endif
            <span>{{ trans_choice('views', (int) $archVideo->views, ['count' => \App\Helpers\Number::humanize((int) $archVideo->views)]) }}{{ $archVideo->duration ? ' · '.$archVideo->duration : '' }}</span>
        </div>
        <div class="avd-hbtns">
            <a class="btn btn-lg btn-primary" href="{{ $archVideo->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Watch Now') }}</a>
            <button type="button" class="btn btn-lg btn-outline-secondary avd-btn2" data-watch-later-id="{{ $archVideo->id }}"
                    onclick="window.avdQuickSave && window.avdQuickSave('{{ $archVideo->id }}');">
                <i class="fa-regular fa-bookmark" aria-hidden="true"></i> {{ __('Save') }}
            </button>
        </div>
    </div>
    <div class="avd-arch">
        <a class="d-block h-100" href="{{ $archVideo->route }}" tabindex="-1" aria-hidden="true">
            <img src="{{ $archVideo->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async">
        </a>
        <span class="avd-sun" aria-hidden="true"></span>
    </div>
</section>
