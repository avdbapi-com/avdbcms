@extends('layouts.admin')
@section('title', __('Transactions & Revenue'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-money-bill-wave me-2"></i>{{__('Transactions & Revenue')}}</h2>
    <span class="badge bg-success fs-6">${{ number_format($stats->total_revenue ?? 0, 2) }} {{__('total')}}</span>
</div>
<hr>

{{-- Revenue Summary --}}
<div class="row mb-4">
    @foreach([
        [__('Total Revenue'), '$'.number_format($stats->total_revenue ?? 0, 2), 'fa-dollar-sign', 'success'],
        [__('Net Revenue'), '$'.number_format($stats->net_revenue ?? 0, 2), 'fa-circle-check', 'primary'],
        [__('Total Tax'), '$'.number_format($stats->total_tax ?? 0, 2), 'fa-receipt', 'warning'],
        [__('Total Fees'), '$'.number_format($stats->total_fees ?? 0, 2), 'fa-hand-holding-dollar', 'danger'],
        [__('Today Revenue'), '$'.number_format($stats->today_revenue ?? 0, 2), 'fa-clock', 'info'],
        [__('Month Revenue'), '$'.number_format($stats->month_revenue ?? 0, 2), 'fa-calendar', 'primary'],
        [__('Unique Customers'), number_format($stats->unique_customers ?? 0), 'fa-users', 'info'],
        [__('Total Transactions'), number_format($stats->total ?? 0), 'fa-hashtag', 'secondary'],
    ] as $s)
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>
            <div class="fw-bold fs-6">{{ $s[1] }}</div>
            <small class="text-muted">{{ $s[0] }}</small>
        </div></div>
    </div>
    @endforeach
</div>

<div class="row mb-4">
    {{-- Monthly Revenue --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-chart-bar me-1"></i>{{__('Monthly Revenue')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>{{__('Month')}}</th><th class="text-end">{{__('Revenue')}}</th><th class="text-end">{{__('Transactions')}}</th></tr></thead>
                    <tbody>@foreach($monthlyRevenue as $m)<tr><td class="fw-bold">{{ $m->month }}</td><td class="text-end">${{ number_format($m->revenue, 2) }}</td><td class="text-end">{{ $m->count }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- Top Customers --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-crown me-1"></i>{{__('Top Customers')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>{{__('User')}}</th><th class="text-end">{{__('Total Spent')}}</th><th class="text-end">{{__('Txns')}}</th></tr></thead>
                    <tbody>@foreach($topCustomers as $c)<tr>
                        <td><a href="{{ route('admin.users.edit', $c->id) }}" class="text-decoration-none">{{ $c->username }}</a></td>
                        <td class="text-end fw-bold">${{ number_format($c->total_spent, 2) }}</td>
                        <td class="text-end">{{ $c->transactions }}</td>
                    </tr>@endforeach</tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Transaction Table --}}
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #0D6EFD">
            <th>ID</th><th>{{__('User')}}</th><th>{{__('Gateway')}}</th><th>{{__('Payment ID')}}</th><th style="min-width:140px">{{__('Date')}}</th>
            <th class="text-end">{{__('Amount')}}</th><th class="text-end">{{__('Tax')}}</th><th class="text-end">{{__('Fee')}}</th>
            <th>{{__('Name')}}</th><th>{{__('Country')}}</th><th>{{__('Actions')}}</th>
        </tr></thead>
        <tbody>
        @forelse($transactions as $t)
        <tr>
            <td class="align-middle">#{{ $t->id }}</td>
            <td class="align-middle">
                @if($t->username) <a href="{{ route('admin.users.edit', $t->user_id) }}" class="text-decoration-none">{{ $t->username }}</a>
                @else <span class="text-muted">{{__('Deleted')}}</span> @endif
            </td>
            <td class="align-middle"><span class="badge bg-secondary">{{ Str::headline($t->payment_provider ?? 'stripe') }}</span></td>
            <td class="align-middle"><code class="small">{{ Str::limit($t->stripe_id, 20) }}</code></td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($t->date)->translatedFormat('M d, Y H:i') }}</small></td>
            <td class="align-middle text-end fw-bold">${{ number_format($t->amount / 100, 2) }}</td>
            <td class="align-middle text-end">${{ number_format($t->tax / 100, 2) }}</td>
            <td class="align-middle text-end">${{ number_format($t->fee / 100, 2) }}</td>
            <td class="align-middle"><small>{{ $t->name }}</small></td>
            <td class="align-middle"><small>{{ $t->country }}</small></td>
            <td class="align-middle">
                <form method="POST" action="{{ route('admin.transactions.delete', $t->id) }}" onsubmit='return confirm(@json(__('Delete this transaction?')))'>
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger py-0"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="11" class="text-center py-4">
            <i class="fa-solid fa-money-bill fa-2x mb-3 text-muted"></i>
            <p class="fw-bold text-muted">{{__('No transactions yet')}}</p>
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$transactions" />
@endsection

