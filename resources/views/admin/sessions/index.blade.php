@extends('layouts.admin')
@section('title', __('Active Sessions'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-desktop me-2"></i>{{__('Active Sessions')}}</h2>
    @if($driver === 'database')
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('admin.sessions.clear-guests') }}">@csrf<button class="btn btn-sm btn-warning"><i class="fa-solid fa-ghost me-1"></i>{{__('Clear Guests')}}</button></form>
        <form method="POST" action="{{ route('admin.sessions.clear-all') }}" onsubmit='return confirm(@json(__('Terminate ALL other sessions?')))'>@csrf<button class="btn btn-sm btn-danger"><i class="fa-solid fa-power-off me-1"></i>{{__('Clear All Others')}}</button></form>
    </div>
    @endif
</div>
<hr>

<div class="row mb-4">
    @foreach([
        ['Session Driver', $stats['driver'], 'fa-hard-drive', 'primary'],
        ['Lifetime', $stats['lifetime'], 'fa-clock', 'info'],
        ['Total Sessions', $stats['total'], 'fa-users', 'success'],
        ['Authenticated', $stats['authenticated'], 'fa-user-check', 'warning'],
        ['Guest Sessions', $stats['guests'], 'fa-ghost', 'secondary'],
        ['Expire on Close', $stats['expire_on_close'], 'fa-door-closed', 'danger'],
    ] as $s)
    <div class="col-md-2 col-4 mb-3">
        <div class="card shadow-sm border-{{ $s[3] }} h-100"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>
            <div class="fw-bold">{{ $s[1] }}</div>
            <small class="text-muted" style="font-size:11px">{{ __($s[0]) }}</small>
        </div></div>
    </div>
    @endforeach
</div>

@if($driver === 'database')
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #0d6efd"><th>{{__('User')}}</th><th>{{__('IP Address')}}</th><th>{{__('User Agent')}}</th><th>{{__("Last Activity")}}</th><th>{{__('Actions')}}</th></tr></thead>
        <tbody>
        @forelse($sessions as $session)
        <tr class="{{ $session->id === session()->getId() ? 'table-success' : '' }}">
            <td class="align-middle">
                @if($session->username)
                    <span class="fw-bold">{{ $session->username }}</span><br><small class="text-muted">{{ $session->user_email }}</small>
                @else
                    <span class="text-muted">{{__('Guest')}}</span>
                @endif
            </td>
            <td class="align-middle"><code>{{ $session->ip_address }}</code></td>
            <td class="align-middle"><small class="text-muted">{{ Str::limit($session->user_agent, 60) }}</small></td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}</small></td>
            <td class="align-middle">
                @if($session->id === session()->getId())
                    <span class="badge bg-success">{{__('Current')}}</span>
                @else
                    <form method="POST" action="{{ route('admin.sessions.destroy', $session->id) }}" onsubmit='return confirm(@json(__('Terminate this session?')))'>
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger py-0"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center py-4 text-muted">{{__('No active sessions')}}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$sessions" />
@else
<div class="alert alert-info">
    <i class="fa-solid fa-info-circle me-1"></i>
    {{__('Session driver is')}} <strong>{{ $driver }}</strong>. {{__('To view individual sessions, switch to the database session driver in Settings -> Advanced.')}}
</div>
@endif
@endsection
