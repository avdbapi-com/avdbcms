@extends('layouts.admin')

@section('title', __('Invoices'))

@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-receipt me-2"></i>{{__('Invoices & Transactions')}}</h2>
</div>
<hr>

{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-primary">
            <div class="card-body text-center py-3">
                <i class="fa-solid fa-receipt fa-2x text-primary mb-2"></i>
                <h3>{{ number_format($stats->total_count) }}</h3>
                <small class="text-muted">{{__('Total Invoices')}}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-success">
            <div class="card-body text-center py-3">
                <i class="fa-solid fa-dollar-sign fa-2x text-success mb-2"></i>
                <h3>${{ number_format($stats->total_amount, 2) }}</h3>
                <small class="text-muted">{{__('Total Revenue')}}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-warning">
            <div class="card-body text-center py-3">
                <i class="fa-solid fa-percent fa-2x text-warning mb-2"></i>
                <h3>${{ number_format($stats->total_tax, 2) }}</h3>
                <small class="text-muted">{{__('Total Tax')}}</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-danger">
            <div class="card-body text-center py-3">
                <i class="fa-solid fa-hand-holding-dollar fa-2x text-danger mb-2"></i>
                <h3>${{ number_format($stats->total_fee, 2) }}</h3>
                <small class="text-muted">{{__('Total Fees')}}</small>
            </div>
        </div>
    </div>
</div>

{{-- Filter --}}
<form method="GET" action="{{ route('admin.invoices.index') }}" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="user" class="form-control" placeholder="{{__('Search by username or email...')}}" value="{{ request('user') }}">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search me-1"></i>{{__('Filter')}}</button>
        <a href="{{ route('admin.invoices.index') }}" class="btn btn-secondary ms-1">{{__('Reset')}}</a>
    </div>
</form>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead>
        <tr style="border-top: 3px solid #0D6EFD;">
            <th style="min-width: 110px">{{__('Date')}}</th>
            <th style="min-width: 50px">{{__('Amount')}}</th>
            <th style="min-width: 50px">{{__('Tax')}}</th>
            <th style="min-width: 50px">{{__('Fee')}}</th>
            <th style="min-width: 200px">{{__('User')}}</th>
            <th>{{__('Country')}}</th>
            <th>{{__('Actions')}}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($invoices as $invoice)
            <tr class="bg-light">
                <td class="align-middle">
                    <small class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{ $invoice->date->translatedFormat('d F Y - H:i') }}">
                        {{ $invoice->date->diffForHumans() }}
                    </small>
                </td>
                <td class="align-middle fw-bold text-success">
                    {{ $invoice->amount }}
                </td>
                <td class="align-middle">
                    {{ $invoice->tax }}
                </td>
                <td class="align-middle">
                    {{ $invoice->fee }}
                </td>
                <td class="align-middle">
                    @if($invoice->user)
                        <a href="{{ route('admin.users.edit', $invoice->user) }}" class="d-flex align-items-center gap-2 text-decoration-none">
                            <img src="{{ $invoice->user->avatar_url }}" class="rounded" style="width:32px;height:32px;object-fit:cover">
                            <div>
                                <div class="fw-bold">{{ $invoice->user->username }}</div>
                                <small class="text-muted">{{ $invoice->user->email }}</small>
                            </div>
                        </a>
                    @else
                        <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                    @endif
                </td>
                <td class="align-middle">
                    <span class="badge bg-secondary">{{ strtoupper($invoice->country ?? '—') }}</span>
                </td>
                <td class="align-middle">
                    <div class="d-flex gap-1">
                        <a href="{{ $invoice->route }}" target="_blank" class="btn btn-primary btn-sm d-flex align-items-center gap-2" title="{{__('Download Invoice')}}">
                            <i class="fa-solid fa-download"></i>
                            <span>{{__('Download')}}</span>
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="text-center py-4">
                    <i class="fa-solid fa-receipt fa-2x mb-3 text-muted"></i>
                    <p class="fw-bold text-muted">{{__('No invoices found')}}</p>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$invoices" />
@endsection
