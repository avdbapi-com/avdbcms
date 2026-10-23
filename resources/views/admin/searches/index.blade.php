@extends('layouts.admin')

@section('title', __('Search History'))

@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-magnifying-glass me-2"></i>{{__('Search History')}}</h2>
    <form method="POST" action="{{ route('admin.searches.clear') }}" onsubmit='return confirm(@json(__('Clear ALL search history? This cannot be undone.')))'>
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-danger btn-sm">
            <i class="fa-solid fa-trash me-1"></i>{{__('Clear All History')}}
        </button>
    </form>
</div>
<hr>

{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-primary">
            <div class="card-body text-center">
                <i class="fa-solid fa-magnifying-glass fa-2x text-primary mb-2"></i>
                <h3>{{ number_format($totalSearches) }}</h3>
                <small class="text-muted">{{__('Total Searches')}}</small>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-success">
            <div class="card-body text-center">
                <i class="fa-solid fa-list fa-2x text-success mb-2"></i>
                <h3>{{ $topQueries->total() }}</h3>
                <small class="text-muted">{{__('Unique Queries')}}</small>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-danger">
            <div class="card-body text-center">
                <i class="fa-solid fa-circle-xmark fa-2x text-danger mb-2"></i>
                <h3>{{ number_format($zeroResults) }}</h3>
                <small class="text-muted">{{__('No Results Found')}}</small>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead>
        <tr style="border-top: 3px solid #0D6EFD;">
            <th style="min-width:300px">{{__('Query')}}</th>
            <th>{{__('Times Searched')}}</th>
            <th>{{__('Best Results')}}</th>
            <th style="min-width:150px">{{__('Last Searched')}}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($topQueries as $search)
            <tr class="bg-light">
                <td class="align-middle fw-bold">
                    <i class="fa-solid fa-search text-muted me-1"></i>
                    {{ $search->query }}
                </td>
                <td class="align-middle">
                    <span class="badge bg-primary fs-6">{{ number_format($search->count) }}</span>
                </td>
                <td class="align-middle">
                    <span class="badge {{ $search->max_results > 0 ? 'bg-success' : 'bg-danger' }}">
                        {{ number_format($search->max_results) }} {{__('results')}}
                    </span>
                </td>
                <td class="align-middle">
                    <small>{{ \Carbon\Carbon::parse($search->last_searched)->diffForHumans() }}</small>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="text-center py-4">
                    <i class="fa-solid fa-magnifying-glass fa-2x mb-3 text-muted"></i>
                    <p class="fw-bold text-muted">{{__('No search history found')}}</p>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$topQueries" />
@endsection
