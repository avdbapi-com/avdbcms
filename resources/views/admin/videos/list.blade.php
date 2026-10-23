{{--
    Content > All videos in the redesigned admin (the old admin keeps videos/index.blade.php): status tabs with counts,
    search / category / sort, bulk actions on the ticked rows, a row menu per video (status changes and Delete with
    Undo, modules/list-rows.ts) and the pager. 25 rows by default; posters load lazily at their shown size. The rows'
    menu buttons get aria-haspopup from list-rows.ts (20 bytes a row: this page stays under the 60 KB list budget).
--}}
@use('App\Enums\VideoStatus')
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('All videos'))

@section('content')
    {{-- The page goes out without the template's indentation (see the end): 25 rows stay inside the list budget. --}}
    @php ob_start(); @endphp
    @php
        $status = request()->query('status');
        $statusTab = fn (?string $value, int $count, string $label) => [
            'label' => $label,
            'url' => route('admin.videos.index', array_filter(['status' => $value], fn ($v) => $v !== null), false),
            'active' => (string) $status === (string) $value,
            'count' => number_format($count),
        ];
        $tabs = array_values(array_filter([
            $statusTab(null, $counts['all'], __('All')),
            $statusTab((string) VideoStatus::PUBLIC->value, $counts['public'], __('Public')),
            $counts['unlisted'] > 0 ? $statusTab((string) VideoStatus::UNLISTED->value, $counts['unlisted'], __('Unlisted')) : null,
            $statusTab((string) VideoStatus::PRIVATE->value, $counts['private'], __('Private')),
            $counts['planned'] > 0 ? $statusTab((string) VideoStatus::PLANNED->value, $counts['planned'], __('Scheduled')) : null,
            $counts['banned'] > 0 ? $statusTab((string) VideoStatus::BANNED->value, $counts['banned'], __('Banned')) : null,
        ]));
        $filtered = collect(request()->only(['search', 'category', 'user', 'date_start', 'date_end']))->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
        // [dot tone, word, state for the row menu, why a public video is not shown]. The Public tab counts what visitors
        // see: a public video whose creator is blocked, deleted or has not confirmed their email is not listed there.
        $stateOf = function ($video): array {
            $creator = $video->user;
            $hiddenBecause = match (true) {
                $creator === null => __('Creator deleted'),
                $creator->banned_at !== null => __('Creator blocked'),
                $creator->email_verified_at === null => __('Creator email not confirmed'),
                default => null,
            };

            return match (true) {
                $video->is_failed => ['danger', __('Failed'), 'failed', null],
                $video->is_banned => ['danger', __('Banned'), 'banned', null],
                $video->is_uploading => ['off', __('Processing'), 'processing', null],
                $video->is_draft => ['off', __('Draft'), 'draft', null],
                $video->is_unlisted => ['off', __('Unlisted'), 'unlisted', null],
                $video->is_private => ['off', __('Private'), 'private', null],
                $video->is_planned => ['info', __('Scheduled'), 'planned', null],
                $hiddenBecause !== null => ['warn', __('Not shown'), 'public', $hiddenBecause],
                default => ['ok', __('Public'), 'public', null],
            };
        };
    @endphp

    <x-admin.page-header page="content.videos"
        :description="trans_choice('1 video. Visitors only see public ones.|:count videos. Visitors only see public ones.', $counts['all'], ['count' => number_format($counts['all'])])"
        :tabs="$tabs">
        <x-slot:actions>
            <a class="ad-btn is-primary" href="{{ route('admin.avdb-import.index', [], false) }}">{{ AdminTheme::icon('cloud-down') }}{{ __('Import videos') }}</a>
        </x-slot:actions>
        @if ($counts['not_shown'] > 0)
            <p class="ad-small ad-muted ad-note">{{ trans_choice('1 public video is not shown on your site: its creator is blocked or has not confirmed their email.|:count public videos are not shown on your site: their creators are blocked or have not confirmed their email.', $counts['not_shown'], ['count' => number_format($counts['not_shown'])]) }}
                <a href="{{ route('admin.verification.index', [], false) }}">{{ __('Confirm emails') }}</a></p>
        @endif
    </x-admin.page-header>

    <form id="ad-videos-bulk" method="POST" action="{{ route('admin.bulk.videos', [], false) }}" hidden>@csrf</form>
    {{-- The row menus' actions: one form each, the row's button brings its video (ids[] or formaction). --}}
    <form id="ad-video-private" method="POST" action="{{ route('admin.bulk.videos', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('The video is private now') }}" hidden><input type="hidden" name="action" value="make_private"></form>
    <form id="ad-video-public" method="POST" action="{{ route('admin.bulk.videos', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('The video is public now') }}" hidden><input type="hidden" name="action" value="make_public"></form>
    <form id="ad-video-ban" method="POST" action="{{ route('admin.bulk.videos', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('The video is banned') }}"
          data-ad-confirm="{{ __('Ban this video?') }}" data-ad-confirm-body="{{ __('Visitors can no longer watch it. It stays in this list, where you can unban it.') }}" data-ad-confirm-label="{{ __('Ban video') }}" hidden></form>
    <form id="ad-video-unban" method="POST" action="{{ route('admin.bulk.videos', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('The video is public again') }}" hidden></form>
    <form id="ad-video-delete" method="POST" action="{{ route('admin.bulk.videos', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Video deleted') }}"
          data-ad-confirm="{{ __('Delete this video for good?') }}" data-ad-confirm-body="{{ __('Its file, comments and views are deleted too. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete video') }}" hidden>@method('DELETE')</form>
    {{-- Names the rows' checkboxes and menu buttons together with each video's title (aria-labelledby). --}}
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-vmenu">
        <a href="{{ route('admin.videos.edit', ['video' => '__ID__'], false) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit details') }}</a>
        <a href="__URL__" target="_blank" rel="noopener" data-ad-when="public,unlisted">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
        <a href="{{ route('user.videos.show', ['video' => '__ID__'], false) }}">{{ AdminTheme::icon('chart') }}{{ __('Statistics') }}</a>
        <a href="{{ route('admin.comments.index', ['video' => '__ID__'], false) }}" data-ad-if="comments">{{ AdminTheme::icon('chat') }}{{ __('Comments') }}</a>
        <button type="submit" form="ad-video-private" name="ids[]" value="__ID__" data-ad-when="public" data-ad-expect="private" data-ad-undo-url="{{ route('admin.bulk.videos', [], false) }}" data-ad-undo-fields='{"ids":["__ID__"],"action":"make_public"}'>{{ AdminTheme::icon('eye-off') }}{{ __('Make private') }}</button>
        <button type="submit" form="ad-video-private" name="ids[]" value="__ID__" data-ad-when="unlisted" data-ad-expect="private" data-ad-undo-url="{{ route('admin.bulk.videos', [], false) }}" data-ad-undo-fields='{"ids":["__ID__"],"action":"make_unlisted"}'>{{ AdminTheme::icon('eye-off') }}{{ __('Make private') }}</button>
        <button type="submit" form="ad-video-public" name="ids[]" value="__ID__" data-ad-when="private" data-ad-expect="public" data-ad-undo-url="{{ route('admin.bulk.videos', [], false) }}" data-ad-undo-fields='{"ids":["__ID__"],"action":"make_private"}'>{{ AdminTheme::icon('eye') }}{{ __('Make public') }}</button>
        <hr>
        <button type="submit" form="ad-video-unban" formaction="{{ route('admin.videos.unban', ['video' => '__ID__'], false) }}" data-ad-when="banned" data-ad-expect="!banned">{{ AdminTheme::icon('check-circle') }}{{ __('Unban') }}</button>
        <button type="submit" form="ad-video-ban" formaction="{{ route('admin.videos.ban', ['video' => '__ID__'], false) }}" class="is-danger" data-ad-when="public" data-ad-expect="banned" data-ad-undo-url="{{ route('admin.videos.unban', ['video' => '__ID__'], false) }}">{{ AdminTheme::icon('ban') }}{{ __('Ban video') }}</button>
        <button type="submit" form="ad-video-ban" formaction="{{ route('admin.videos.ban', ['video' => '__ID__'], false) }}" class="is-danger" data-ad-when="!public,banned" data-ad-expect="banned">{{ AdminTheme::icon('ban') }}{{ __('Ban video') }}</button>
        <button type="submit" form="ad-video-delete" formaction="{{ route('admin.videos.delete', ['video' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete video...') }}</button>
    </template>

    <x-admin.table :paginator="$videos" :label="__('Videos')" list="videos" table-class="is-list" row-menu="ad-vmenu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.videos.index', [], false) }}" class="ad-filter" role="search" aria-label="{{ __('Find videos') }}">
                @if ($status !== null && $status !== '')
                    <input type="hidden" name="status" value="{{ $status }}">
                @endif
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Search titles and descriptions') }}" aria-label="{{ __('Search videos') }}">
                </span>
                <select class="ad-select" id="ad-videos-category" name="category" aria-label="{{ __('Category') }}" data-ad-autosubmit>
                    <option value="">{{ __('All categories') }}</option>
                    <option value="without" @selected(request('category') === 'without')>{{ __('No category') }}</option>
                    @foreach ($categories as $id => $title)
                        <option value="{{ $id }}" @selected((string) request('category') === (string) $id)>{{ $title }}</option>
                    @endforeach
                </select>
                <details class="ad-filter-more" @if(request()->filled('date_start') || request()->filled('date_end')) open @endif>
                    <summary class="ad-btn">{{ AdminTheme::icon('calendar') }}{{ __('Published') }}</summary>
                    <div class="ad-filter-pop">
                        <label class="ad-label" for="ad-videos-from">{{ __('From') }}</label>
                        <input class="ad-input" id="ad-videos-from" type="datetime-local" name="date_start" value="{{ request('date_start') }}">
                        <label class="ad-label" for="ad-videos-to">{{ __('To') }}</label>
                        <input class="ad-input" id="ad-videos-to" type="datetime-local" name="date_end" value="{{ request('date_end') }}">
                    </div>
                </details>
                @if (request()->filled('user'))
                    <input type="hidden" name="user" value="{{ request('user') }}">
                @endif
                <span class="ad-spacer"></span>
                <select class="ad-select" name="sort" aria-label="{{ __('Sort') }}" data-ad-autosubmit>
                    <option value="newest" @selected($sort === 'newest')>{{ __('Newest first') }}</option>
                    <option value="oldest" @selected($sort === 'oldest')>{{ __('Oldest first') }}</option>
                    <option value="views" @selected($sort === 'views')>{{ __('Most viewed') }}</option>
                    <option value="title" @selected($sort === 'title')>{{ __('Title A-Z') }}</option>
                </select>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if ($filtered)
                    <a class="ad-btn is-ghost" href="{{ route('admin.videos.index', array_filter(['status' => $status]), false) }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-videos-bulk" name="action" value="make_public">{{ __('Make public') }}</button>
            <button type="submit" class="ad-btn sm" form="ad-videos-bulk" name="action" value="make_unlisted">{{ __('Make unlisted') }}</button>
            <button type="submit" class="ad-btn sm" form="ad-videos-bulk" name="action" value="make_private">{{ __('Make private') }}</button>
            <span class="ad-bulk-group">
                {{-- Its categories are the toolbar's (modules/table.ts copies them when the bar first shows). --}}
                <select class="ad-select" name="category_id" form="ad-videos-bulk" aria-label="{{ __('New category') }}" data-ad-options-from="ad-videos-category">
                    <option value="">{{ __('Choose a category') }}</option>
                </select>
                <button type="submit" class="ad-btn sm" form="ad-videos-bulk" name="action" value="change_category">{{ __('Change category') }}</button>
            </span>
            <button type="submit" class="ad-btn sm hide-phone" form="ad-videos-bulk" name="action" value="resync_duration">{{ __('Check durations again') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-videos-bulk" name="action" value="ban"
                    data-ad-confirm="{{ __('Ban the selected videos?') }}" data-ad-confirm-body="{{ __('Visitors can no longer watch them. They stay in this list, where you can unban them.') }}" data-ad-confirm-label="{{ __('Ban videos') }}">{{ __('Ban') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-videos-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected videos for good?') }}" data-ad-confirm-body="{{ __('Their files, comments and views are deleted too. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete videos') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all videos on this page') }}"></th>
            <th scope="col">{{ __('Video') }}</th>
            <th scope="col">{{ __('Status') }}</th>
            <th scope="col" class="hide-tab">{{ __('Category') }}</th>
            <th scope="col" class="is-num">{{ __('Views') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Comments') }}</th>
            <th scope="col">{{ __('Added') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            @if ($filtered || ($status !== null && $status !== ''))
                <x-admin.empty icon="search" :title="__('No videos match')">{{ __('Try another word, or clear the filters.') }}</x-admin.empty>
            @else
                <x-admin.empty icon="film" :title="__('No videos yet')" :action="__('Import your first videos')" :href="route('admin.avdb-import.index', [], false)">{{ __('Videos you import or members upload appear here.') }}</x-admin.empty>
            @endif
        </x-slot:empty>

        {{-- Each row on one line, with short tags (the cells' classes style them) and no menu box of its own
             (modules/list-rows.ts makes the menu from the template when it opens). --}}
        @foreach ($videos as $video)
            @php
                [$tone, $stateLabel, $stateKey, $hiddenBecause] = $stateOf($video);
                $added = $video->created_at;
                $comments = (int) $video->comments_count;
            @endphp
            <tr><td class="is-check"><input type="checkbox" name="ids[]" value="{{ $video->id }}" form="ad-videos-bulk" aria-labelledby="ad-l-sel ad-v-{{ $video->id }}"></td><td class="c-main"><div class="ad-vid"><span class="ad-thumb">@unless ($video->is_draft)<img src="{{ $video->thumbnail_url }}" alt="" width="96" height="54" loading="lazy" decoding="async">@endunless @if ($video->duration !== '')<i>{{ $video->duration }}</i>@endif</span><p><a id="ad-v-{{ $video->id }}" href="{{ route('admin.videos.edit', $video, false) }}">{{ \Illuminate\Support\Str::limit((string) $video->title, 100) }}</a><small>{{ $video->user?->username ?? __('Deleted member') }}</small></p></div></td><td class="c-meta"><x-admin.status :type="$tone">{{ $stateLabel }}</x-admin.status>@if ($hiddenBecause)<small>{{ $hiddenBecause }}</small>@endif<small class="ad-phone-only">{{ trans_choice('1 view|:count views', (int) $video->views, ['count' => number_format((int) $video->views)]) }}</small></td><td class="hide-tab">{{ $video->category?->title ?? '-' }}</td><td class="is-num">{{ number_format((int) $video->views) }}</td><td class="is-num hide-tab">@if ($comments > 0)<a href="{{ route('admin.comments.index', ['video' => $video->id], false) }}">{{ number_format($comments) }}</a>@else 0 @endif</td><td class="is-date">{{ $added?->isToday() ? __('Today') : ($added?->isYesterday() ? __('Yesterday') : $added?->translatedFormat($added->isCurrentYear() ? 'j M' : 'j M Y')) }}</td><td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-labelledby="ad-l-act ad-v-{{ $video->id }}" data-id="{{ $video->id }}" data-state="{{ $stateKey }}"@if ($stateKey === 'public' || $stateKey === 'unlisted') data-url="{{ preg_replace('#^https?://[^/]+#', '', $video->route) }}"@endif @if ($comments > 0) data-comments="1"@endif></button></td></tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
