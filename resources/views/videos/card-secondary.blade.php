@php
    $scrubbingEnabled = config('site.video_card_scrubbing_enabled', true);
    $previewThumbs = $scrubbingEnabled ? ($video->relationLoaded('thumbnails') ? $video->preview_thumbnails : (array)($video->thumbnail_url ? [$video->thumbnail_url] : [])) : [];
    $hdBadgeEnabled = config('site.video_card_hd_badge_enabled', true);
    
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
    $showChannel = $showChannel ?? true;
    $showMeta = $showMeta ?? true;
@endphp
@once
<style>
    /* Quick actions over the small related thumbnail: 24px discs (the minimum target size), 10px icons. The
       disc is a fixed dark scrim with a white icon (bg-black/text-white) whatever the theme, as over any picture. */
    .avd-action-btn--sm {
        width: 24px;
        height: 24px;
        min-height: 24px;
        font-size: 10px;
    }
</style>
@endonce
<article @class(["suggested_video avd-related-card", "mb-2" => !isset($playlist_video)]) x-data="{
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
    <a href="{{$video->route}}" class="avd-thumb position-relative overflow-hidden" @mouseenter="startScrub()" @mouseleave="stopScrub()" aria-label="{{$video->title}}" data-avd-upnext-item>
        <template x-if="thumbs && thumbs.length > 1 && hover">
            <img :src="thumbs[thumbIndex]" alt="{{$video->title}}" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="z-index:1;" loading="lazy">
        </template>
        <image-loaded source="{{$video->thumbnail_url}}" title="{{$video->title}}" :hover="hover"></image-loaded>
        <span class="avd-duration">{{$video->duration}}</span>
        @if($hdBadgeEnabled && $hasQuality)
            <span class="badge {{ $qualityBadgeClass }} position-absolute top-0 start-0 m-1 px-1.5 py-0.5 text-uppercase shadow-sm" style="font-size:9px; z-index:2; letter-spacing:0.5px;">{{$qualityText}}</span>
        @endif

        <!-- Center Play Icon Hover Watermark -->
        <div class="avd-thumb-play-center" :class="{ 'opacity-100': hover }">
            <i class="fa-solid fa-play"></i>
        </div>

        <!-- Quick Action Toolbar -->
        <div class="avd-thumb-actions position-absolute top-0 end-0 m-1 d-flex flex-column gap-1 opacity-0 pointer-events-auto" style="z-index: 4;" :class="{ 'opacity-100': hover }">
            <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn avd-action-btn--sm" title="{{ __('Watch Later') }}" data-watch-later-id="{{ $video->id }}" onclick="event.preventDefault(); event.stopPropagation(); window.avdQuickSave && window.avdQuickSave('{{ $video->id }}');">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center bg-black bg-opacity-75 text-white avd-action-btn avd-action-btn--sm" title="{{ __('Share') }}" onclick="event.preventDefault(); event.stopPropagation(); window.avdQuickShare && window.avdQuickShare('{{ $video->route }}', '{{ addslashes($video->title) }}');">
                <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
            </button>
        </div>
    </a>
    <div class="avd-related-info min-w-0 py-1">
        <a href="{{$video->route}}" class="avd-related-title {{ \App\Support\TitleScript::cssClass($video->title) }} text-decoration-none" title="{{$video->title}}">
            {{Str::limit($video->title, 58)}}
        </a>
        @if($showChannel)
            @if($video->user)
                <a href="{{$video->user->route}}" class="avd-channel-name text-decoration-none mt-1 d-inline-flex align-items-center" title="{{$video->user->username}}">
                    <span>{{$video->user->username}}</span>
                    @if($creatorVerified)
                        <i class="fa-solid fa-circle-check text-primary ms-1" style="font-size:9px;" title="{{ __('Verified Creator') }}"></i>
                    @endif
                </a>
            @else
                <span class="avd-channel-name text-muted mt-1">{{__('Deleted user')}}</span>
            @endif
        @endif
        @if($showMeta)
            <div class="avd-video-meta">
                {{trans_choice('views', $video->views, ['count' => \App\Helpers\Number::humanize($video->views)])}} <span aria-hidden="true">&middot;</span> {{ ($video->published_at ?? $video->created_at ?? $video->date)?->diffForHumans() ?? __('Recently') }}
            </div>
        @endif
    </div>
</article>
