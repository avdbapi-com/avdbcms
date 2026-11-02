{{--
    Tools > Logs > Admin actions, in the redesigned admin (the old admin keeps audit/index.blade.php): what each
    administrator did and from which address, the latest first, clearing the log, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Logs'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="tools.logs"
        :description="trans_choice('1 admin action in the log.|:count admin actions in the log.', $stats['total'], ['count' => number_format($stats['total'])]).($stats['today'] > 0 ? ' '.trans_choice('1 today.|:count today.', $stats['today'], ['count' => number_format($stats['today'])]) : '')">
        <x-slot:actions>
            @if ($stats['total'] > 0)
                <form method="POST" action="{{ route('admin.audit.clear') }}"
                      data-ad-confirm="{{ __('Clear the admin action log?') }}" data-ad-confirm-body="{{ __('Every entry is deleted. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Clear the log') }}">
                    @csrf
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Clear the log...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.table :paginator="$logs" :label="__('Admin actions')" list="audit" table-class="is-list">
        <x-slot:head>
            <th scope="col">{{ __('Action') }}</th>
            <th scope="col">{{ __('By') }}</th>
            <th scope="col" class="hide-tab">{{ __('Address') }}</th>
            <th scope="col">{{ __('When') }}</th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="list" :title="__('No admin actions logged')">{{ __('Changes administrators make are written here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($logs as $log)
            @php
                $when = \Illuminate\Support\Carbon::parse($log->created_at);
                // stored as written (in English); shown in the admin's language where its wording is known (R2A-054)
                $details = \App\Support\AuditDetails::text((string) $log->action, $log->details);
            @endphp
            <tr>
                {{-- The action in plain words (its key in the tooltip); the details in full, wrapped, a very long one
                     folded after its first lines (1.2.2 cut every detail at about 70 characters). --}}
                <td class="c-main ad-audit-main">
                    <p><b title="{{ $log->action }}" data-audit-action="{{ $log->action }}">{{ \App\Support\AuditActions::label((string) $log->action) }}</b></p>
                    @if ($details !== '')
                        @if (mb_strlen($details) <= 280)
                            <div class="ad-audit-detail">{{ \App\Support\AuditDetails::html((string) $log->action, $log->details) }}</div>
                        @else
                            <details class="ad-audit-detail">
                                <summary><span>{{ \Illuminate\Support\Str::limit($details, 200) }}</span> <span class="ad-link" data-more>{{ __('Show all') }}</span><span class="ad-link" data-less>{{ __('Show less') }}</span></summary>
                                <div>{{ \App\Support\AuditDetails::html((string) $log->action, $log->details) }}</div>
                            </details>
                        @endif
                    @endif
                </td>
                <td class="c-meta">{{ $log->username ?? __('System') }}</td>
                <td class="hide-tab"><code>{{ $log->ip_address ?? '-' }}</code></td>
                <td class="is-date" title="{{ $when->translatedFormat('j M Y, H:i:s') }}">{{ $when->diffForHumans(short: \App\Support\AdminText::shortTimes()) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
    <style>
        .ad-audit-detail{margin-top:2px;color:var(--text-2);font-size:var(--fs-sm);white-space:normal;overflow-wrap:anywhere;max-width:72ch}
        details.ad-audit-detail > summary{cursor:pointer;list-style:none}
        details.ad-audit-detail > summary::-webkit-details-marker{display:none}
        details.ad-audit-detail[open] > summary > span:first-child,details.ad-audit-detail[open] [data-more],details.ad-audit-detail:not([open]) [data-less]{display:none}
    </style>
@endsection
