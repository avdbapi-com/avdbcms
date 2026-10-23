{{-- Split spotlight (split, split_glass, split_orbit): one featured video beside its facts. split_glass is the only
     hero panel allowed a backdrop blur (rich tier, CSS). Phones keep the preview, title and buttons (the kicker
     starts at md; the CSS drops the channel, stats and text). --}}
@php
    $splitVideo = $heroVideos->first();
    $splitLikes = (int) ($splitVideo->likes_count ?? 0);
    $splitVotes = $splitLikes + (int) ($splitVideo->dislikes_count ?? 0);
    $splitStats = array_filter([
        ['value' => \App\Helpers\Number::humanize((int) $splitVideo->views), 'label' => __('Views')],
        $splitVotes > 0 ? ['value' => round($splitLikes / $splitVotes * 100).'%', 'label' => __('Liked')] : null,
        $splitVideo->duration ? ['value' => $splitVideo->duration, 'label' => __('Length')] : null,
    ]);
@endphp
@push('preload')
    <link rel="preload" as="image" href="{{ $splitVideo->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--split" aria-labelledby="avdSplitTitle">
    <div class="avd-split">
        @if($heroDeco === 'split_orbit')
            <span class="avd-orbit" aria-hidden="true"></span>
        @endif
        <a class="avd-pv" href="{{ $splitVideo->route }}" aria-label="{{ __('Play :title', ['title' => $splitVideo->title]) }}" data-avd-card>
            <img src="{{ $splitVideo->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async">
            <span class="avd-play" aria-hidden="true"><i class="fa-solid fa-play"></i></span>
            @if($splitVideo->duration)
                <span class="avd-duration">{{ $splitVideo->duration }}</span>
            @endif
        </a>
        <div class="avd-info">
            <span class="avd-page-kicker mb-0 d-none d-md-inline-flex">{{ __(\App\Support\ThemeStructure::voice('kicker')) }}</span>
            <h2 @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($splitVideo->title)]) id="avdSplitTitle">
                <a class="text-reset text-decoration-none" href="{{ $splitVideo->route }}">{{ $splitVideo->title }}</a>
            </h2>
            @if($splitVideo->user)
                <a class="avd-chan text-reset text-decoration-none" href="{{ $splitVideo->user->route }}">
                    <span class="d-flex flex-shrink-0"><img class="avd-channel-avatar" src="{{ $splitVideo->user->avatar_url }}" alt="" width="38" height="38" loading="lazy" decoding="async"></span>
                    <div class="min-w-0">
                        <div>{{ $splitVideo->user->username }}</div>
                        @if($splitVideo->category)
                            <div class="avd-video-meta">{{ __($splitVideo->category->title) }}</div>
                        @endif
                    </div>
                </a>
            @endif
            @if($splitStats !== [])
                <div class="avd-stats">
                    @foreach($splitStats as $stat)
                        <div><b>{{ $stat['value'] }}</b><span>{{ $stat['label'] }}</span></div>
                    @endforeach
                </div>
            @endif
            @if($splitVideo->description)
                <p>{{ Str::limit(strip_tags($splitVideo->description), 140) }}</p>
            @endif
            <div class="avd-hbtns">
                <a class="btn btn-primary" href="{{ $splitVideo->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Watch Now') }}</a>
                <button type="button" class="btn btn-outline-secondary avd-btn2" data-watch-later-id="{{ $splitVideo->id }}"
                        onclick="window.avdQuickSave && window.avdQuickSave('{{ $splitVideo->id }}');">
                    <i class="fa-regular fa-bookmark" aria-hidden="true"></i> {{ __('Save') }}
                </button>
            </div>
        </div>
    </div>
</section>
