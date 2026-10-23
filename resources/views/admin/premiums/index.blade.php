@extends('layouts.admin')
@section('title', __('Premium Records'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-crown me-2 text-warning"></i>{{__('Premium Records')}}</{{ $titleTag }}>
</div>

<div class="row mb-4">
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['total'] }}</div><small class="text-muted">{{__('Total Records')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-success">{{ $stats['active'] }}</div><small class="text-muted">{{__('Active')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-danger">{{ $stats['expired'] }}</div><small class="text-muted">{{__('Expired')}}</small></div></div>
</div>

<div class="card shadow">
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th><th>{{__('User')}}</th><th>{{__('Plan')}}</th><th>{{__('Gateway')}}</th><th>{{__('Ends At')}}</th><th>{{__('Status')}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($premiums as $p)
                <tr>
                    <td>{{ $p->id }}</td>
                    <td>{{ $p->username ?? __('N/A') }}<br><small class="text-muted">{{ $p->user_email ?? '' }}</small></td>
                    <td><span class="badge bg-info">{{ $p->plan_name ?? __('N/A') }}</span></td>
                    <td><span class="badge bg-secondary">{{ Str::headline($p->payment_provider ?? 'stripe') }}</span></td>
                    <td>{{ $p->ends_at ? \Carbon\Carbon::parse($p->ends_at)->translatedFormat('M d, Y H:i') : 'N/A' }}</td>
                    <td>
                        @if(in_array($p->stripe_status, ['active', 'trialing'], true) && (!$p->ends_at || \Carbon\Carbon::parse($p->ends_at)->isFuture()))
                            <span class="badge bg-success">{{__('Active')}}</span>
                        @elseif($p->stripe_status === 'unpaid')
                            <span class="badge bg-warning">{{__('Unpaid')}}</span>
                        @else
                            <span class="badge bg-danger">{{ __(Str::headline($p->stripe_status ?: 'Expired')) }}</span>
                        @endif
                    </td>
                    <td>{{ \Carbon\Carbon::parse($p->created_at)->translatedFormat('M d, Y') }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" action="{{ route('admin.premiums.extend', $p->id) }}" class="d-flex gap-1">
                                @csrf
                                <input type="number" name="days" value="30" min="1" max="365" class="form-control form-control-sm" style="width:70px" placeholder="{{__('Days')}}">
                                <button class="btn btn-outline-success btn-sm" title="{{__('Extend')}}"><i class="fa-solid fa-plus"></i></button>
                            </form>
                            <form method="POST" action="{{ route('admin.premiums.destroy', $p->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{__('No premium records found.')}}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$premiums" class="mt-0" /></div>
</div>
@endsection
