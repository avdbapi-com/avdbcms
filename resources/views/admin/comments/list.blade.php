{{--
    Community > Comments in the redesigned admin (the old admin keeps comments/index.blade.php): Visible / Hidden tabs,
    search, bulk actions, each comment with its video and its latest replies in the row (they replace the old replies
    modal), a row menu filled from one template (modules/list-rows.ts), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Comments'))

@section('content')
    @php
        $ban = (string) request()->query('ban', '');
        $video = request()->query('video');
        $tab = fn (string $value, int $count, string $label) => [
            'label' => $label,
            'url' => route('admin.comments.index', array_filter(['ban' => $value, 'video' => $video])),
            'active' => $ban === $value,
            'count' => number_format($count),
        ];
        $tabs = [
            $tab('', $counts['all'], __('All')),
            $tab('not_banned', $counts['all'] - $counts['hidden'], __('Visible')),
            $tab('banned', $counts['hidden'], __('Hidden')),
        ];
        $filtered = request()->filled('search') || $video !== null;
    @endphp

    <x-admin.page-header page="community.comments" :tabs="$tabs" />

    <form id="ad-comments-bulk" method="POST" action="{{ route('admin.bulk.comments') }}" hidden>@csrf</form>
    <form id="ad-comment-hide" method="POST" action="{{ route('admin.bulk.comments') }}" data-ad-row-action="change" data-ad-done="{{ __('Comment hidden') }}" hidden></form>
    <form id="ad-comment-show" method="POST" action="{{ route('admin.bulk.comments') }}" data-ad-row-action="change" data-ad-done="{{ __('Comment visible again') }}" hidden></form>
    <form id="ad-comment-delete" method="POST" action="{{ route('admin.bulk.comments') }}" data-ad-row-action="delete" data-ad-done="{{ __('Comment deleted') }}"
          data-ad-confirm="{{ __('Delete this comment?') }}" data-ad-confirm-body="{{ __('Its replies and likes are deleted too. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete comment') }}" hidden>@method('DELETE')</form>
    <form id="ad-reply-delete" method="POST" action="{{ route('admin.bulk.comments') }}" data-ad-row-action="delete" data-ad-done="{{ __('Reply deleted') }}"
          data-ad-confirm="{{ __('Delete this reply?') }}" data-ad-confirm-label="{{ __('Delete reply') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-select">{{ __('Select the comment of') }}</span><span hidden id="ad-l-actions">{{ __('Actions for the comment of') }}</span>
    <template id="ad-comment-menu">
        <a href="__URL__" target="_blank" rel="noopener" data-ad-if="url">{{ AdminTheme::icon('external') }}{{ __('Open the video') }}</a>
        <a href="{{ route('admin.comments.index', ['video' => '__VIDEO__'], false) }}">{{ AdminTheme::icon('chat') }}{{ __('All comments on this video') }}</a>
        <a href="{{ route('admin.comments.index', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('All comments of this member') }}</a>
        <hr>
        <button type="submit" form="ad-comment-show" formaction="{{ route('admin.comments.unban', ['comment' => '__ID__'], false) }}" data-ad-when="hidden" data-ad-expect="visible" data-ad-undo-url="{{ route('admin.comments.ban', ['comment' => '__ID__'], false) }}">{{ AdminTheme::icon('eye') }}{{ __('Show again') }}</button>
        <button type="submit" form="ad-comment-hide" formaction="{{ route('admin.comments.ban', ['comment' => '__ID__'], false) }}" data-ad-when="visible" data-ad-expect="hidden" data-ad-undo-url="{{ route('admin.comments.unban', ['comment' => '__ID__'], false) }}">{{ AdminTheme::icon('eye-off') }}{{ __('Hide comment') }}</button>
        <button type="submit" form="ad-comment-delete" formaction="{{ route('admin.comments.delete', ['comment' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete comment...') }}</button>
    </template>

    <x-admin.table :paginator="$comments" :label="__('Comments')" list="comments" table-class="is-list">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.comments.index') }}" class="ad-filter" role="search" aria-label="{{ __('Find comments') }}">
                @foreach (['ban' => $ban, 'video' => $video, 'user' => request('user')] as $name => $value)
                    @if ($value !== null && $value !== '')
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Search the comments') }}" aria-label="{{ __('Search the comments') }}">
                </span>
                <select class="ad-select" name="replies" aria-label="{{ __('Replies') }}" data-ad-autosubmit>
                    <option value="">{{ __('With or without replies') }}</option>
                    <option value="with" @selected(request('replies') === 'with')>{{ __('With replies') }}</option>
                    <option value="without" @selected(request('replies') === 'without')>{{ __('Without replies') }}</option>
                </select>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if ($filtered || request()->filled('replies') || request()->filled('user'))
                    <a class="ad-btn is-ghost" href="{{ route('admin.comments.index', array_filter(['ban' => $ban])) }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-comments-bulk" name="action" value="ban">{{ __('Hide') }}</button>
            <button type="submit" class="ad-btn sm" form="ad-comments-bulk" name="action" value="unban">{{ __('Show again') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-comments-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected comments?') }}" data-ad-confirm-body="{{ __('Their replies and likes are deleted too. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete comments') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all comments on this page') }}"></th>
            <th scope="col">{{ __('Comment') }}</th>
            <th scope="col">{{ __('Status') }}</th>
            <th scope="col" class="hide-tab">{{ __('Video') }}</th>
            <th scope="col" class="is-num">{{ __('Replies') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Likes') }}</th>
            <th scope="col">{{ __('Posted') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="chat" :title="$filtered || $ban !== '' ? __('No comments match') : __('No comments yet')">{{ __('Comments visitors leave under your videos appear here.') }}</x-admin.empty>
        </x-slot:empty>

        @php ob_start(); @endphp
        @foreach ($comments as $comment)
            @php
                $author = $comment->user?->username ?? __('Deleted member');
                $hidden = $comment->banned_at !== null;
                $videoTitle = $comment->video ? (string) $comment->video->title : __('a deleted video');
                $open = $comment->video && ! $comment->video->trashed() && ($comment->video->is_public || $comment->video->is_unlisted);
            @endphp
            <tr>
                <td class="is-check"><input type="checkbox" class="ad-check" name="ids[]" value="{{ $comment->id }}" form="ad-comments-bulk" data-ad-select aria-labelledby="ad-l-select ad-c-{{ $comment->id }}"></td>
                <td class="c-main">
                    <b class="ad-comment-author" id="ad-c-{{ $comment->id }}">{{ $author }}</b>
                    <span class="ad-comment-text">{{ \Illuminate\Support\Str::limit((string) $comment->content, 220) }}</span>
                    @if ((int) $comment->replies_count > 0)
                        <details class="ad-replies">
                            <summary>{{ trans_choice('1 reply|:count replies', (int) $comment->replies_count, ['count' => (int) $comment->replies_count]) }}</summary>
                            <ul>
                                @foreach ($comment->replies as $reply)
                                    <li data-ad-row>
                                        <span><b>{{ $reply->user?->username ?? __('Deleted member') }}</b> {{ \Illuminate\Support\Str::limit((string) $reply->content, 160) }}</span>
                                        <button type="submit" class="ad-linkbtn ad-small" form="ad-reply-delete" formaction="{{ route('admin.comments.delete', $reply, false) }}">{{ __('Delete') }}<span class="ad-vh"> {{ __('the reply of :name', ['name' => $reply->user?->username ?? __('Deleted member')]) }}</span></button>
                                    </li>
                                @endforeach
                            </ul>
                            @if ((int) $comment->replies_count > $comment->replies->count() && $open)
                                <a class="ad-small" href="{{ $comment->video->route }}" target="_blank" rel="noopener">{{ __('See every reply on the video page') }}</a>
                            @endif
                        </details>
                    @endif
                </td>
                <td class="c-meta">
                    <x-admin.status :type="$hidden ? 'off' : 'ok'">{{ $hidden ? __('Hidden') : __('Visible') }}</x-admin.status>
                    <span class="ad-phone-only ad-faint">{{ __('on :video', ['video' => \Illuminate\Support\Str::limit($videoTitle, 40)]) }}</span>
                </td>
                <td class="hide-tab">
                    @if ($comment->video)
                        <a class="ad-comment-video" href="{{ route('admin.comments.index', ['video' => $comment->video_id], false) }}">{{ \Illuminate\Support\Str::limit($videoTitle, 60) }}</a>
                    @else
                        <span class="ad-muted">{{ __('Deleted video') }}</span>
                    @endif
                </td>
                <td class="is-num">{{ number_format((int) $comment->replies_count) }}</td>
                <td class="is-num hide-tab">{{ number_format((int) $comment->likes_count) }}</td>
                <td class="ad-muted is-date"><time datetime="{{ $comment->created_at?->toIso8601String() }}" title="{{ $comment->created_at?->translatedFormat('j M Y, H:i') }}">{{ $comment->created_at?->diffForHumans(short: true) }}</time></td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" data-ad-row-menu="ad-comment-menu" aria-haspopup="true" aria-labelledby="ad-l-actions ad-c-{{ $comment->id }}" data-id="{{ $comment->id }}" data-state="{{ $hidden ? 'hidden' : 'visible' }}" data-video="{{ $comment->video_id }}"@if ($comment->user_id) data-user="{{ $comment->user_id }}"@endif @if ($open) data-url="{{ $comment->video->route }}"@endif></button></td>
            </tr>
        @endforeach
        {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
    </x-admin.table>
@endsection
