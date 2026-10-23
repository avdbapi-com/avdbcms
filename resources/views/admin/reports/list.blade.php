{{--
    Community > Reports in the redesigned admin (the old admin keeps reports/index.blade.php): Waiting / Blocked /
    Dismissed tabs, what was reported (video, comment or member) with its owner, the reason and who reported it, and
    a row menu (Block, Dismiss) filled from one template (modules/list-rows.ts). Block is offered for a reported comment
    only (data-block on its row): a member is blocked on their own page, a video banned on its page.
--}}
@use('App\Enums\ReportStatus')
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Reports'))

@section('content')
    @php
        // $status: the controller's tab ('0' Waiting - the default -, '1' Blocked, '2' Dismissed, 'all').
        $waiting = (string) ReportStatus::PENDING->value;
        $tab = fn (string $value, string $label, ?int $count) => [
            'label' => $label,
            'url' => route('admin.reports.index', array_filter(['status' => $value === $waiting ? null : $value, 'type' => request('type')], fn ($v) => $v !== null && $v !== '')),
            'active' => $status === $value,
            'count' => $count !== null ? number_format($count) : null,
        ];
        $tabs = [
            $tab($waiting, __('Waiting'), $counts[ReportStatus::PENDING->value] ?? 0),
            $tab((string) ReportStatus::BLOCKED->value, __('Blocked'), $counts[ReportStatus::BLOCKED->value] ?? 0),
            $tab((string) ReportStatus::CANCELLED->value, __('Dismissed'), $counts[ReportStatus::CANCELLED->value] ?? 0),
            $tab('all', __('All'), array_sum($counts)),
        ];
        $types = ['video' => __('Videos'), 'comment' => __('Comments'), 'user' => __('Members')];
    @endphp

    <x-admin.page-header page="community.reports" :tabs="$tabs" />

    <form id="ad-report-block" method="POST" action="{{ route('admin.reports.index') }}" data-ad-row-action="change" data-ad-done="{{ __('Blocked') }}"
          data-ad-confirm="{{ __('Block this content?') }}" data-ad-confirm-body="{{ __('The comment is hidden from visitors. Every report about it is closed.') }}" data-ad-confirm-label="{{ __('Block') }}" hidden></form>
    <form id="ad-report-dismiss" method="POST" action="{{ route('admin.reports.index') }}" data-ad-row-action="change" data-ad-done="{{ __('Report dismissed') }}" hidden></form>
    <span hidden id="ad-l-actions">{{ __('Actions for the report about') }}</span>
    <template id="ad-report-menu">
        <a href="__URL__" target="_blank" rel="noopener" data-ad-if="url">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
        <a href="__EDIT__" data-ad-if="edit">{{ AdminTheme::icon('pencil') }}{{ __('Open in the admin') }}</a>
        <hr>
        <button type="submit" form="ad-report-dismiss" formaction="{{ route('admin.reports.cancel', ['type' => '__TYPE__', 'reportable' => '__REPORTABLE__'], false) }}" data-ad-when="!cancelled" data-ad-expect="cancelled">{{ AdminTheme::icon('check') }}{{ __('Dismiss: nothing wrong') }}</button>
        <button type="submit" form="ad-report-block" formaction="{{ route('admin.reports.block', ['type' => '__TYPE__', 'reportable' => '__REPORTABLE__'], false) }}" class="is-danger" data-ad-when="!blocked" data-ad-if="block" data-ad-expect="blocked">{{ AdminTheme::icon('ban') }}{{ __('Block it') }}</button>
    </template>

    <x-admin.table :paginator="$reports" :label="__('Reports')" list="reports" table-class="is-list">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.reports.index') }}" class="ad-filter" role="search" aria-label="{{ __('Find reports') }}">
                @if ($status !== $waiting)
                    <input type="hidden" name="status" value="{{ $status }}">
                @endif
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Video title, member or note') }}" aria-label="{{ __('Search the reports') }}">
                </span>
                <select class="ad-select" name="type" aria-label="{{ __('Reported content') }}" data-ad-autosubmit>
                    <option value="">{{ __('All types') }}</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th scope="col">{{ __('Reported') }}</th>
            <th scope="col">{{ __('Status') }}</th>
            <th scope="col" class="hide-tab">{{ __('Reason') }}</th>
            <th scope="col" class="hide-tab">{{ __('Reported by') }}</th>
            <th scope="col">{{ __('When') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="flag" :title="$status === $waiting ? __('Nothing waiting') : __('No reports here')">{{ __('When visitors report a video, a comment or a member, it waits here for you.') }}</x-admin.empty>
        </x-slot:empty>

        @php ob_start(); @endphp
        @foreach ($reports as $report)
            @php
                $item = $report->reportable;
                $type = strtolower($report->type);
                [$tone, $label, $state] = match ($report->status) {
                    ReportStatus::BLOCKED => ['danger', __('Blocked'), 'blocked'],
                    ReportStatus::CANCELLED => ['off', __('Dismissed'), 'cancelled'],
                    default => ['warn', __('Waiting'), 'pending'],
                };
                [$title, $sub, $url, $edit] = match (true) {
                    $item === null => [__('Deleted'), $types[$type] ?? '', null, null],
                    $type === 'video' => [(string) $item->title, __('Video by :name', ['name' => $item->user?->username ?? __('Deleted member')]), null, route('admin.videos.edit', $item, false)],
                    $type === 'comment' => [\Illuminate\Support\Str::limit((string) $item->content, 120), __('Comment by :name on :video', ['name' => $item->user?->username ?? __('Deleted member'), 'video' => \Illuminate\Support\Str::limit((string) ($item->video?->title ?? __('a deleted video')), 50)]), null, route('admin.comments.index', ['video' => $item->video_id], false)],
                    default => [(string) $item->username, __('Member'), $item->route, route('admin.users.edit', $item, false)],
                };
            @endphp
            <tr>
                <td class="c-main">
                    <div class="ad-vid">
                        <span class="ad-sym is-accent" aria-hidden="true">{{ AdminTheme::icon(['video' => 'film', 'comment' => 'chat', 'user' => 'user'][$type] ?? 'flag') }}</span>
                        <span class="ad-vid-text">
                            <b class="ad-vid-title" id="ad-r-{{ $report->id }}">{{ $title }}</b>
                            <span class="ad-vid-sub">{{ $sub }}</span>
                        </span>
                    </div>
                </td>
                <td class="c-meta">
                    <x-admin.status :type="$tone">{{ $label }}</x-admin.status>
                    <span class="ad-phone-only ad-faint">{{ $report->reason?->name() }}</span>
                </td>
                <td class="hide-tab">{{ $report->reason?->name() }}@if ($report->comment)<span class="ad-vid-sub">{{ \Illuminate\Support\Str::limit((string) $report->comment, 80) }}</span>@endif</td>
                <td class="hide-tab">{{ $report->user?->username ?? __('A visitor') }}</td>
                <td class="ad-muted is-date"><time datetime="{{ $report->created_at?->toIso8601String() }}" title="{{ $report->created_at?->translatedFormat('j M Y, H:i') }}">{{ $report->created_at?->diffForHumans(short: true) }}</time></td>
                <td class="is-actions">@if ($item !== null)<button type="button" class="ad-iconbtn is-more" data-ad-row-menu="ad-report-menu" aria-haspopup="true" aria-labelledby="ad-l-actions ad-r-{{ $report->id }}" data-id="{{ $report->id }}" data-state="{{ $state }}" data-type="{{ $type }}" data-reportable="{{ $report->reportable_id }}"@if ($type === 'comment') data-block="1"@endif @if ($url) data-url="{{ $url }}"@endif @if ($edit) data-edit="{{ $edit }}"@endif></button>@endif</td>
            </tr>
        @endforeach
        {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
    </x-admin.table>
@endsection
