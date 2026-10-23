@php
    $cardStyle = config('site.video_card_layout_style', 'card_standard');
    $scrubbingEnabled = config('site.video_card_scrubbing_enabled', true);
    $previewThumbs = $scrubbingEnabled ? ($video->relationLoaded('thumbnails') ? $video->preview_thumbnails : (array)($video->thumbnail_url ? [$video->thumbnail_url] : [])) : [];
    $hdBadgeEnabled = config('site.video_card_hd_badge_enabled', true);
    $newBadgeDays = (int) config('site.video_card_new_badge_days', 3);
    $isNew = $newBadgeDays > 0 && ($video->published_at ?? $video->created_at)?->diffInDays(now()) <= $newBadgeDays;
    
    $height = (int) ($video->height ?? 0);
    $rawQuality = strtolower((string) ($video->xvid_quality ?? ''));
    $is4K = $height >= 2160 || str_contains($rawQuality, '4k') || str_contains($rawQuality, '2160');
    $is2K = $height >= 1440 || str_contains($rawQuality, '2k') || str_contains($rawQuality, '1440');
    $is1080 = $height >= 1080 || str_contains($rawQuality, '1080');
    $is720 = $height >= 720 || str_contains($rawQuality, '720');
    $hasQuality = $is4K || $is2K || $is1080 || $is720 || filled($video->xvid_quality);
    $qualityText = $is4K ? '4K UHD' : ($is2K ? '1440p 2K' : ($is1080 ? '1080p' : ($is720 ? '720p' : ($video->xvid_quality ?: 'HD'))));
    $qualityBadgeClass = $is4K ? 'bg-gradient-warning' : ($is2K ? 'bg-primary' : 'bg-danger');

    $creatorVerified = config('site.creator_verified_badge_enabled', true);
    $titleScriptClass = \App\Support\TitleScript::cssClass($video->title);
    $category = $video->category;
@endphp
@once
<style>
    /* Card actions remain reachable without placing interactive buttons inside the thumbnail link. */
    .avd-card-quick-actions {
        pointer-events: none;
    }

    .avd-card-quick-actions:focus-within,
    .avd-video-card:focus-within .avd-card-quick-actions {
        opacity: 1 !important;
        transform: translateY(0) !important;
        pointer-events: auto;
    }

    .avd-video-card:hover .avd-card-quick-actions {
        pointer-events: auto;
    }

    .avd-card-quick-actions .avd-action-btn {
        min-width: 44px;
        min-height: 44px;
        touch-action: manipulation;
    }

    .avd-card-quick-actions .avd-action-btn:focus-visible {
        outline: 2px solid var(--avd-focus);
        outline-offset: 2px;
    }

    @media (hover: none), (pointer: coarse) {
        .avd-card-inline-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            min-height: 44px;
        }

        .avd-card-quick-actions {
            opacity: 1 !important;
            transform: translateY(0) !important;
            pointer-events: auto;
        }

        .avd-card-quick-actions .avd-action-btn {
            width: 44px !important;
            height: 44px !important;
        }
    }

    @media (max-width: 767.98px) {
        .avd-card-quick-actions {
            position: static !important;
            opacity: 1 !important;
            width: 100%;
            max-width: 100%;
            margin: 0 !important;
            padding: 0.3rem 0.5rem;
            flex-direction: row !important;
            justify-content: flex-end;
            gap: 0.35rem !important;
            box-sizing: border-box;
            pointer-events: auto;
            background: var(--avd-surface-1);
            border-top: 1px solid var(--avd-border);
            transform: none !important;
        }

        /* Glass cards become normal flow cards in a two-column mobile feed so metadata cannot cover the thumbnail. */
        html[data-avd-card-layout="card_glass_overlay"][data-avd-mobile-video-columns="2"] .avd-card-glass-overlay .avd-glass-content {
            position: static !important;
            inset: auto !important;
            width: 100% !important;
            padding: 0.65rem 0.5rem 0.55rem !important;
            background: var(--avd-surface-1);
            pointer-events: auto !important;
        }

        html[data-avd-card-layout="card_glass_overlay"][data-avd-mobile-video-columns="2"] .avd-card-glass-overlay .avd-thumb {
            padding-bottom: 0 !important;
        }

        html[data-avd-card-layout="card_glass_overlay"][data-avd-mobile-video-columns="2"] .avd-card-glass-overlay .avd-glass-content .avd-video-title {
            color: var(--avd-text) !important;
            overflow-wrap: anywhere;
            text-shadow: none !important;
        }

        html[data-avd-card-layout="card_glass_overlay"][data-avd-mobile-video-columns="2"] .avd-card-glass-overlay .avd-glass-content > .d-flex {
            color: var(--avd-muted) !important;
            min-width: 0;
            flex-wrap: wrap;
            justify-content: flex-start !important;
            gap: 0.35rem 0.6rem;
        }

        html[data-avd-card-layout="card_glass_overlay"][data-avd-mobile-video-columns="2"] .avd-card-glass-overlay .avd-glass-content > .d-flex > a {
            color: var(--avd-muted) !important;
            min-width: 0;
            max-width: 100%;
            flex: 1 1 7rem;
        }

        html[data-avd-card-layout="card_glass_overlay"][data-avd-mobile-video-columns="2"] .avd-card-glass-overlay .avd-glass-content > .d-flex > span {
            flex: 0 0 auto;
            margin-inline-start: auto;
        }
    }
</style>
@endonce
<article class="col avd-card-col" data-card-layout="{{ $cardStyle }}" x-data="{
    hover: false,
    thumbs: {{ json_encode($previewThumbs) }},
    thumbIndex: 0,
    interval: null,
    startScrub() {
        this.hover = true;
        if (this.thumbs && this.thumbs.length > 1) {
            this.interval = setInterval(() => {
                this.thumbIndex = (this.thumbIndex + 1) % this.thumbs.length;
            }, 750);
        }
    },
    stopScrub() {
        this.hover = false;
        if (this.interval) {
            clearInterval(this.interval);
            this.interval = null;
        }
        this.thumbIndex = 0;
    }
}">
    @if($cardStyle === 'card_glass_overlay')
        {{-- Modern Glassmorphic Overlay: Metadata integrated over thumbnail --}}
        <div class="avd-video-card avd-card-glass-overlay position-relative overflow-hidden" @mouseenter="startScrub()" @mouseleave="stopScrub()">
            <div class="avd-card-spotlight"></div>
            <div class="avd-card-glare"></div>
            <a href="{{$video->route}}" class="avd-thumb d-block position-relative overflow-hidden w-100" aria-label="{{$video->title}}">
                <template x-if="thumbs && thumbs.length > 1 && hover">
                    <img :src="thumbs[thumbIndex]" alt="{{$video->title}}" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="z-index:1;" loading="lazy">
                </template>
                <div class="position-absolute top-0 start-0 w-100 h-100">
                    <image-loaded source="{{$video->thumbnail_url}}" title="{{$video->title}}" :hover="hover"></image-loaded>
                </div>

                <span class="avd-duration" style="z-index: 3;">{{$video->duration}}</span>
                @if($hdBadgeEnabled && $hasQuality)
                    <span class="badge {{ $qualityBadgeClass }} position-absolute top-0 start-0 m-2 px-2 py-1 text-uppercase shadow-sm" style="font-size:10px; z-index:3; letter-spacing:0.5px;">{{$qualityText}}</span>
                @endif
                @if($isNew)
                    <span class="badge bg-primary position-absolute top-0 end-0 m-2 px-2 py-1 text-uppercase fw-bold shadow-sm" style="font-size:10px; z-index:3; letter-spacing:0.5px;">{{ __('NEW') }}</span>
                @endif

            </a>

            <!-- Quick Action Toolbar: sibling of the thumbnail link so buttons remain valid controls. -->
            <div class="avd-thumb-actions avd-card-quick-actions position-absolute top-0 end-0 m-2 d-flex flex-column gap-1.5 opacity-0" style="z-index: 10;" :class="{ 'opacity-100': hover }">
                <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn" title="{{ __('Watch Later') }}" aria-label="{{ __('Watch Later') }}" data-watch-later-id="{{ $video->id }}" onclick="event.stopPropagation(); window.avdQuickSave && window.avdQuickSave('{{ $video->id }}');">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn" title="{{ __('Share') }}" aria-label="{{ __('Share') }}" onclick="event.stopPropagation(); window.avdQuickShare && window.avdQuickShare('{{ $video->route }}', '{{ addslashes($video->title) }}');">
                    <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Caption over the bottom of the thumbnail: the theme's caption shade (--avd-caption-scrim), at most --avd-caption-max of it. -->
            <div class="avd-glass-content avd-card-caption position-absolute bottom-0 start-0 w-100 p-2 p-sm-3 pointer-events-none">
                <a href="{{$video->route}}" class="avd-video-title {{ $titleScriptClass }} d-block text-white pointer-events-auto text-decoration-none mb-1" title="{{$video->title}}">
                    {{$video->title}}
                </a>
                <div class="d-flex align-items-center justify-content-between text-white text-opacity-75 small pointer-events-auto pt-1">
                    @if($video->user)
                        <a href="{{$video->user->route}}" class="d-flex align-items-center gap-2 text-reset text-decoration-none" title="{{$video->user->username}}">
                            <img class="avd-channel-avatar rounded-circle" src="{{$video->user->avatar_url}}" alt="{{$video->user->username}}" loading="lazy" decoding="async">
                            <span class="text-truncate">{{$video->user->username}}</span>
                        </a>
                    @else
                        <span>{{__('Community')}}</span>
                    @endif
                    <span><i class="fa-solid fa-eye me-1" aria-hidden="true"></i>{{trans_choice('views', $video->views, ['count' => \App\Helpers\Number::humanize($video->views)])}}</span>
                </div>
            </div>
        </div>

    @elseif($cardStyle === 'card_bento_modern')
        {{-- Bento Card: Rounded Tile with Border Rim Glow & Category Chip --}}
        <div class="avd-video-card avd-card-bento p-2 h-100 position-relative">
            <div class="avd-card-spotlight"></div>
            <div class="avd-card-glare"></div>
            <a href="{{$video->route}}" class="avd-thumb position-relative overflow-hidden d-block rounded-3" @mouseenter="startScrub()" @mouseleave="stopScrub()" aria-label="{{$video->title}}">
                <template x-if="thumbs && thumbs.length > 1 && hover">
                    <img :src="thumbs[thumbIndex]" alt="{{$video->title}}" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="z-index:1;" loading="lazy">
                </template>
                <image-loaded source="{{$video->thumbnail_url}}" title="{{$video->title}}" :hover="hover"></image-loaded>
                <span class="avd-duration">{{$video->duration}}</span>
                @if($hdBadgeEnabled && $hasQuality)
                    <span class="badge {{ $qualityBadgeClass }} position-absolute top-0 start-0 m-2 px-2 py-1 text-uppercase shadow-sm" style="font-size:10px; z-index:2; letter-spacing:0.5px;">{{$qualityText}}</span>
                @endif
                @if($isNew)
                    <span class="badge bg-primary position-absolute top-0 end-0 m-2 px-2 py-1 text-uppercase fw-bold shadow-sm" style="font-size:10px; z-index:2; letter-spacing:0.5px;">{{ __('NEW') }}</span>
                @endif

            </a>
            <!-- Quick Action Toolbar: sibling of the thumbnail link so buttons remain valid controls. -->
            <div class="avd-thumb-actions avd-card-quick-actions position-absolute top-0 end-0 m-2 d-flex flex-column gap-1.5 opacity-0" style="z-index: 10;" :class="{ 'opacity-100': hover }">
                <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn" title="{{ __('Watch Later') }}" aria-label="{{ __('Watch Later') }}" data-watch-later-id="{{ $video->id }}" onclick="event.stopPropagation(); window.avdQuickSave && window.avdQuickSave('{{ $video->id }}');">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn" title="{{ __('Share') }}" aria-label="{{ __('Share') }}" onclick="event.stopPropagation(); window.avdQuickShare && window.avdQuickShare('{{ $video->route }}', '{{ addslashes($video->title) }}');">
                    <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
                </button>
            </div>
            <div class="avd-card-body p-2 pt-2">
                <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                    @if($category)
                        <span class="avd-card-category">
                            {{ __($category->title) }}
                        </span>
                    @endif
                    <span class="text-body-secondary avd-video-meta" style="font-size: 10px;">
                        <i class="fa-solid fa-eye me-0.5"></i>{{trans_choice('views', $video->views, ['count' => \App\Helpers\Number::humanize($video->views)])}}
                    </span>
                </div>
                <a href="{{$video->route}}" class="avd-video-title {{ $titleScriptClass }} text-truncate-2 mb-1" title="{{$video->title}}">
                    {{$video->title}}
                </a>
                <div class="d-flex align-items-center gap-2 mt-1">
                    @if($video->user)
                        <a href="{{$video->user->route}}" class="avd-channel-name text-decoration-none d-flex align-items-center gap-2" title="{{$video->user->username}}">
                            <img class="avd-channel-avatar" src="{{$video->user->avatar_url}}" alt="{{$video->user->username}}" loading="lazy">
                            <span class="text-truncate" style="font-size: 11px;">{{$video->user->username}}</span>
                            @if($creatorVerified)
                                <i class="fa-solid fa-circle-check text-primary" style="font-size:9px;"></i>
                            @endif
                        </a>
                    @endif
                    <span class="text-body-secondary avd-video-meta ms-auto" style="font-size: 10px;">
                        {{$video->published_at?->diffForHumans() ?? $video->created_at?->diffForHumans() ?? __('Recently')}}
                    </span>
                </div>
            </div>
        </div>

    @elseif($cardStyle === 'card_horizontal_strip')
        {{-- Horizontal Row Strip: List View --}}
        <div class="avd-video-card avd-card-horizontal d-flex gap-3 p-2 h-100 position-relative">
            <div class="avd-card-spotlight"></div>
            <div class="avd-card-glare"></div>
            <a href="{{$video->route}}" class="avd-thumb avd-card-horizontal-thumb flex-shrink-0 position-relative overflow-hidden rounded-2" style="width: min(140px, 34vw); aspect-ratio: 16 / 9;" @mouseenter="startScrub()" @mouseleave="stopScrub()" aria-label="{{$video->title}}">
                <template x-if="thumbs && thumbs.length > 1 && hover">
                    <img :src="thumbs[thumbIndex]" alt="{{$video->title}}" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="z-index:1;" loading="lazy">
                </template>
                <image-loaded source="{{$video->thumbnail_url}}" title="{{$video->title}}" :hover="hover"></image-loaded>
                <span class="avd-duration" style="font-size: 9px; padding: 1px 4px;">{{$video->duration}}</span>
                @if($hdBadgeEnabled && $hasQuality)
                    <span class="badge {{ $qualityBadgeClass }} position-absolute top-0 start-0 m-1 px-1 py-0.5 text-uppercase" style="font-size:9px; z-index:2;">{{$qualityText}}</span>
                @endif
            </a>
            <div class="avd-card-body min-w-0 flex-grow-1 p-0 d-flex flex-column justify-content-center">
                <a href="{{$video->route}}" class="avd-video-title {{ $titleScriptClass }} text-truncate-2 mb-1" title="{{$video->title}}">
                    {{$video->title}}
                </a>
                @if($video->user)
                    <a href="{{$video->user->route}}" class="avd-channel-name small text-muted text-truncate d-inline-flex align-items-center mb-0.5" title="{{$video->user->username}}">
                        <span>{{$video->user->username}}</span>
                        @if($creatorVerified)
                            <i class="fa-solid fa-circle-check text-primary ms-1" style="font-size:9px;"></i>
                        @endif
                    </a>
                @endif
                <div class="d-flex flex-wrap gap-1 align-items-center justify-content-between mt-1">
                    <span class="avd-video-meta" style="font-size: 11px;">{{trans_choice('views', $video->views, ['count' => \App\Helpers\Number::humanize($video->views)])}} <span aria-hidden="true">&middot;</span> {{$video->published_at?->diffForHumans() ?? $video->created_at?->diffForHumans() ?? __('Recently')}}</span>
                    <div class="avd-card-inline-actions d-flex align-items-center gap-1">
                        <button type="button" class="btn btn-link text-body-secondary p-0" title="{{ __('Watch Later') }}" aria-label="{{ __('Watch Later') }}" data-watch-later-id="{{ $video->id }}" onclick="event.preventDefault(); event.stopPropagation(); window.avdQuickSave && window.avdQuickSave('{{ $video->id }}');">
                            <i class="fa-regular fa-clock" style="font-size: 12px;"></i>
                        </button>
                        <button type="button" class="btn btn-link text-body-secondary p-0 ms-1" title="{{ __('Share') }}" aria-label="{{ __('Share') }}" onclick="event.preventDefault(); event.stopPropagation(); window.avdQuickShare && window.avdQuickShare('{{ $video->route }}', '{{ addslashes($video->title) }}');">
                            <i class="fa-solid fa-share-nodes" style="font-size: 12px;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

    @else
        {{-- Standard / Hover Expand Card --}}
        <div class="avd-video-card position-relative {{ $cardStyle === 'card_hover_expand' ? 'avd-card-hover-expand' : '' }}">
            <div class="avd-card-spotlight"></div>
            <div class="avd-card-glare"></div>
            <a href="{{$video->route}}" class="avd-thumb position-relative overflow-hidden" @mouseenter="startScrub()" @mouseleave="stopScrub()" aria-label="{{$video->title}}">
                <template x-if="thumbs && thumbs.length > 1 && hover">
                    <img :src="thumbs[thumbIndex]" alt="{{$video->title}}" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="z-index:1;" loading="lazy">
                </template>
                <image-loaded source="{{$video->thumbnail_url}}" title="{{$video->title}}" :hover="hover"></image-loaded>
                <span class="avd-duration">{{$video->duration}}</span>
                @if($hdBadgeEnabled && $hasQuality)
                    <span class="badge {{ $qualityBadgeClass }} position-absolute top-0 start-0 m-2 px-2 py-1 text-uppercase shadow-sm" style="font-size:10px; z-index:2; letter-spacing:0.5px;">{{$qualityText}}</span>
                @endif
                @if($isNew)
                    <span class="badge bg-primary position-absolute top-0 end-0 m-2 px-2 py-1 text-uppercase fw-bold shadow-sm" style="font-size:10px; z-index:2; letter-spacing:0.5px;">{{ __('NEW') }}</span>
                @endif

            </a>
            <!-- Quick Action Toolbar: sibling of the thumbnail link so buttons remain valid controls. -->
            <div class="avd-thumb-actions avd-card-quick-actions position-absolute top-0 end-0 m-2 d-flex flex-column gap-1.5 opacity-0" style="z-index: 10;" :class="{ 'opacity-100': hover }">
                <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn" title="{{ __('Watch Later') }}" aria-label="{{ __('Watch Later') }}" data-watch-later-id="{{ $video->id }}" onclick="event.stopPropagation(); window.avdQuickSave && window.avdQuickSave('{{ $video->id }}');">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn" title="{{ __('Share') }}" aria-label="{{ __('Share') }}" onclick="event.stopPropagation(); window.avdQuickShare && window.avdQuickShare('{{ $video->route }}', '{{ addslashes($video->title) }}');">
                    <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
                </button>
            </div>
            <div class="avd-card-body">
                @if($video->user)
                    <a href="{{$video->user->route}}" class="avd-card-avatar-link" title="{{$video->user->username}}">
                        <img class="avd-channel-avatar" src="{{$video->user->avatar_url}}" alt="{{$video->user->username}} avatar" loading="lazy">
                    </a>
                @else
                    <div class="avd-channel-avatar bg-secondary rounded-circle d-flex align-items-center justify-content-center">
                        <i class="fa-solid fa-user-slash text-muted" style="font-size:12px;"></i>
                    </div>
                @endif
                <div class="min-w-0">
                    <a href="{{$video->route}}" class="avd-video-title {{ $titleScriptClass }}" title="{{$video->title}}">
                        {{$video->title}}
                    </a>
                    @if($video->user)
                        <a href="{{$video->user->route}}" class="avd-channel-name d-inline-flex align-items-center" title="{{$video->user->username}}">
                            <span>{{$video->user->username}}</span>
                            @if($creatorVerified)
                                <i class="fa-solid fa-circle-check text-primary ms-1" style="font-size:10px;" title="{{ __('Verified Creator') }}"></i>
                            @endif
                        </a>
                    @else
                        <span class="avd-channel-name text-muted">{{__('Deleted user')}}</span>
                    @endif
                    <span class="avd-video-meta">{{trans_choice('views', $video->views, ['count' => \App\Helpers\Number::humanize($video->views)])}} <span aria-hidden="true">&middot;</span> {{$video->published_at?->diffForHumans() ?? $video->created_at?->diffForHumans() ?? __('Recently')}}</span>
                </div>
            </div>
        </div>
    @endif
</article>
