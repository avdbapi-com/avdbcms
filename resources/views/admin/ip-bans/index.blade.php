@extends('layouts.admin')
@section('title', __('IP Bans'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-shield-halved me-2"></i>{{__('IP Ban Management')}}</h2>
    <form method="POST" action="{{ route('admin.ip-bans.clear-expired') }}">
        @csrf
        <button class="btn btn-sm btn-warning"><i class="fa-solid fa-broom me-1"></i>{{__('Clear Expired')}}</button>
    </form>
</div>
<hr>

<div class="row mb-4">
    @foreach([
        [__('Total Bans'), $stats['total'], 'fa-ban', 'danger'],
        [__('Active'), $stats['active'], 'fa-shield', 'success'],
        [__('Permanent'), $stats['permanent'], 'fa-infinity', 'warning'],
        [__('Temporary'), $stats['temporary'], 'fa-clock', 'info'],
    ] as $s)
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>
            <div class="fw-bold fs-5">{{ $s[1] }}</div>
            <small class="text-muted">{{ __($s[0]) }}</small>
        </div></div>
    </div>
    @endforeach
</div>

{{-- Add New Ban --}}
<div class="card shadow mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-plus me-1"></i>{{__('Ban IP Address')}}</h6></div>
    <div class="card-body">
        <form action="{{ route('admin.ip-bans.store') }}" method="POST" class="row g-3">
            @csrf
            <div class="col-md-3"><input type="text" name="ip_address" aria-label="{{ __('IP address') }}" class="form-control @error('ip_address') is-invalid @enderror" placeholder="192.168.1.1" required>@error('ip_address')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-md-3"><input type="text" name="reason" class="form-control" aria-label="{{ __('Reason (optional)') }}" placeholder="{{__('Reason (optional)')}}"></div>
            <div class="col-md-3">
                <select name="duration" class="form-select" aria-label="{{ __('Duration') }}">
                    <option value="">{{__('Permanent')}}</option>
                    <option value="1">{{__('1 Hour')}}</option>
                    <option value="6">{{__('6 Hours')}}</option>
                    <option value="24">{{__('24 Hours')}}</option>
                    <option value="168">{{__('1 Week')}}</option>
                    <option value="720">{{__('30 Days')}}</option>
                    <option value="8760">{{__('1 Year')}}</option>
                </select>
            </div>
            <div class="col-md-3"><button type="submit" class="btn btn-danger w-100"><i class="fa-solid fa-ban me-1"></i>{{__('Ban IP')}}</button></div>
        </form>
    </div>
</div>

{{-- Ban List --}}
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #dc3545"><th>{{__('IP Address')}}</th><th>{{__('Reason')}}</th><th>{{__('Type')}}</th><th>{{__("Expires")}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th></tr></thead>
        <tbody>
        @forelse($bans as $ban)
        <tr>
            <td class="align-middle"><code class="fw-bold">{{ $ban->ip_address }}</code></td>
            <td class="align-middle"><small>{{ $ban->reason ?: '-' }}</small></td>
            <td class="align-middle">
                @if($ban->expires_at) <span class="badge bg-info">{{__('Temporary')}}</span>
                @else <span class="badge bg-danger">{{__('Permanent')}}</span>
                @endif
            </td>
            <td class="align-middle">
                @if($ban->expires_at)
                    <small>{{ \Carbon\Carbon::parse($ban->expires_at)->translatedFormat('M d, Y H:i') }}</small>
                    @if(\Carbon\Carbon::parse($ban->expires_at)->isPast())
                        <span class="badge bg-secondary">{{__('Expired')}}</span>
                    @endif
                @else <small class="text-muted">{{__('Never')}}</small>
                @endif
            </td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($ban->created_at)->diffForHumans() }}</small></td>
            <td class="align-middle">
                <form method="POST" action="{{ route('admin.ip-bans.delete', $ban->id) }}" onsubmit='return confirm(@json(__('Remove this IP ban?')))'>
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-success py-0"><i class="fa-solid fa-unlock"></i>{{__('Unban')}}</button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" class="text-center py-4 text-muted"><i class="fa-solid fa-check-circle fa-2x mb-2"></i><p>{{__('No IP bans')}}</p></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$bans" />
@endsection
