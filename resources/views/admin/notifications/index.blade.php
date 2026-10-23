@extends('layouts.admin')
@section('title', __('Notifications'))
@section('content')
<div x-data="{selected:[]}" class="position-relative">
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-bell me-2"></i>{{__('Notifications')}}</h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        {{-- Bulk bar --}}
        <template x-if="selected.length > 0">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __("selected") }}'"></span>
                <form method="POST" action="{{ route('admin.bulk.notifications') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Delete selected notifications?') }}')"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Selected')}}</button>
                </form>
                <form method="POST" action="{{ route('admin.bulk.notifications') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="mark_read">
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-check me-1"></i>{{__('Mark Read')}}</button>
                </form>
            </div>
        </template>
        <form method="POST" action="{{ route('admin.notifications.clear') }}" onsubmit='return confirm(@json(__('Clear ALL notifications?')))'>
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All')}}</button>
        </form>
    </div>
</div>
<hr>

{{-- Broadcast Form --}}
<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fa-solid fa-broadcast-tower me-2"></i>{{__('Broadcast Notification to Users')}}</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.notifications.broadcast') }}">
            @csrf
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold" for="ad-notifications-title">{{__('Title')}}</label>
                    <input id="ad-notifications-title" type="text" name="title" class="form-control" required placeholder="{{__('Notification title')}}">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold" for="ad-notifications-target">{{__('Target Users')}}</label>
                    <select id="ad-notifications-target" name="target" class="form-select" required>
                        <option value="all">{{__('All Users')}}</option>
                        <option value="premium">{{__('Premium Users Only')}}</option>
                        <option value="unverified">{{__('Unverified Users Only')}}</option>
                    </select>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label fw-bold" for="ad-notifications-message">{{__('Message')}}</label>
                    <textarea id="ad-notifications-message" name="message" class="form-control" rows="3" required placeholder="{{__('Notification message...')}}"></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>{{__('Send Broadcast')}}</button>
        </form>
    </div>
</div>

{{-- Stats --}}
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-primary"><div class="card-body text-center py-3">
            <h3>{{ number_format($stats['total']) }}</h3>
            <small class="text-muted">{{__('Total')}}</small>
        </div></div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-warning"><div class="card-body text-center py-3">
            <h3>{{ number_format($stats['unread']) }}</h3>
            <small class="text-muted">{{__('Unread')}}</small>
        </div></div>
    </div>
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead>
        <tr style="border-top: 3px solid #0D6EFD;">
            <th scope="col">
                <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                    @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                >
            </th>
            <th>{{__('Type')}}</th>
            <th style="min-width:300px">{{__('Data')}}</th>
            <th>{{__('User ID')}}</th>
            <th>{{__('Read At')}}</th>
            <th style="min-width:150px">{{__('Date')}}</th>
            <th>{{__('Actions')}}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($notifications as $notif)
            <tr class="bg-light {{ $notif->read_at ? '' : 'table-warning' }}">
                <td class="align-middle">
                    <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $notif->id }}" x-model="selected">
                </td>
                <td class="align-middle"><span class="badge bg-info">{{ class_basename($notif->type) }}</span></td>
                <td class="align-middle"><small>{{ Str::limit($notif->data, 120) }}</small></td>
                <td class="align-middle">#{{ $notif->notifiable_id }}</td>
                <td class="align-middle">
                    @if($notif->read_at)
                        <span class="badge bg-success">{{__('Read')}}</span>
                    @else
                        <span class="badge bg-warning">{{__('Unread')}}</span>
                    @endif
                </td>
                <td class="align-middle"><small>{{ \Carbon\Carbon::parse($notif->created_at)->diffForHumans() }}</small></td>
                <td class="align-middle">
                    <form method="POST" action="{{ route('admin.notifications.delete', $notif->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger" aria-label="{{ __('Delete') }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4">
                <i class="fa-solid fa-bell-slash fa-2x mb-3 text-muted"></i>
                <p class="fw-bold text-muted">{{__('No notifications found')}}</p>
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$notifications" />
</div>
@endsection
