{{--
    Content > Views & searches > Views, in the redesigned admin (the old admin keeps views/index.blade.php): the totals
    of the video counter, the 10 most watched videos (folded), the latest views kept in the history (what the history
    keeps depends on the view-history setting), a row menu filled from one template (modules/list-rows.ts: delete one
    view with Undo), clearing the history, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Views & searches'))

@section('content')
    <x-admin.list-page>
    @php
        $modes = [
            'detailed' => __('The history keeps every view.'),
            'authenticated' => __('The history keeps the views of signed-in members only.'),
            'counter_only' => __('The history keeps no views: only the counters go up.'),
        ];
    @endphp

    <x-admin.page-header page="content.stats"
        :description="__(':total views in all (counted on the videos), :today today. :ips different addresses and :members members are in the history.', ['total' => number_format($summary['total']), 'today' => number_format($summary['today']), 'ips' => number_format($summary['unique_ips']), 'members' => number_format($summary['logged_in_viewers'])])">
        <x-slot:actions>
            @if ($views->total() > 0)
                <form method="POST" action="{{ route('admin.views.clear') }}"
                      data-ad-confirm="{{ __('Clear the view history?') }}" data-ad-confirm-body="{{ __('The view counts of your videos stay. Only the list of who watched what goes. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Clear history') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Clear history...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <p class="ad-help ad-console-block">{{ $modes[$viewLogMode] ?? '' }}</p>

    @if ($summary['top'] !== [])
        <details class="ad-panel ad-console-block ad-fold">
            <summary class="ad-panel-h"><h2>{{ __('Most watched videos') }}</h2></summary>
            <ol class="ad-list">
                @foreach ($summary['top'] as $video)
                    <li><span class="ad-li-text"><a href="{{ route('admin.videos.edit', $video['id'], false) }}">{{ \Illuminate\Support\Str::limit($video['title'], 80) }}</a></span><span>{{ trans_choice('1 view|:count views', $video['views'], ['count' => number_format($video['views'])]) }}</span></li>
                @endforeach
            </ol>
        </details>
    @endif

    <form id="ad-view-delete" method="POST" action="{{ route('admin.views.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Removed from the history') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-view-menu">
        <a href="{{ route('admin.videos.edit', ['video' => '__VIDEO__'], false) }}" data-ad-if="video">{{ AdminTheme::icon('film') }}{{ __('Open video') }}</a>
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <hr>
        <button type="submit" form="ad-view-delete" formaction="{{ route('admin.views.delete', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Remove from the history') }}</button>
    </template>

    <x-admin.table :paginator="$views" :label="__('Latest views')" list="views" table-class="is-list" row-menu="ad-view-menu">
        <x-slot:head>
            <th scope="col">{{ __('Video') }}</th>
            <th scope="col">{{ __('Watched by') }}</th>
            <th scope="col" class="hide-tab">{{ __('Address') }}</th>
            <th scope="col">{{ __('When') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="eye" :title="__('No views in the history')">{{ __('The view counts of your videos still go up. The history lists who watched what.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($views as $view)
            @php $when = \Illuminate\Support\Carbon::parse($view->view_at); @endphp
            <tr>
                <td class="c-main"><p><b id="ad-w-{{ $view->id }}">{{ \Illuminate\Support\Str::limit((string) ($view->video_title ?? __('Deleted video')), 90) }}</b></p></td>
                <td class="c-meta">{{ $view->username ?? __('Visitor') }}</td>
                <td class="hide-tab"><code>{{ $view->ip ?? '-' }}</code></td>
                <td class="is-date" title="{{ $when->translatedFormat('j M Y, H:i') }}">{{ $when->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-w-{{ $view->id }}" data-id="{{ $view->id }}" @if ($view->video_title !== null) data-video="{{ $view->video_id }}" @endif @if ($view->username !== null) data-user="{{ $view->user_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
