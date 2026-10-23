@extends('layouts.admin')
@section('title', __('Failed Jobs'))
@section('content')
<div x-data="{selected:[]}">
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-triangle-exclamation me-2 text-warning"></i>{{__('Failed Queue Jobs')}}</h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <template x-if="selected.length > 0">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __("selected") }}'"></span>
                <form method="POST" action="{{ route('admin.bulk.failed-jobs') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="retry">
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-rotate me-1"></i>{{__('Retry Selected')}}</button>
                </form>
                <form method="POST" action="{{ route('admin.bulk.failed-jobs') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Delete selected failed jobs?') }}')"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Selected')}}</button>
                </form>
            </div>
        </template>
        <form method="POST" action="{{ route('admin.failed-jobs.retry-all') }}" onsubmit='return confirm(@json(__('Retry ALL failed jobs?')))'>
            @csrf
            <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-rotate me-1"></i>{{__('Retry All')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.failed-jobs.clear') }}" onsubmit='return confirm(@json(__('Delete ALL failed jobs?')))'>
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All')}}</button>
        </form>
    </div>
</div>
<hr>
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-danger"><div class="card-body text-center py-3">
            <i class="fa-solid fa-circle-xmark fa-2x text-danger mb-2"></i>
            <h3>{{ number_format($stats['total']) }}</h3>
            <small class="text-muted">{{__('Total Failed')}}</small>
        </div></div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-warning"><div class="card-body text-center py-3">
            <i class="fa-solid fa-calendar-day fa-2x text-warning mb-2"></i>
            <h3>{{ number_format($stats['today']) }}</h3>
            <small class="text-muted">{{__('Today')}}</small>
        </div></div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card shadow-sm"><div class="card-body">
            <small class="fw-bold text-muted">{{__('BY QUEUE')}}</small>
            @foreach($stats['queues'] as $q)
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small>{{ $q->queue }}</small><span class="badge bg-danger">{{ $q->count }}</span>
            </div>
            @endforeach
        </div></div>
    </div>
</div>
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered">
        <thead><tr style="border-top:3px solid #dc3545">
            <th scope="col">
                <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                    @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                >
            </th>
            <th>UUID</th><th>{{__('Queue')}}</th><th style="min-width:200px">{{__('Connection')}}</th>
            <th style="min-width:150px">{{__('Failed At')}}</th><th>{{__('Actions')}}</th>
        </tr></thead>
        <tbody>
        @forelse($jobs as $job)
        <tr class="bg-light">
            <td class="align-middle">
                <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $job->uuid }}" x-model="selected">
            </td>
            <td class="align-middle font-monospace" style="font-size:11px">{{ Str::limit($job->uuid, 20) }}</td>
            <td class="align-middle"><span class="badge bg-secondary">{{ $job->queue }}</span></td>
            <td class="align-middle"><span class="badge bg-info">{{ $job->connection }}</span></td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</small></td>
            <td class="align-middle">
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#exceptionModal"
                        onclick="document.getElementById('exceptionContent').textContent = {{ json_encode($job->exception) }}">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    <form method="POST" action="{{ route('admin.failed-jobs.retry', $job->uuid) }}">
                        @csrf
                        <button class="btn btn-sm btn-success" title="{{__('Retry')}}"><i class="fa-solid fa-rotate"></i></button>
                    </form>
                    <form method="POST" action="{{ route('admin.failed-jobs.delete', $job->uuid) }}" onsubmit='return confirm(@json(__('Delete?')))'>
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger" title="{{__('Delete')}}"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="6" class="text-center py-4">
            <i class="fa-solid fa-check-circle fa-2x mb-3 text-success"></i>
            <p class="fw-bold text-success">{{__('No failed jobs - queue is healthy!')}}</p>
        </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$jobs" />

{{-- Exception Modal --}}
<div class="modal fade" id="exceptionModal" tabindex="-1">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header bg-danger text-white">
            <h5 class="modal-title"><i class="fa-solid fa-bug me-2"></i>{{__('Exception Details')}}</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <pre id="exceptionContent" class="bg-dark text-light p-3 rounded" style="max-height:500px;overflow-y:auto;font-size:12px;white-space:pre-wrap"></pre>
        </div>
    </div></div>
</div>
</div>
@endsection
