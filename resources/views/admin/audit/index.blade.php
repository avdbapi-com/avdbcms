@extends('layouts.admin')
@section('title', __('Admin Audit Log'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-clipboard-list me-2"></i>{{__('Admin Audit Log')}}</h2>
    <form method="POST" action="{{ route('admin.audit.clear') }}" onsubmit='return confirm(@json(__('Clear entire audit log?')))'>
        @csrf
        <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All')}}</button>
    </form>
</div>
<hr>

<div class="row mb-4">
    @foreach([
        [__('Total Entries'), $stats['total'], 'fa-list', 'primary'],
        [__('Today'), $stats['today'], 'fa-clock', 'success'],
        [__('Admin Users'), $stats['admins'], 'fa-user-shield', 'info'],
    ] as $s)
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>
            <div class="fw-bold fs-5">{{ number_format($s[1]) }}</div>
            <small class="text-muted">{{ $s[0] }}</small>
        </div></div>
    </div>
    @endforeach
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #6c757d"><th>{{__("Admin")}}</th><th>{{__('Action')}}</th><th style="min-width:200px">{{__('Details')}}</th><th>IP</th><th style="min-width:150px">{{__('Date')}}</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
        <tr>
            <td class="align-middle"><span class="fw-bold">{{ $log->username ?? 'System' }}</span></td>
            <td class="align-middle"><span class="badge bg-primary">{{ $log->action }}</span></td>
            <td class="align-middle"><small>{{ Str::limit($log->details, 120) }}</small></td>
            <td class="align-middle"><code class="small">{{ $log->ip_address ?? '—' }}</code></td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($log->created_at)->translatedFormat('M d, Y H:i:s') }}</small></td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-check-circle fa-2x mb-2"></i><p>{{__('No audit log entries')}}</p></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$logs" />
@endsection
