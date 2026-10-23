@extends('layouts.admin')
@section('title', __('API Tokens'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-key me-2"></i>{{__('API Tokens (Personal Access Tokens)')}}</{{ $titleTag }}>
    <form method="POST" action="{{ route('admin.api-tokens.revoke-all') }}" onsubmit='return confirm(@json(__('Revoke ALL tokens? This will log out all API users.')))'>
        @csrf
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-ban me-1"></i>{{__('Revoke All')}}</button>
    </form>
</div>

<div class="row mb-4">
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['total'] }}</div><small class="text-muted">{{__('Total Tokens')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-success">{{ $stats['used_today'] }}</div><small class="text-muted">{{__('Used Today')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-warning">{{ $stats['never_used'] }}</div><small class="text-muted">{{__('Never Used')}}</small></div></div>
</div>

<div class="card shadow">
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th><th>{{__('User')}}</th><th>{{__('Token Name')}}</th><th>{{__('Abilities')}}</th><th>{{__('Last Used')}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tokens as $token)
                <tr>
                    <td>{{ $token->id }}</td>
                    <td>{{ $token->username ?? __('N/A') }}<br><small class="text-muted">{{ $token->user_email ?? '' }}</small></td>
                    <td><code>{{ $token->name }}</code></td>
                    <td><small>{{ Str::limit($token->abilities ?? '["*"]', 30) }}</small></td>
                    <td>
                        @if($token->last_used_at)
                            {{ \Carbon\Carbon::parse($token->last_used_at)->diffForHumans() }}
                        @else
                            <span class="badge bg-secondary">{{ __('Never') }}</span>
                        @endif
                    </td>
                    <td>{{ \Carbon\Carbon::parse($token->created_at)->translatedFormat('M d, Y') }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.api-tokens.destroy', $token->id) }}" onsubmit='return confirm(@json(__('Revoke this token?')))'>
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-ban"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">{{__('No API tokens found.')}}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$tokens" class="mt-0" /></div>
</div>
@endsection
