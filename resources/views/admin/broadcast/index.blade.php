@extends('layouts.admin')
@section('title', __('Mass Broadcast'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-bullhorn me-2 text-primary"></i>{{__('Mass Broadcast Notification')}}</{{ $titleTag }}>
</div>

<div class="row mb-4">
    <div class="col-md-3"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['total_users'] }}</div><small class="text-muted">{{__('Total Users')}}</small></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><div class="h4 mb-0 text-success">{{ $stats['verified_users'] }}</div><small class="text-muted">{{__('Verified Users')}}</small></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><div class="h4 mb-0 text-warning">{{ $stats['premium_users'] }}</div><small class="text-muted">{{__('Premium Users')}}</small></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><div class="h4 mb-0 text-info">{{ count($stats['recent_notifications']) }}</div><small class="text-muted">{{__('Recent Notifications')}}</small></div></div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="card shadow">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-paper-plane me-1"></i>{{__('Send Notification')}}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.broadcast.send') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="broadcastTarget">{{__('Target Audience')}}</label>
                        <select name="target" class="form-select" id="broadcastTarget" onchange="document.getElementById('specificUsers').style.display = this.value === 'specific' ? 'block' : 'none'">
                            <option value="all">{{ __('All Users') }} ({{ $stats['total_users'] }})</option>
                            <option value="verified">{{ __('Verified Users Only') }} ({{ $stats['verified_users'] }})</option>
                            <option value="premium">{{ __('Premium Users Only') }} ({{ $stats['premium_users'] }})</option>
                            <option value="specific">{{__('Specific User IDs')}}</option>
                        </select>
                    </div>
                    <div class="mb-3" id="specificUsers" style="display:none">
                        <label class="form-label fw-bold" for="ad-broadcast-user-ids">{{ __('User IDs (comma-separated)') }}</label>
                        <input id="ad-broadcast-user-ids" type="text" name="user_ids" class="form-control" placeholder="1, 2, 5, 12">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="ad-broadcast-title">{{__('Notification Title')}}</label>
                        <input id="ad-broadcast-title" type="text" name="title" class="form-control" placeholder="{{__('Important Update')}}" required maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="ad-broadcast-message">{{__('Message')}}</label>
                        <textarea id="ad-broadcast-message" name="message" class="form-control" rows="5" placeholder="{{__('Your notification message...')}}" required maxlength="5000"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" onclick='return confirm(@json(__('Send this notification to the selected audience?')))'>
                        <i class="fa-solid fa-paper-plane me-1"></i>{{__('Send Broadcast')}}</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card shadow">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-clock-rotate-left me-1"></i>{{__('Recent Notifications')}}</h5></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @forelse($stats['recent_notifications'] as $n)
                    @php $data = json_decode($n->data, true); @endphp
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <strong>{{ Str::limit($data['title'] ?? $n->type, 40) }}</strong>
                            <small class="text-muted">{{ \Carbon\Carbon::parse($n->created_at)->diffForHumans() }}</small>
                        </div>
                        <small class="text-muted">{{ Str::limit($data['message'] ?? '', 80) }}</small>
                    </div>
                    @empty
                    <div class="list-group-item text-center text-muted py-4">{{__('No recent notifications')}}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
