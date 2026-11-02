{{-- Delete one video from Video details (member design 6.6): a dialog in the look's "Pop-up window style"
     (a side sheet in the sliding-sheet looks, centred in the others, a bottom sheet on phones), what goes with the video,
     the gentle choice ("Make private instead") and the final Delete. My videos opens the same dialog filled per row. --}}
@php
    use App\Support\Studio\StudioFormat;
    $dvStatus = $video->status instanceof \App\Enums\VideoStatus ? $video->status : \App\Enums\VideoStatus::tryFrom((int) $video->status);
    $dvChangeable = ! in_array($dvStatus, [\App\Enums\VideoStatus::BANNED, \App\Enums\VideoStatus::FAILED, \App\Enums\VideoStatus::DRAFT], true) && ! $video->is_uploading;
    $dvPoster = ($video->thumbnail || ! empty($video->poster_url)) ? $video->thumbnail_url : null;
@endphp
<div class="modal fade" id="mk-delete-video" tabindex="-1" aria-labelledby="mk-delete-video-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
        <div class="modal-content avd-mk">
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="mk-delete-video-title">{{ __('Delete this video?') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-summary">
                    <span class="mk-thumb">
                        @if($dvPoster)
                            <img src="{{ $dvPoster }}" alt="" width="160" height="90" loading="lazy" decoding="async">
                        @else
                            <span class="mk-thumb__empty"><i class="fa-regular fa-image" aria-hidden="true"></i></span>
                        @endif
                        @if($video->duration !== '')<span class="mk-thumb__dur">{{ $video->duration }}</span>@endif
                    </span>
                    <div style="display:grid;gap:.2rem;min-width:0">
                        <strong class="mk-clamp-2" dir="auto">{{ (string) ($video->getRawOriginal('title') ?: __('Untitled video')) }}</strong>
                        <span class="mk-muted" style="font-size:.8125rem">{!! e(trans_choice(':count view|:count views', (int) $video->views, ['count' => StudioFormat::number((int) $video->views)])) !!}&nbsp;· {{ StudioFormat::date($video->published_at ?? $video->created_at) }}</span>
                        <span><x-mk.status :video="$video" /></span>
                    </div>
                </div>
                <div style="display:grid;gap:.6rem">
                    <strong>{{ __('This also deletes') }}</strong>
                    <ul class="mk-facts">
                        <li><i class="fa-regular fa-comment" aria-hidden="true"></i>{{ trans_choice(':count comment and its replies|:count comments and their replies', (int) $video->comments_count, ['count' => StudioFormat::number((int) $video->comments_count)]) }}</li>
                        <li><i class="fa-regular fa-thumbs-up" aria-hidden="true"></i>{{ __(':likes and :dislikes', [
                            'likes' => trans_choice(':count like|:count likes', (int) $video->likes_count, ['count' => StudioFormat::number((int) $video->likes_count)]),
                            'dislikes' => trans_choice(':count dislike|:count dislikes', (int) $video->dislikes_count, ['count' => StudioFormat::number((int) $video->dislikes_count)]),
                        ]) }}</li>
                        @if($video->playlists_count)
                            <li><i class="fa-solid fa-list" aria-hidden="true"></i>{{ trans_choice('Its place in :count playlist|Its place in :count playlists', (int) $video->playlists_count, ['count' => StudioFormat::number((int) $video->playlists_count)]) }}</li>
                        @endif
                        <li><i class="fa-regular fa-file-video" aria-hidden="true"></i>{{ __('The video file and its thumbnails') }}</li>
                    </ul>
                </div>
                <x-mk.alert type="danger">{{ __('You can\'t undo this. To hide the video instead, make it private.') }}</x-mk.alert>
            </div>
            <div class="mk-sheet__foot">
                @if($dvChangeable && $dvStatus !== \App\Enums\VideoStatus::PRIVATE)
                    <button type="submit" class="mk-btn mk-btn--quiet" form="mk-private-one"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i><span>{{ __('Make private instead') }}</span></button>
                @endif
                <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                <button type="submit" class="mk-btn mk-btn--danger" form="mk-delete-one"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete video') }}</span></button>
            </div>
            <form id="mk-delete-one" method="POST" action="{{ route('user.videos.destroy', $video) }}" hidden>@csrf @method('DELETE')</form>
            @if($dvChangeable)
                <form id="mk-private-one" method="POST" action="{{ route('user.videos.mass-visibility') }}" hidden>@csrf<input type="hidden" name="ids[]" value="{{ $video->id }}"><input type="hidden" name="visibility" value="private"></form>
            @endif
        </div>
    </div>
</div>
