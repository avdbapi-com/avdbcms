@extends('layouts.admin')
@section('title', __('Devices & Sessions'))
@section('content')
<div x-data="{selected:[]}">
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-mobile-screen me-2"></i>{{__('Devices & API Sessions')}}</h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <template x-if="selected.length > 0">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __('selected') }}'"></span>
                <form method="POST" action="{{ route('admin.bulk.devices') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Revoke selected sessions?') }}')"><i class="fa-solid fa-ban me-1"></i>{{__('Revoke Selected')}}</button>
                </form>
            </div>
        </template>
        <form method="POST" action="{{ route('admin.devices.clear-expired') }}" onsubmit='return confirm(@json(__('Clear all expired sessions?')))'>
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-warning btn-sm"><i class="fa-solid fa-clock me-1"></i>{{__('Clear Expired')}}</button>
        </form>
    </div>
</div>
<hr>
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-primary"><div class="card-body text-center py-3">
            <i class="fa-solid fa-mobile-screen fa-2x text-primary mb-2"></i>
            <h3>{{ number_format($stats['total']) }}</h3>
            <small class="text-muted">{{__('Total Sessions')}}</small>
        </div></div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-success"><div class="card-body text-center py-3">
            <i class="fa-solid fa-circle-check fa-2x text-success mb-2"></i>
            <h3>{{ number_format($stats['active']) }}</h3>
            <small class="text-muted">{{__('Active')}}</small>
        </div></div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-danger"><div class="card-body text-center py-3">
            <i class="fa-solid fa-circle-xmark fa-2x text-danger mb-2"></i>
            <h3>{{ number_format($stats['expired']) }}</h3>
            <small class="text-muted">{{__('Expired')}}</small>
        </div></div>
    </div>
</div>
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead><tr style="border-top:3px solid #0D6EFD">
            <th scope="col">
                <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                    @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                >
            </th>
            <th style="min-width:200px">{{__('User')}}</th>
            <th>{{__('Token Name')}}</th>
            <th>{{__('Abilities')}}</th>
            <th style="min-width:150px">{{__('Last Used')}}</th>
            <th style="min-width:150px">{{__('Expires')}}</th>
            <th>{{__('Status')}}</th>
            <th>{{__('Actions')}}</th>
        </tr></thead>
        <tbody>
        @forelse($devices as $device)
        <tr class="bg-light">
            <td class="align-middle">
                <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $device->id }}" x-model="selected">
            </td>
            <td class="align-middle">
                @if($device->user)
                    <a href="{{ route('admin.users.edit', $device->user) }}" class="d-flex align-items-center gap-2 text-decoration-none">
                        <img src="{{ $device->user->avatar_url }}" class="rounded" style="width:32px;height:32px;object-fit:cover">
                        <div>
                            <div class="fw-bold">{{ $device->user->username }}</div>
                            <small class="text-muted">{{ $device->user->email }}</small>
                        </div>
                    </a>
                @else
                    <span class="text-muted">{{__('Unknown User')}}</span>
                @endif
            </td>
            <td class="align-middle"><span class="badge bg-info">{{ $device->name }}</span></td>
            <td class="align-middle"><small class="text-muted">{{ $device->abilities ?? '*' }}</small></td>
            <td class="align-middle"><small>{{ $device->last_used_at?->diffForHumans() ?? __('Never') }}</small></td>
            <td class="align-middle"><small>{{ $device->expires_at?->translatedFormat('d M Y H:i') ?? __('Never') }}</small></td>
            <td class="align-middle">
                @if($device->expires_at && $device->expires_at->isPast())
                    <span class="badge bg-danger">{{__('Expired')}}</span>
                @else
                    <span class="badge bg-success">{{__('Active')}}</span>
                @endif
            </td>
            <td class="align-middle">
                <form method="POST" action="{{ route('admin.devices.delete', $device) }}" onsubmit='return confirm(@json(__('Revoke this session?')))'>
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger" title="{{__('Revoke')}}"><i class="fa-solid fa-ban"></i></button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" class="text-center py-4">
            <i class="fa-solid fa-mobile-screen fa-2x mb-3 text-muted"></i>
            <p class="fw-bold text-muted">{{__('No active sessions')}}</p>
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$devices" />
</div>
@endsection
