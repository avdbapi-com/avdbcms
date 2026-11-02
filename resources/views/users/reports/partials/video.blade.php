{{-- A reported video in "Reports you sent" (member design 6.8; the member copy: the shared reports/video
     stays for the v1 admin). Prints the media cell and the body of the row. Variable: $report. --}}
@php
    $mkVideo = $report->reportable;
    $mkThumb = $mkVideo && ($mkVideo->thumbnail || ! empty($mkVideo->poster_url)) ? $mkVideo->thumbnail_url : null;
@endphp
@if($mkVideo)
    <a class="mk-item__media mk-item__media--sm" href="{{ $mkVideo->route }}" tabindex="-1" aria-hidden="true">
        <span class="mk-thumb">@if($mkThumb)<img src="{{ $mkThumb }}" alt="" width="160" height="90" loading="lazy" decoding="async">@else<span class="mk-thumb__empty"><i class="fa-solid fa-film"></i></span>@endif</span>
    </a>
    <div class="mk-item__body">
        <a class="mk-item__title mk-clamp-1" href="{{ $mkVideo->route }}" dir="auto">{{ $mkVideo->title }}</a>
        <span class="mk-muted" style="font-size:.8125rem">{!! strtr(e(__('by :name')), [':name' => '<bdi>'.e($mkVideo->user?->username ?? __('a deleted channel')).'</bdi>']) !!}</span>
        @include('users.reports.partials.meta')
    </div>
@else
    <span class="mk-item__media mk-item__media--sm"><span class="mk-thumb mk-thumb--icon"><i class="fa-solid fa-video-slash" aria-hidden="true"></i></span></span>
    <div class="mk-item__body">
        <span class="mk-item__title mk-clamp-1">{{ __('A video that was deleted') }}</span>
        @include('users.reports.partials.meta')
    </div>
@endif
