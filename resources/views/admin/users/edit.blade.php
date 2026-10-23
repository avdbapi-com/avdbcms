@extends('layouts.admin')

@section('title', __('Edit User:') . ' ' . $user->username)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-user-edit me-2"></i>{{__('Edit User:')}} {{ $user->username }}</h3>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.users.show', $user) }}" class="btn btn-info btn-sm"><i class="fa-solid fa-eye me-1"></i>{{__('View Profile')}}</a>
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back to Users')}}</a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fa-solid fa-info-circle me-1"></i>{{__('User Information')}}</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.users.update', $user) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-users-username">{{__('Username')}}</label>
                                <input id="ad-users-username" type="text" name="username" class="form-control @error('username') is-invalid @enderror" value="{{ old('username', $user->username) }}">
                                @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-users-email">{{__('Email')}}</label>
                                <input id="ad-users-email" type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">{{__('New Password')}}<small class="text-muted">{{__('(leave blank to keep current)')}}</small></label>
                                <input type="password" name="password" class="form-control" autocomplete="new-password">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-users-country">{{__('Country')}}</label>
                                <input id="ad-users-country" type="text" name="country" class="form-control" value="{{ old('country', $user->country) }}" maxlength="2" placeholder="US">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold" for="ad-users-description">{{__('Description')}}</label>
                                <textarea id="ad-users-description" name="description" class="form-control" rows="3">{{ old('description', $user->description) }}</textarea>
                            </div>
                            <div class="col-12 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_admin" value="1" {{ $user->is_admin ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold text-danger">{{__('Administrator')}}</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Update User')}}</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow mb-3">
                <div class="card-body text-center">
                    <img src="{{ $user->avatar_url }}" alt="{{ $user->username }}" class="rounded-circle mb-3" style="width: 100px; height: 100px; object-fit: cover;">
                    <h5>{{ $user->username }}</h5>
                    <p class="text-muted text-sm">{{ $user->email }}</p>
                    @if($user->is_admin)
                        <span class="badge bg-danger">{{__('Admin')}}</span>
                    @endif
                    @if($user->is_premium)
                        <span class="badge bg-warning">{{__('Premium')}}</span>
                    @endif
                    @if($user->banned_at)
                        <span class="badge bg-dark">{{__('Banned')}}</span>
                    @endif
                    @if($user->email_verified_at)
                        <span class="badge bg-success">{{__('Verified')}}</span>
                    @else
                        <span class="badge bg-secondary">{{__('Unverified')}}</span>
                    @endif
                </div>
            </div>

            <div class="card shadow mb-3">
                <div class="card-header"><h6 class="mb-0">{{__('Quick Actions')}}</h6></div>
                <div class="card-body d-flex flex-column gap-2">
                    {{-- Impersonate (disabled — requires package) --}}
                    <button type="button" class="btn btn-primary btn-sm w-100" disabled title="{{__('Requires impersonation package to be installed')}}">
                        <i class="fa-solid fa-user-secret me-1"></i>{{__('Login As This User')}}
                    </button>
                    @if($user->banned_at)
                        <form action="{{ route('admin.users.unban', $user) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-unlock me-1"></i>{{__('Unban User')}}</button>
                        </form>
                    @else
                        <form action="{{ route('admin.users.ban', $user) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-warning btn-sm w-100"><i class="fa-solid fa-ban me-1"></i>{{__('Ban User')}}</button>
                        </form>
                    @endif
                    @if(!$user->email_verified_at)
                        <form action="{{ route('admin.users.confirm', $user) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-info btn-sm w-100"><i class="fa-solid fa-check me-1"></i>{{__('Force Verify Email')}}</button>
                        </form>
                    @else
                        <form action="{{ route('admin.users.unverify', $user) }}" method="POST" onsubmit='return confirm(@json(__('Un-verify this user email?')))'>
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-sm w-100"><i class="fa-solid fa-times me-1"></i>{{__('Revoke Email Verification')}}</button>
                        </form>
                    @endif
                    <form action="{{ route('admin.users.toggle-admin', $user) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-{{ $user->is_admin ? 'secondary' : 'danger' }} btn-sm w-100">
                            <i class="fa-solid fa-{{ $user->is_admin ? 'user-minus' : 'user-shield' }} me-1"></i>
                            {{ $user->is_admin ? __('Remove Admin') : __('Make Admin') }}
                        </button>
                    </form>
                    <a href="{{ route('admin.views.index') }}?user={{ $user->id }}" class="btn btn-outline-info btn-sm w-100">
                        <i class="fa-solid fa-eye me-1"></i>{{__('View Watch History')}}
                    </a>
                    <a href="{{ route('admin.interactions.index') }}?user={{ $user->id }}" class="btn btn-outline-info btn-sm w-100">
                        <i class="fa-solid fa-thumbs-up me-1"></i>{{__('View Interactions')}}
                    </a>
                    <a href="{{ route('admin.devices.index') }}?user={{ $user->id }}" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fa-solid fa-mobile-screen me-1"></i>{{__('View Sessions')}}
                    </a>
                    <form action="{{ route('admin.users.delete', $user) }}" method="POST" onsubmit='return confirm(@json(__('Are you sure you want to delete this user?')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fa-solid fa-trash me-1"></i>{{__('Delete User')}}</button>
                    </form>
                </div>
            </div>

            <div class="card shadow">
                <div class="card-header"><h6 class="mb-0">{{__('Account Details')}}</h6></div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Joined')}}</span><span>{{ $user->created_at->translatedFormat('M d, Y') }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Last Login')}}</span><span>{{ $user->last_login_at?->diffForHumans() ?? __('Never') }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Last IP')}}</span><span>{{ $user->last_login_ip ?? __('N/A') }}</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
