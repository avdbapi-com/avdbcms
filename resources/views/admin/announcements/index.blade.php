@extends('layouts.admin')
@section('title', __('Announcements'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-bullhorn me-2"></i>{{__('Announcements & Banners')}}</h2>
</div>
<hr>

{{-- Create New --}}
<div class="card shadow-sm mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-plus me-1"></i>{{__('Create Announcement')}}</h6></div>
    <div class="card-body">
        <form action="{{ route('admin.announcements.store') }}" method="POST" class="row g-3">
            @csrf
            <div class="col-md-4"><label class="form-label fw-bold" for="ad-ann-title">{{__('Title')}}</label><input type="text" name="title" id="ad-ann-title" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label fw-bold" for="ad-ann-type">{{__('Type')}}</label>
                <select name="type" id="ad-ann-type" class="form-select">
                    <option value="info">{{__('Info (Blue)')}}</option>
                    <option value="success">{{__('Success (Green)')}}</option>
                    <option value="warning">{{__('Warning (Yellow)')}}</option>
                    <option value="danger">{{__('Danger (Red)')}}</option>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label fw-bold" for="ad-ann-position">{{__('Position')}}</label>
                <select name="position" id="ad-ann-position" class="form-select">
                    <option value="top">{{__('Top')}}</option>
                    <option value="bottom">{{__('Bottom')}}</option>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label fw-bold" for="ad-ann-starts">{{__('Starts')}}</label><input type="datetime-local" name="starts_at" id="ad-ann-starts" class="form-control"></div>
            <div class="col-md-2"><label class="form-label fw-bold" for="ad-ann-expires">{{__('Expires')}}</label><input type="datetime-local" name="expires_at" id="ad-ann-expires" class="form-control"></div>
            <div class="col-md-10"><label class="form-label fw-bold" for="ad-ann-message">{{__('Message')}}</label><textarea name="message" id="ad-ann-message" class="form-control" rows="2" required></textarea></div>
            <div class="col-md-2 d-flex flex-column justify-content-end gap-2">
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="ad-ann-active" value="1" checked><label class="form-check-label" for="ad-ann-active">{{__('Active')}}</label></div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus me-1"></i>{{__('Create')}}</button>
            </div>
        </form>
    </div>
</div>

{{-- Announcements List --}}
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #fd7e14"><th>{{__('Status')}}</th><th>{{__('Type')}}</th><th>{{__('Title')}}</th><th style="min-width:200px">{{__('Message')}}</th><th>{{__('Position')}}</th><th>{{__('Schedule')}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th></tr></thead>
        <tbody>
        @forelse($announcements as $a)
        <tr>
            <td class="align-middle">
                @if($a->is_active) <span class="badge bg-success">{{__('Active')}}</span>
                @else <span class="badge bg-secondary">{{__('Inactive')}}</span>
                @endif
            </td>
            <td class="align-middle"><span class="badge bg-{{ $a->type }}">{{ ucfirst($a->type) }}</span></td>
            <td class="align-middle fw-bold">{{ $a->title }}</td>
            <td class="align-middle"><small>{{ Str::limit($a->message, 80) }}</small></td>
            <td class="align-middle"><span class="badge bg-dark">{{ ucfirst($a->position) }}</span></td>
            <td class="align-middle">
                <small>
                    @if($a->starts_at) {{__('From')}}: {{ \Carbon\Carbon::parse($a->starts_at)->translatedFormat('M d H:i') }}<br> @endif
                    @if($a->expires_at) {{__('Until')}}: {{ \Carbon\Carbon::parse($a->expires_at)->translatedFormat('M d H:i') }}
                        @if(\Carbon\Carbon::parse($a->expires_at)->isPast()) <span class="badge bg-secondary">{{__('Expired')}}</span> @endif
                    @else
                        <span class="text-muted">{{__('No expiry')}}</span>
                    @endif
                </small>
            </td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($a->created_at)->diffForHumans() }}</small></td>
            <td class="align-middle">
                <div class="d-flex gap-1">
                    <form method="POST" action="{{ route('admin.announcements.toggle', $a->id) }}">@csrf
                        <button class="btn btn-sm {{ $a->is_active ? 'btn-warning' : 'btn-success' }} py-0" aria-label="{{ $a->is_active ? __('Hide') : __('Show') }}"><i class="fa-solid fa-{{ $a->is_active ? 'pause' : 'play' }}" aria-hidden="true"></i></button>
                    </form>
                    <form method="POST" action="{{ route('admin.announcements.destroy', $a->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>@csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger py-0" aria-label="{{ __('Delete') }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" class="text-center py-4 text-muted"><i class="fa-solid fa-bullhorn fa-2x mb-2"></i><p>{{__('No announcements')}}</p></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$announcements" />
@endsection
