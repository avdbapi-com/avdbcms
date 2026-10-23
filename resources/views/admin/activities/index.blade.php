@extends('layouts.admin')
@section('title', __('Activities'))
@section('content')
<div x-data="{selected:[]}">
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-bars-staggered me-2"></i>{{__('User Activities')}}</h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <template x-if="selected.length > 0">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __("selected") }}'"></span>
                <form method="POST" action="{{ route('admin.bulk.activities') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Delete selected activities?') }}')"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Selected')}}</button>
                </form>
            </div>
        </template>
        <form method="POST" action="{{ route('admin.activities.clear') }}" onsubmit='return confirm(@json(__('Clear ALL activities?')))'>
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All')}}</button>
        </form>
    </div>
</div>
<hr>
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-primary"><div class="card-body text-center py-3">
            <i class="fa-solid fa-list fa-2x text-primary mb-2"></i>
            <h3>{{ number_format($stats['total']) }}</h3>
            <small class="text-muted">{{__('Total Activities')}}</small>
        </div></div>
    </div>
    <div class="col-md-3 col-6 mb-3">
        <div class="card shadow-sm border-success"><div class="card-body text-center py-3">
            <i class="fa-solid fa-calendar-day fa-2x text-success mb-2"></i>
            <h3>{{ number_format($stats['today']) }}</h3>
            <small class="text-muted">{{__('Today')}}</small>
        </div></div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card shadow-sm"><div class="card-body">
            <small class="fw-bold text-muted">{{__('BY TYPE')}}</small>
            @foreach($stats['types'] as $type)
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small>{{ class_basename($type->subject_type) }}</small>
                    <span class="badge bg-info">{{ $type->count }}</span>
                </div>
            @endforeach
        </div></div>
    </div>
</div>
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped">
        <thead>
        <tr style="border-top: 3px solid #0D6EFD;">
            <th scope="col">
                <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                    @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                >
            </th>
            <th style="min-width:200px">{{__('User')}}</th>
            <th>{{__('Type')}}</th>
            <th>{{__('Subject ID')}}</th>
            <th style="min-width:150px">{{__('Date')}}</th>
            <th>{{__('Actions')}}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($activities as $activity)
            <tr class="bg-light">
                <td class="align-middle">
                    <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $activity->id }}" x-model="selected">
                </td>
                <td class="align-middle">
                    <div class="fw-bold">{{ $activity->username }}</div>
                    <small class="text-muted">{{ $activity->email }}</small>
                </td>
                <td class="align-middle">
                    <span class="badge bg-secondary">{{ class_basename($activity->subject_type) }}</span>
                </td>
                <td class="align-middle"><small>#{{ $activity->subject_id }}</small></td>
                <td class="align-middle"><small>{{ \Carbon\Carbon::parse($activity->perform_at)->diffForHumans() }}</small></td>
                <td class="align-middle">
                    <form method="POST" action="{{ route('admin.activities.delete', $activity->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4">
                <i class="fa-solid fa-bars-staggered fa-2x mb-3 text-muted"></i>
                <p class="fw-bold text-muted">{{__('No activities found')}}</p>
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$activities" />
</div>
@endsection
