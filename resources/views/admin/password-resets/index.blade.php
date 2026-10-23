@extends('layouts.admin')
@section('title', __('Password Resets'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-key me-2"></i>{{__('Password Reset Tokens')}}</h2>
    <form method="POST" action="{{ route('admin.password-resets.clear') }}" onsubmit='return confirm(@json(__('Clear ALL pending reset tokens?')))'>
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All Tokens')}}</button>
    </form>
</div>
<hr>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-warning"><div class="card-body text-center">
            <i class="fa-solid fa-key fa-2x text-warning mb-2"></i>
            <h3>{{ number_format($stats['total']) }}</h3>
            <small class="text-muted">{{__('Total Pending')}}</small>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-danger"><div class="card-body text-center">
            <i class="fa-solid fa-clock fa-2x text-danger mb-2"></i>
            <h3>{{ number_format($stats['recent']) }}</h3>
            <small class="text-muted">{{__('Last Hour')}}</small>
        </div></div>
    </div>
</div>
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead><tr style="border-top:3px solid #0D6EFD">
            <th style="min-width:200px">{{__('Email')}}</th>
            <th>{{__('User')}}</th>
            <th>{{__('Email Verified')}}</th>
            <th style="min-width:150px">{{__('Requested')}}</th>
            <th>{{__('Token Preview')}}</th>
            <th>{{__('Actions')}}</th>
        </tr></thead>
        <tbody>
        @forelse($resets as $reset)
        <tr class="bg-light">
            <td class="align-middle fw-bold">{{ $reset->email }}</td>
            <td class="align-middle">
                @if($reset->user_id)
                    <a href="{{ route('admin.users.edit', $reset->user_id) }}" class="text-decoration-none">
                        {{ $reset->username }}
                    </a>
                @else
                    <span class="text-muted">{{__('Not found')}}</span>
                @endif
            </td>
            <td class="align-middle">
                @if($reset->email_verified_at)
                    <span class="badge bg-success">{{__('Verified')}}</span>
                @else
                    <span class="badge bg-warning">{{__('Unverified')}}</span>
                @endif
            </td>
            <td class="align-middle">
                <small>{{ \Carbon\Carbon::parse($reset->created_at)->diffForHumans() }}</small>
            </td>
            <td class="align-middle">
                <code class="small">{{ Str::limit($reset->token, 20) }}...</code>
            </td>
            <td class="align-middle">
                <form method="POST" action="{{ route('admin.password-resets.delete', $reset->email) }}" onsubmit='return confirm(@json(__('Delete this reset token?')))'>
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" class="text-center py-4">
            <i class="fa-solid fa-key fa-2x mb-3 text-muted"></i>
            <p class="fw-bold text-success">{{__('No pending password reset tokens')}}</p>
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$resets" />
@endsection
