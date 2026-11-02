{{-- The replies of one comment (member design 6.8, studio-21): the parent comment, "N replies" and the
     replies, oldest first, per_page_replies at a time, each with Remove (the #mk-remove-reply dialog of the page). Printed
     into the replies sheet of Comments (fetched with ?part=1) and inside the page of its own (users/comments/replies)
     when JavaScript is off. Variables: $comment (on one of the member's own videos: the controller answers 404
     otherwise), $replies (a paginator), $me. --}}
@php
    use App\Support\Studio\StudioFormat;

    $video = $comment->video;
    $videoTitle = $video ? (string) ($video->getRawOriginal('title') ?: __('Untitled video')) : __('A deleted video');
    $parentAuthor = $comment->user?->username ?? __('Deleted member');
    $total = $replies->total();
@endphp
<div class="mk-sheet__body" data-mk-replies-page>
    <div class="mk-item mk-item--parent">
        @if($comment->user)
            <img class="mk-avatar mk-avatar--36" src="{{ $comment->user->avatar_url }}" alt="" width="36" height="36" loading="lazy" decoding="async">
        @else
            <span class="mk-avatar mk-avatar--36" aria-hidden="true"></span>
        @endif
        <div class="mk-item__body">
            <div><strong><bdi>{{ $parentAuthor }}</bdi></strong> <span class="mk-muted" style="font-size:.8125rem">· {{ StudioFormat::ago($comment->created_at) }}</span></div>
            {{-- R1-025: a comment keeps its own direction in every language; names and titles are isolated --}}
            <p class="mk-replies__text" dir="auto">{{ $comment->content }}</p>
            @if($video)
                <span class="mk-muted" style="font-size:.8125rem">{!! strtr(e(__('on :title')), [':title' => '<a class="mk-quiet-link" href="'.e($video->route).'#comments_area"><bdi>'.e($videoTitle).'</bdi></a>']) !!}</span>
            @endif
        </div>
    </div>
    <strong>{{ trans_choice(':count reply|:count replies', $total, ['count' => StudioFormat::number($total)]) }}</strong>
    @if($total === 0)
        <p class="mk-muted">{{ __('The replies were removed.') }}</p>
    @else
        <ul class="mk-rows">
            @foreach($replies as $reply)
                @php($replyAuthor = $reply->user?->username ?? __('Deleted member'))
                <li class="mk-item">
                    @if($reply->user)
                        <img class="mk-avatar mk-avatar--32" src="{{ $reply->user->avatar_url }}" alt="" width="32" height="32" loading="lazy" decoding="async">
                    @else
                        <span class="mk-avatar mk-avatar--32" aria-hidden="true"></span>
                    @endif
                    <div class="mk-item__body">
                        <div><strong><bdi>{{ $replyAuthor }}</bdi></strong>@if($me && (int) $reply->user_id === (int) $me->id) <span class="mk-badge mk-badge--neutral">{{ __('You') }}</span>@endif <span class="mk-muted" style="font-size:.8125rem">· {{ StudioFormat::ago($reply->created_at) }}</span></div>
                        <p class="mk-replies__text" dir="auto">{{ $reply->content }}</p>
                    </div>
                    <div class="mk-item__aside">
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--danger" title="{{ __('Remove reply') }}" aria-label="{{ __('Remove the reply by :name', ['name' => $replyAuthor]) }}" data-bs-toggle="modal" data-bs-target="#mk-remove-reply" data-action="{{ route('user.comments.destroy', $reply) }}" data-title="{{ \Illuminate\Support\Str::limit((string) $reply->content, 80) }}"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                    </div>
                </li>
            @endforeach
        </ul>
        @if($replies->hasPages())
            <div data-mk-replies-pager>{{ $replies->onEachSide(1)->links('vendor.pagination.mk') }}</div>
        @endif
    @endif
</div>
@if($video)
    <div class="mk-sheet__foot">
        <a class="mk-btn mk-btn--secondary" href="{{ $video->route }}#comments_area"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span>{{ __('Open on the video') }}</span></a>
    </div>
@endif
