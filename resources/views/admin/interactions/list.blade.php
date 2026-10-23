{{--
    Content > Views & searches > Likes, in the redesigned admin (the old admin keeps interactions/index.blade.php): the
    10 most liked videos (folded), every like and dislike with likes / dislikes only, bulk delete, a row menu filled
    from one template (modules/list-rows.ts: delete with Undo), clearing them all, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Views & searches'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="content.stats"
        :description="__(':likes likes and :dislikes dislikes from :members members, :today today.', ['likes' => number_format($summary['likes']), 'dislikes' => number_format($summary['dislikes']), 'members' => number_format($summary['members']), 'today' => number_format($summary['today'])])">
        <x-slot:actions>
            @if ($summary['total'] > 0)
                <form method="POST" action="{{ route('admin.interactions.clear') }}"
                      data-ad-confirm="{{ __('Delete every like and dislike?') }}" data-ad-confirm-body="{{ __('Every like and dislike of every member goes. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete all') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete all...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($summary['top'] !== [])
        <details class="ad-panel ad-console-block ad-fold">
            <summary class="ad-panel-h"><h2>{{ __('Most liked videos') }}</h2></summary>
            <ol class="ad-list">
                @foreach ($summary['top'] as $video)
                    <li><span class="ad-li-text"><a href="{{ route('admin.videos.edit', $video['id'], false) }}">{{ \Illuminate\Support\Str::limit($video['title'], 80) }}</a></span><span>{{ trans_choice('1 like|:count likes', $video['likes'], ['count' => number_format($video['likes'])]) }}</span></li>
                @endforeach
            </ol>
        </details>
    @endif

    <form id="ad-interactions-bulk" method="POST" action="{{ route('admin.bulk.interactions') }}" hidden>@csrf</form>
    <form id="ad-interaction-delete" method="POST" action="{{ route('admin.interactions.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Deleted') }}"
          data-ad-confirm="{{ __('Delete this like or dislike?') }}" data-ad-confirm-body="{{ __('You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-interaction-menu">
        <a href="{{ route('admin.videos.edit', ['video' => '__VIDEO__'], false) }}" data-ad-if="video">{{ AdminTheme::icon('film') }}{{ __('Open video') }}</a>
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <hr>
        <button type="submit" form="ad-interaction-delete" formaction="{{ route('admin.interactions.delete', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
    </template>

    <x-admin.table :paginator="$interactions" :label="__('Likes and dislikes')" list="interactions" table-class="is-list" row-menu="ad-interaction-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.interactions.index') }}" class="ad-filter" aria-label="{{ __('Filter likes') }}">
                <select class="ad-select" name="kind" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                    <option value="">{{ __('Likes and dislikes') }}</option>
                    <option value="likes" @selected($kind === 'likes')>{{ __('Likes') }}</option>
                    <option value="dislikes" @selected($kind === 'dislikes')>{{ __('Dislikes') }}</option>
                </select>
                <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn is-danger sm" form="ad-interactions-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected likes and dislikes?') }}" data-ad-confirm-body="{{ __('This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all on this page') }}"></th>
            <th scope="col">{{ __('Liked') }}</th>
            <th scope="col">{{ __('Like or dislike') }}</th>
            <th scope="col" class="hide-tab">{{ __('Member') }}</th>
            <th scope="col">{{ __('When') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="heart" :title="__('No likes yet')">{{ __('When members like or dislike a video or a comment, it is listed here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($interactions as $interaction)
            @php
                $when = \Illuminate\Support\Carbon::parse($interaction->perform_at);
                $what = $interaction->video_title ?? __(':kind #:id', ['kind' => \Illuminate\Support\Str::headline(class_basename((string) $interaction->likeable_type)), 'id' => $interaction->likeable_id]);
            @endphp
            <tr>
                <td class="is-check"><input type="checkbox" name="ids[]" value="{{ $interaction->id }}" form="ad-interactions-bulk" aria-labelledby="ad-l-sel ad-i-{{ $interaction->id }}"></td>
                <td class="c-main"><p><b id="ad-i-{{ $interaction->id }}">{{ \Illuminate\Support\Str::limit((string) $what, 90) }}</b><small class="ad-phone-only">{{ $interaction->username }}</small></p></td>
                <td class="c-meta"><x-admin.status :type="(int) $interaction->status === 1 ? 'ok' : 'danger'">{{ (int) $interaction->status === 1 ? __('Like') : __('Dislike') }}</x-admin.status></td>
                <td class="hide-tab"><p class="ad-cell-two"><b>{{ $interaction->username }}</b><small>{{ $interaction->email }}</small></p></td>
                <td class="is-date" title="{{ $when->translatedFormat('j M Y, H:i') }}">{{ $when->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-i-{{ $interaction->id }}" data-id="{{ $interaction->id }}" data-user="{{ $interaction->user_id }}" @if ($interaction->video_title !== null) data-video="{{ $interaction->likeable_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
