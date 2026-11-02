{{-- One row of My videos (member design 5.7, 6.3). Phone: check, thumb, title (2 lines) + status line +
     stats line, the ⋯ menu; compact (from 45rem of list width): the views column and Edit / Analytics; full table (from
     77.5rem): Visibility, Views, Comments, Likes columns. Lean (4.3): the ⋯ menu element is added and filled when it
     opens (one template for every row, users/videos/index.blade.php; users/partials/studio-scripts), and the row's own
     links are root-relative (StudioHtml::path). 1.2.3 round 1b (R1R-01): the status line is printed once; in the full
     table it is the Visibility column (member/_kit.scss), it was printed a second time as that column's cell. --}}
@php
    $rowStatus = $video->status instanceof \App\Enums\VideoStatus ? $video->status : \App\Enums\VideoStatus::tryFrom((int) $video->status);
    $rowFailed = $rowStatus === \App\Enums\VideoStatus::FAILED;
    $rowDraft = $rowStatus === \App\Enums\VideoStatus::DRAFT;
    $rowBanned = $rowStatus === \App\Enums\VideoStatus::BANNED;
    $rowCreated = ! $rowFailed && ! $rowDraft && ! $video->is_uploading;
    $rowPlanned = $rowStatus === \App\Enums\VideoStatus::PLANNED && $video->scheduled_at !== null && $video->scheduled_at->isFuture();
    $rowEdit = \App\Support\Studio\StudioHtml::path('user.videos.edit', $video);
    $rowAnalytics = \App\Support\Studio\StudioHtml::path('user.videos.show', $video);
    $rowFinish = \App\Support\Studio\StudioHtml::path('user.videos.create', $video);
    $rowUrl = match (true) {
        $rowDraft => $rowFinish,
        $rowFailed => null,
        $rowBanned => $rowCreated ? $rowAnalytics : null,
        default => $rowEdit,
    };
    $rowLine = match (true) {
        $rowFailed => __('Failed on :date', ['date' => \App\Support\Studio\StudioFormat::shortDate($video->updated_at ?? $video->created_at)]),
        $rowDraft && \App\Support\Upload18\UploadClaims::isProcessing($video) => __('Uploaded :date', ['date' => \App\Support\Studio\StudioFormat::shortDate($video->created_at)]),
        $rowDraft => __('Details missing'),
        $rowBanned => $video->banned_at ? __('Banned on :date', ['date' => \App\Support\Studio\StudioFormat::shortDate($video->banned_at)]) : __('Banned'),
        $rowPlanned => \App\Support\Studio\ZoneLabel::goesPublic($video->scheduled_at, false),
        $video->published_at !== null => \App\Support\Studio\StudioFormat::listDate($video->published_at),
        default => \App\Support\Studio\StudioFormat::listDate($video->created_at),
    };
    $rowRatings = (int) $video->likes_count + (int) $video->dislikes_count;
    $rowPct = $rowCreated ? \App\Support\Studio\StudioFormat::percent((int) $video->likes_count, $rowRatings) : null;
    $rowThumb = ($video->thumbnail || ! empty($video->poster_url)) ? \App\Support\Studio\StudioHtml::relative($video->thumbnail_url) : null;
    $rowTitle = (string) ($video->getRawOriginal('title') ?: __('Untitled video'));
    $rowComments = (int) $video->comments_count;
    $rowMore = __('More actions');
@endphp
<li class="mk-row" data-id="{{ $video->id }}">
    <label class="mk-row__check"><input class="mk-check" type="checkbox" value="{{ $video->id }}" aria-label="{{ __('Select :title', ['title' => $rowTitle]) }}"></label>
    @if($rowUrl)<a class="mk-row__media" href="{{ $rowUrl }}" tabindex="-1" aria-hidden="true">@else<span class="mk-row__media" aria-hidden="true">@endif<span class="mk-thumb">@if($rowThumb)<img src="{{ $rowThumb }}" alt="" width="160" height="90"@if(($rowIndex ?? 0) >= 4) loading="lazy"@endif decoding="async">@else<span class="mk-thumb__empty"><i class="fa-regular fa-image"></i></span>@endif @if($video->duration !== '')<span class="mk-thumb__dur">{{ $video->duration }}</span>@endif</span>@if($rowUrl)</a>@else</span>@endif
    <div class="mk-row__main">
        @if($rowUrl)<a class="mk-row__title mk-clamp-2" href="{{ $rowUrl }}" dir="auto">{{ $rowTitle }}</a>@else<span class="mk-row__title mk-clamp-2" dir="auto">{{ $rowTitle }}</span>@endif
        <div class="mk-row__meta mk-row__meta--status"><span><x-mk.status :video="$video" /></span><span>{{ $rowLine }}</span></div>
        <div class="mk-row__meta mk-row__meta--stats">@if($rowCreated)<span class="mk-meta-views">{{ trans_choice(':count view|:count views', (int) $video->views, ['count' => \App\Support\Studio\StudioFormat::number((int) $video->views)]) }}</span>@if($rowComments)<span class="mk-meta-comments">{{ trans_choice(':count comment|:count comments', $rowComments, ['count' => \App\Support\Studio\StudioFormat::number($rowComments)]) }}</span>@endif @if($rowPct !== null)<span class="mk-meta-likes">{{ __(':percent% likes', ['percent' => $rowPct]) }}</span>@endif @endif @if($video->is_short)<span class="mk-meta-short"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> {{ __('Short') }}</span>@endif @if($video->category)<span class="mk-meta-cat">{{ __($video->category->title) }}</span>@endif</div>
    </div>
    <div class="mk-row__cell mk-row__cell--views mk-row__num">@if($rowCreated){{ \App\Support\Studio\StudioFormat::number((int) $video->views) }}@else<span class="mk-muted">–</span>@endif</div>
    <div class="mk-row__cell mk-row__cell--comments mk-row__num">@if($rowCreated && $rowComments)<a href="{{ \App\Support\Studio\StudioHtml::path('user.comments.index', ['video' => $video->id]) }}" aria-label="{{ trans_choice(':count comment on :title|:count comments on :title', $rowComments, ['count' => \App\Support\Studio\StudioFormat::number($rowComments), 'title' => $rowTitle]) }}">{{ \App\Support\Studio\StudioFormat::number($rowComments) }}</a>@elseif($rowCreated){{ \App\Support\Studio\StudioFormat::number(0) }}@else<span class="mk-muted">–</span>@endif</div>
    <div class="mk-row__cell mk-row__cell--likes">@if($rowPct !== null)<div class="mk-ratio" role="img" aria-label="{{ __(':percent% likes', ['percent' => $rowPct]) }}"><span style="inline-size:{{ $rowPct }}%"></span><span style="inline-size:{{ 100 - $rowPct }}%"></span></div><div class="mk-ratio__label"><span>{{ $rowPct }}%</span><span><i class="fa-solid fa-thumbs-up" aria-hidden="true"></i> {{ \App\Support\Studio\StudioFormat::number((int) $video->likes_count) }} &nbsp;<i class="fa-solid fa-thumbs-down" aria-hidden="true"></i> {{ \App\Support\Studio\StudioFormat::number((int) $video->dislikes_count) }}</span></div>@elseif($rowCreated)<span class="mk-row__sub">{{ __('No ratings yet') }}</span>@endif</div>
    <div class="mk-row__actions">
        @if($rowFailed)
            @if($uploadsAllowed)<button type="button" class="mk-icon-btn mk-icon-btn--muted mk-act-wide" title="{{ __('Upload again') }}" aria-label="{{ __('Upload again') }}" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>@endif
            <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--danger mk-act-wide" title="{{ __('Delete video') }}" aria-label="{{ __('Delete video') }}" data-bs-toggle="modal" data-bs-target="#mk-delete-video" data-mk-delete="{{ $video->id }}"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
            <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-act-narrow" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" title="{{ $rowMore }}" aria-label="{{ $rowMore }}" data-mk-menu="{{ $video->id }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
        @elseif($rowDraft)
            <a class="mk-btn mk-btn--secondary mk-btn--sm mk-act-wide" href="{{ $rowFinish }}">{{ __('Finish') }}</a>
            <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" title="{{ $rowMore }}" aria-label="{{ $rowMore }}" data-mk-menu="{{ $video->id }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
        @else
            @unless($rowBanned)<a class="mk-icon-btn mk-icon-btn--muted mk-act-wide" href="{{ $rowEdit }}" title="{{ __('Edit details') }}" aria-label="{{ __('Edit details') }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>@endunless
            @if($rowCreated)<a class="mk-icon-btn mk-icon-btn--muted mk-act-wide" href="{{ $rowAnalytics }}" title="{{ __('Analytics') }}" aria-label="{{ __('Analytics') }}"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i></a>@endif
            <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="menu" title="{{ $rowMore }}" aria-label="{{ $rowMore }}" data-mk-menu="{{ $video->id }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
        @endif
    </div>
</li>
