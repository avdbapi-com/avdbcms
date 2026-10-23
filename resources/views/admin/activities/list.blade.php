{{--
    Members > Activity in the redesigned admin (the old admin keeps activities/index.blade.php): what members did
    (comments, likes) with one kind at a time, bulk delete, a row menu filled from one template (modules/list-rows.ts:
    delete with Undo), clearing the whole log, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Activity'))

@section('content')
    <x-admin.list-page>
    @php
        $total = array_sum(array_column($kinds, 'total'));
        $today = array_sum(array_column($kinds, 'today'));
        $what = fn (string $kind): string => match ($kind) {
            'comment' => __('Commented'),
            'interaction' => __('Liked or disliked'),
            'video' => __('Added a video'),
            default => \Illuminate\Support\Str::headline($kind),
        };
    @endphp

    <x-admin.page-header page="members.activity"
        :description="trans_choice('1 thing members did is in the log.|:count things members did are in the log.', $total, ['count' => number_format($total)]).($today > 0 ? ' '.trans_choice('1 today.|:count today.', $today, ['count' => number_format($today)]) : '')">
        <x-slot:actions>
            @if ($total > 0)
                <form method="POST" action="{{ route('admin.activities.clear') }}"
                      data-ad-confirm="{{ __('Clear the whole activity log?') }}" data-ad-confirm-body="{{ __('Only the log is cleared: the comments and likes stay. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Clear the log') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Clear the log...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-activities-bulk" method="POST" action="{{ route('admin.bulk.activities') }}" hidden>@csrf</form>
    <form id="ad-activity-delete" method="POST" action="{{ route('admin.activities.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Removed from the log') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-activity-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <button type="submit" form="ad-activity-delete" formaction="{{ route('admin.activities.delete', ['activity' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Remove from the log') }}</button>
    </template>

    <x-admin.table :paginator="$activities" :label="__('Activity')" list="activities" table-class="is-list" row-menu="ad-activity-menu">
        @if (count($kinds) > 1 || $type !== null)
            <x-slot:toolbar>
                <form method="GET" action="{{ route('admin.activities.index') }}" class="ad-filter" aria-label="{{ __('Filter the activity') }}">
                    <select class="ad-select" name="type" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                        <option value="">{{ __('Everything') }}</option>
                        @foreach ($kinds as $kind => $row)
                            <option value="{{ $kind }}" @selected($type === $kind)>{{ $what($kind) }} ({{ number_format($row['total']) }})</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
                </form>
            </x-slot:toolbar>
        @endif

        <x-slot:bulk>
            <button type="submit" class="ad-btn is-danger sm" form="ad-activities-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Remove the selected entries from the log?') }}" data-ad-confirm-body="{{ __('Only the log entries go: the comments and likes stay. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Remove') }}">{{ AdminTheme::icon('trash') }}{{ __('Remove...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all entries on this page') }}"></th>
            <th scope="col">{{ __('Member') }}</th>
            <th scope="col">{{ __('What') }}</th>
            <th scope="col">{{ __('When') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="activity" :title="__('Nothing in the log')">{{ __('Comments and likes of your members are listed here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($activities as $activity)
            @php $when = \Illuminate\Support\Carbon::parse($activity->perform_at); @endphp
            <tr>
                <td class="is-check"><input type="checkbox" name="ids[]" value="{{ $activity->id }}" form="ad-activities-bulk" aria-labelledby="ad-l-sel ad-a-{{ $activity->id }}"></td>
                <td class="c-main"><p><b id="ad-a-{{ $activity->id }}">{{ $activity->username }}</b><small>{{ $activity->email }}</small></p></td>
                <td class="c-meta">{{ $what(strtolower(class_basename((string) $activity->subject_type))) }}<small>#{{ $activity->subject_id }}</small></td>
                <td class="is-date" title="{{ $when->translatedFormat('j M Y, H:i') }}">{{ $when->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-a-{{ $activity->id }}" data-id="{{ $activity->id }}" data-user="{{ $activity->user_id }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
