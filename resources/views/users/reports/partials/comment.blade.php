{{-- A reported comment in "Reports you sent" (member design 6.8; the member copy: the shared
     reports/comment stays for the v1 admin). Prints the media cell and the body of the row. The line under the excerpt
     is escaped first and gets the escaped names after, so a title or a translation never adds markup.
     Variable: $report. --}}
@php
    $mkComment = $report->reportable;
    $mkVideo = $mkComment?->video;
    $mkAuthor = $mkComment?->user?->username ?? __('a deleted member');
@endphp
<span class="mk-item__media mk-item__media--sm"><span class="mk-thumb mk-thumb--icon"><i class="fa-regular fa-comment" aria-hidden="true"></i></span></span>
<div class="mk-item__body">
    @if($mkComment)
        <span class="mk-item__title mk-clamp-1" dir="auto"><q>{{ \Illuminate\Support\Str::limit(trim((string) $mkComment->content), 60) }}</q></span>
        @if($mkVideo)
            <span class="mk-muted mk-ref-line" style="font-size:.8125rem">{!! strtr(e(__('comment by :name on :title')), [':name' => '<bdi class="mk-ref-line__name" dir="auto">'.e($mkAuthor).'</bdi>', ':title' => '<a class="mk-quiet-link mk-ref-line__fit" href="'.e($mkVideo->route).'" dir="auto">'.e($mkVideo->title).'</a>']) !!}</span>
        @else
            <span class="mk-muted mk-clamp-1" style="font-size:.8125rem">{{ __('comment by :name', ['name' => $mkAuthor]) }}</span>
        @endif
    @else
        <span class="mk-item__title mk-clamp-1">{{ __('A comment that was deleted') }}</span>
    @endif
    @include('users.reports.partials.meta')
</div>
