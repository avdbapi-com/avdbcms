@extends('layouts.admin')
@section('title', __('Tags'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-tags me-2"></i>{{__('Tags Management')}}</{{ $titleTag }}>
    <div>
        <form method="POST" action="{{ route('admin.tags.delete-unused') }}" class="d-inline" onsubmit='return confirm(@json(__('Delete all unused tags?')))'>@csrf
            <button class="btn btn-warning btn-sm"><i class="fa-solid fa-broom me-1"></i>{{__('Delete Unused')}} ({{ $stats['unused'] }})</button>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['total'] }}</div><small class="text-muted">{{__('Total Tags')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-success">{{ $stats['used'] }}</div><small class="text-muted">{{__('Used Tags')}}</small></div></div>
    <div class="col-md-4"><div class="card text-center p-3"><div class="h4 mb-0 text-warning">{{ $stats['unused'] }}</div><small class="text-muted">{{__('Unused Tags')}}</small></div></div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card shadow mb-3">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-plus me-1"></i>{{__('Add Tag')}}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.tags.store') }}">@csrf
                    <div class="input-group">
                        <input type="text" name="name" class="form-control" placeholder="{{__('Tag name')}}" aria-label="{{__('Tag name')}}" required maxlength="100">
                        <button class="btn btn-primary" aria-label="{{__('Add Tag')}}"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card shadow">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-code-merge me-1"></i>{{__('Merge Tags')}}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.tags.merge') }}">@csrf
                    <div class="mb-2"><input type="number" name="source_id" class="form-control form-control-sm" placeholder="{{__('Source Tag ID (to merge FROM)')}}" required></div>
                    <div class="mb-2"><input type="number" name="target_id" class="form-control form-control-sm" placeholder="{{__('Target Tag ID (to merge INTO)')}}" required></div>
                    <button class="btn btn-outline-primary btn-sm w-100" onclick='return confirm(@json(__('Merge these tags?')))'><i class="fa-solid fa-code-merge me-1"></i>{{__('Merge')}}</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header d-flex justify-content-between">
                <h5 class="mb-0">{{__('All Tags')}}</h5>
                <form method="GET" class="d-flex gap-1" style="width:250px">
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{__('Search tags...')}}" aria-label="{{__('Search tags...')}}">
                    <button class="btn btn-outline-primary btn-sm" aria-label="{{__('Search')}}"><i class="fa-solid fa-search"></i></button>
                </form>
            </div>
            <div class="table-responsive" tabindex="0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>ID</th><th>{{__('Name')}}</th><th>{{__('Slug')}}</th><th>{{__('Usage')}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th></tr>
                    </thead>
                    <tbody>
                        @forelse($tags as $tag)
                        <tr>
                            <td>{{ $tag->id }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.tags.update', $tag->id) }}" class="d-flex gap-1">@csrf @method('PUT')
                                    <input type="text" name="name" value="{{ $tag->name }}" class="form-control form-control-sm" style="width:150px" aria-label="{{__('Name')}}">
                                    <button class="btn btn-outline-primary btn-sm" title="{{__('Save')}}" aria-label="{{__('Save')}}"><i class="fa-solid fa-check"></i></button>
                                </form>
                            </td>
                            <td><code>{{ $tag->slug }}</code></td>
                            <td><span class="badge bg-{{ $tag->usage_count > 0 ? 'success' : 'secondary' }}">{{ $tag->usage_count }}</span></td>
                            <td>{{ \Carbon\Carbon::parse($tag->created_at)->translatedFormat('M d, Y') }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.tags.destroy', $tag->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>@csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" aria-label="{{__('Delete')}}"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">{{__('No tags found.')}}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer"><x-admin.pagination :paginator="$tags" class="mt-0" /></div>
        </div>
    </div>
</div>
@endsection
