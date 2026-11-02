{{-- The meta line of a report row (member design 6.8): status, reason, date. Variable: $report. --}}
@php
    [$mkKind, $mkLabel] = match ($report->status) {
        \App\Enums\ReportStatus::BLOCKED => ['private', __('Reviewed: removed')],
        \App\Enums\ReportStatus::CANCELLED => ['private', __('Reviewed: no action')],
        default => ['scheduled', __('Pending')],
    };
@endphp
<div class="mk-row__meta">
    <span><x-mk.status :kind="$mkKind" :label="$mkLabel" /></span>
    @if($report->reason)<span>{{ $report->reason->name() }}</span>@endif
    <span>{{ \App\Support\Studio\StudioFormat::date($report->created_at) }}</span>
</div>
