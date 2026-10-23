@extends('layouts.admin')
@section('title', __('User Verification'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-user-check me-2"></i>{{__('User Verification Manager')}}</{{ $titleTag }}>
    <form method="POST" action="{{ route('admin.verification.verify-all') }}" onsubmit='return confirm(@json(__('Verify ALL unverified users?')))'>@csrf
        <button class="btn btn-success btn-sm"><i class="fa-solid fa-check-double me-1"></i>{{__('Verify All Unverified')}} ({{ $stats['unverified'] }})</button>
    </form>
</div>

<div class="row mb-4">
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['total'] }}</div><small class="text-muted">{{__('Total Users')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-success">{{ $stats['verified'] }}</div><small class="text-muted">{{__('Verified')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-danger">{{ $stats['unverified'] }}</div><small class="text-muted">{{__('Unverified')}}</small></div></div>
</div>

<div class="card shadow">
    <div class="card-header d-flex justify-content-between flex-wrap gap-2">
        <div class="btn-group btn-group-sm">
            <a href="{{ route('admin.verification.index', ['filter' => 'all']) }}" class="btn btn-{{ $filter === 'all' ? 'primary' : 'outline-primary' }}">{{__('All')}}</a>
            <a href="{{ route('admin.verification.index', ['filter' => 'verified']) }}" class="btn btn-{{ $filter === 'verified' ? 'success' : 'outline-success' }}">{{__('Verified')}}</a>
            <a href="{{ route('admin.verification.index', ['filter' => 'unverified']) }}" class="btn btn-{{ $filter === 'unverified' ? 'danger' : 'outline-danger' }}">{{__('Unverified')}}</a>
        </div>
        <form method="GET" class="d-flex gap-1" style="width:250px">
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{__('Search users...')}}">
            <button class="btn btn-outline-primary btn-sm" aria-label="{{ __('Search') }}"><i class="fa-solid fa-search" aria-hidden="true"></i></button>
        </form>
    </div>
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>ID</th><th>{{__('Username')}}</th><th>{{__('Email')}}</th><th>{{__('Status')}}</th><th>{{__('Verified At')}}</th><th>{{__('Registered')}}</th><th>{{__('Actions')}}</th></tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                <tr>
                    <td>{{ $user->id }}</td>
                    <td><strong>{{ $user->username }}</strong> @if($user->is_admin)<span class="badge bg-danger">{{__('Admin')}}</span>@endif</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        @if($user->email_verified_at)
                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>{{__('Verified')}}</span>
                        @else
                            <span class="badge bg-danger"><i class="fa-solid fa-times me-1"></i>{{__('Unverified')}}</span>
                        @endif
                    </td>
                    <td>{{ $user->email_verified_at ? \Carbon\Carbon::parse($user->email_verified_at)->translatedFormat('M d, Y H:i') : '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($user->created_at)->translatedFormat('M d, Y') }}</td>
                    <td>
                        @if($user->email_verified_at)
                        <form method="POST" action="{{ route('admin.verification.unverify', $user->id) }}">@csrf
                            <button class="btn btn-outline-warning btn-sm" title="{{__('Unverify')}}"><i class="fa-solid fa-times"></i>{{__('Unverify')}}</button>
                        </form>
                        @else
                        <form method="POST" action="{{ route('admin.verification.verify', $user->id) }}">@csrf
                            <button class="btn btn-outline-success btn-sm" title="{{__('Verify')}}"><i class="fa-solid fa-check"></i>{{__('Verify')}}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">{{__('No users found.')}}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$users" class="mt-0" /></div>
</div>
@endsection

