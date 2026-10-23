@php
    $playlistEntryRoute = $playlist->first_video ? $playlist->first_video->routeWithParams(['list' => $playlist->uuid]) : $playlist->route;
@endphp

<article class="col-md-6 col-lg-4 col-xl-3 avd-card-col" x-data="{hover:false}">
    <div class="avd-video-card">
        <a href="{{$playlistEntryRoute}}" class="avd-thumb" @mouseover="hover=true" @mouseleave="hover=false" @focusin="hover=true" @focusout="hover=false" aria-label="{{$playlist->title}}">
            @if($playlist->first_video)
                <img src="{{$playlist->first_video->thumbnail_url}}" alt="{{$playlist->title}} thumbnail" width="640" height="360" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/placeholders/no-image.svg') }}';">
            @else
                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                    <i class="fa-solid fa-image fa-2x"></i>
                </div>
            @endif
            <span class="avd-duration d-flex gap-2 align-items-center">
                <i class="fa-solid fa-list"></i>
                <span>{{trans_choice('videos', $playlist->videos_count, ['count' => \App\Helpers\Number::humanize($playlist->videos_count)])}}</span>
            </span>
            <span x-show.important="hover" x-cloak class="avd-card-play-all position-absolute inset z-3 d-flex align-items-center justify-content-center fw-bold bg-black bg-opacity-50 text-white">
                <i class="fa-solid fa-play me-2" aria-hidden="true"></i>
                {{ __('Play All') }}
            </span>
        </a>
        <div class="pt-3">
            <a href="{{$playlist->route}}" class="avd-video-title {{ \App\Support\TitleScript::cssClass($playlist->title) }} text-decoration-none" title="{{$playlist->title}}">
                {{Str::limit($playlist->title, 69)}}
            </a>
            <div class="avd-video-meta">{{$playlist->created_at->diffForHumans()}}</div>
            <a href="{{$playlist->route}}" class="avd-channel-name text-decoration-none mt-2" title="{{$playlist->title}}">
                {{ __('View full playlist') }}
            </a>
        </div>
    </div>
</article>
