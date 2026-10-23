{{-- HUD (hud, hud_toxic, hud_terminal): the featured video in a bracketed frame beside a panel of its real facts
     (channel, views, length, category, added; hud_terminal lists them as terminal lines) and the next two picks.
     No invented telemetry. --}}
@php
    $hudVideo = $heroVideos->first();
    $hudDate = $hudVideo->published_at ?? $hudVideo->created_at;
    $hudRows = array_filter([
        $hudVideo->user ? [__('Channel'), $hudVideo->user->username] : null,
        [__('Views'), \App\Helpers\Number::humanize((int) $hudVideo->views)],
        $hudVideo->duration ? [__('Length'), $hudVideo->duration] : null,
        $hudVideo->category ? [__('Category'), __($hudVideo->category->title)] : null,
        $hudDate ? [__('Added'), $hudDate->diffForHumans()] : null,
    ]);
@endphp
@push('preload')
    <link rel="preload" as="image" href="{{ $hudVideo->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--hud" aria-labelledby="avdHudTitle">
    @if($heroDeco === 'hud_toxic')
        <div class="avd-hazard" aria-hidden="true"></div>
    @endif
    <div class="avd-hud">
        <a class="avd-feat text-reset text-decoration-none" href="{{ $hudVideo->route }}" data-avd-card>
            <img src="{{ $hudVideo->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async">
            <span class="avd-brk tl" aria-hidden="true"></span>
            <span class="avd-brk tr" aria-hidden="true"></span>
            <span class="avd-brk bl" aria-hidden="true"></span>
            <span class="avd-brk br" aria-hidden="true"></span>
            <div class="avd-cap2">
                <span class="avd-badge">{{ __('Featured') }}</span>
                <div @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($hudVideo->title)]) id="avdHudTitle">{{ $hudVideo->title }}</div>
            </div>
        </a>
        <div class="avd-panel">
            <h4>{{ __(\App\Support\ThemeStructure::voice('kicker')) }}</h4>
            @if($heroDeco === 'hud_terminal')
                <dl class="avd-term">
                    @foreach($hudRows as [$label, $value])
                        <dt class="avd-p">{{ $label }}</dt><dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            @else
                @foreach($hudRows as [$label, $value])
                    <div class="avd-drow"><span>{{ $label }}</span><b>{{ $value }}</b></div>
                @endforeach
            @endif
            <div class="avd-hbtns">
                <a class="btn btn-primary" href="{{ $hudVideo->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Watch') }}</a>
                <button type="button" class="btn btn-outline-secondary avd-btn2" data-watch-later-id="{{ $hudVideo->id }}"
                        onclick="window.avdQuickSave && window.avdQuickSave('{{ $hudVideo->id }}');">
                    <i class="fa-regular fa-bookmark" aria-hidden="true"></i> {{ __('Save') }}
                </button>
            </div>
            @foreach($heroVideos->slice(1, 2) as $video)
                <a class="avd-mini text-reset text-decoration-none" href="{{ $video->route }}" data-avd-rank-row>
                    <div class="avd-tt"><img src="{{ $video->thumbnail_url }}" alt="" width="92" height="52" loading="lazy" decoding="async"></div>
                    <div class="min-w-0">
                        <b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</b>
                        <span>{{ trans_choice('views', (int) $video->views, ['count' => \App\Helpers\Number::humanize((int) $video->views)]) }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
