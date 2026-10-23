@extends('layouts.admin')
@section('title', __('URL Redirects'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-share me-2"></i>{{__('URL Redirect Rules')}}</h2>
</div>
<hr>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-plus me-1"></i>{{__('Create Redirect Rule')}}</h6></div>
    <div class="card-body">
        <form action="{{ route('admin.redirects.store') }}" method="POST" class="row g-3">
            @csrf
            <div class="col-md-4"><label class="form-label fw-bold" for="ad-redirects-source">{{__('Source URL')}}</label><input id="ad-redirects-source" type="text" name="source" class="form-control" placeholder="/old-page" required></div>
            <div class="col-md-4"><label class="form-label fw-bold" for="ad-redirects-destination">{{__('Destination URL')}}</label><input id="ad-redirects-destination" type="text" name="destination" class="form-control" placeholder="/new-page" required></div>
            <div class="col-md-2"><label class="form-label fw-bold" for="ad-redirects-status-code">{{__('Status Code')}}</label>
                <select id="ad-redirects-status-code" name="status_code" class="form-select">
                    <option value="301">301 {{__('Permanent')}}</option>
                    <option value="302">302 {{__('Temporary')}}</option>
                    <option value="307">307 {{__('Temporary')}}</option>
                </select>
            </div>
            <div class="col-md-2 d-flex flex-column justify-content-end gap-2">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="ad-redirect-active" value="1" checked><label class="form-check-label" for="ad-redirect-active">{{__('Active')}}</label></div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i>{{__('Add')}}</button>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #6610f2"><th>{{__('Status')}}</th><th>{{__('Code')}}</th><th style="min-width:200px">{{__('Source')}}</th><th style="min-width:200px">{{__('Destination')}}</th><th>{{__('Hits')}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th></tr></thead>
        <tbody>
        @forelse($redirects as $r)
        <tr>
            <td class="align-middle">@if($r->is_active)<span class="badge bg-success">{{__('Active')}}</span>@else<span class="badge bg-secondary">{{__('Inactive')}}</span>@endif</td>
            <td class="align-middle"><span class="badge bg-{{ $r->status_code == 301 ? 'primary' : 'warning' }}">{{ $r->status_code }}</span></td>
            <td class="align-middle"><code>{{ $r->source }}</code></td>
            <td class="align-middle"><code>{{ $r->destination }}</code></td>
            <td class="align-middle">{{ number_format($r->hits) }}</td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}</small></td>
            <td class="align-middle">
                <div class="d-flex gap-1">
                    <form method="POST" action="{{ route('admin.redirects.toggle', $r->id) }}">@csrf<button class="btn btn-sm {{ $r->is_active ? 'btn-warning' : 'btn-success' }} py-0" aria-label="{{ $r->is_active ? __('Turn off') : __('Turn on') }}"><i class="fa-solid fa-{{ $r->is_active ? 'pause' : 'play' }}"></i></button></form>
                    <form method="POST" action="{{ route('admin.redirects.delete', $r->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>@csrf @method('DELETE')<button class="btn btn-sm btn-danger py-0" aria-label="{{ __('Delete') }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="7" class="text-center py-4 text-muted"><i class="fa-solid fa-share fa-2x mb-2"></i><p>{{__('No redirect rules')}}</p></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$redirects" />
@endsection
