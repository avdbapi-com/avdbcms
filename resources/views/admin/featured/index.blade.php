@extends('layouts.admin')
@section('title', __('Featured Content'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-star me-2 text-warning"></i>{{__('Featured Content')}}</{{ $titleTag }}>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFeaturedModal"><i class="fa-solid fa-plus me-1"></i>{{__('Add Featured')}}</button>
</div>

<div class="card shadow">
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th><th>{{__('Type')}}</th><th>{{__('Title / Reference')}}</th><th>{{__('Position')}}</th><th>{{__('Order')}}</th><th>{{__('Status')}}</th><th>{{__('Schedule')}}</th><th>{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($featured as $f)
                <tr>
                    <td>{{ $f->id }}</td>
                    <td><span class="badge bg-{{ $f->type === 'video' ? 'primary' : ($f->type === 'user' ? 'info' : ($f->type === 'banner' ? 'warning' : 'secondary')) }}">{{ ucfirst($f->type) }}</span></td>
                    <td>
                        {{ $f->title ?? $f->video_title ?? $f->username ?? '-' }}
                        @if($f->link_url)<br><small class="text-muted"><i class="fa-solid fa-link"></i> {{ Str::limit($f->link_url, 40) }}</small>@endif
                    </td>
                    <td><span class="badge bg-outline-secondary text-dark">{{ __(Str::headline($f->position)) }}</span></td>
                    <td>{{ $f->sort_order }}</td>
                    <td>
                        @if($f->is_active)
                            <span class="badge bg-success">{{__('Active')}}</span>
                        @else
                            <span class="badge bg-secondary">{{__('Inactive')}}</span>
                        @endif
                    </td>
                    <td>
                        @if($f->starts_at)<small>{{ __('From') }}: {{ \Carbon\Carbon::parse($f->starts_at)->translatedFormat('M d') }}</small><br>@endif
                        @if($f->expires_at)<small>{{ __('Until') }}: {{ \Carbon\Carbon::parse($f->expires_at)->translatedFormat('M d') }}</small>@endif
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" action="{{ route('admin.featured.toggle', $f->id) }}">@csrf
                                <button class="btn btn-outline-{{ $f->is_active ? 'warning' : 'success' }} btn-sm" title="{{ $f->is_active ? __('Deactivate') : __('Activate') }}">
                                    <i class="fa-solid fa-{{ $f->is_active ? 'eye-slash' : 'eye' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.featured.destroy', $f->id) }}" onsubmit='return confirm(@json(__('Remove?')))'>@csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No featured content. Click "Add Featured" to get started.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$featured" class="mt-0" /></div>
</div>

{{-- Add Featured Modal --}}
<div class="modal fade" id="addFeaturedModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.featured.store') }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-star me-1"></i>{{__('Add Featured Content')}}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="ad-featured-type">{{__('Type')}}</label>
                            <select id="ad-featured-type" name="type" class="form-select">
                                <option value="video">{{__('Video')}}</option><option value="user">{{__('User / Channel')}}</option>
                                <option value="category">{{__('Category')}}</option><option value="banner">{{__('Custom Banner')}}</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold" for="ad-featured-position">{{__('Position')}}</label>
                            <select id="ad-featured-position" name="position" class="form-select">
                                <option value="homepage_hero">{{__('Homepage Hero')}}</option><option value="homepage_sidebar">{{__('Homepage Sidebar')}}</option>
                                <option value="trending">{{__('Trending Section')}}</option><option value="category_top">{{__('Category Top')}}</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3"><label class="form-label fw-bold" for="ad-featured-video-id">{{__('Video ID')}}</label><input id="ad-featured-video-id" type="number" name="video_id" class="form-control" placeholder="{{__('Optional')}}"></div>
                        <div class="col-md-4 mb-3"><label class="form-label fw-bold" for="ad-featured-user-id">{{__('User ID')}}</label><input id="ad-featured-user-id" type="number" name="user_id" class="form-control" placeholder="{{__('Optional')}}"></div>
                        <div class="col-md-4 mb-3"><label class="form-label fw-bold" for="ad-featured-category-id">{{__('Category ID')}}</label><input id="ad-featured-category-id" type="number" name="category_id" class="form-control" placeholder="{{__('Optional')}}"></div>
                        <div class="col-md-8 mb-3"><label class="form-label fw-bold" for="ad-featured-title">{{__('Title')}}</label><input id="ad-featured-title" type="text" name="title" class="form-control" maxlength="200"></div>
                        <div class="col-md-4 mb-3"><label class="form-label fw-bold" for="ad-featured-sort-order">{{__('Sort Order')}}</label><input id="ad-featured-sort-order" type="number" name="sort_order" class="form-control" value="0"></div>
                        <div class="col-12 mb-3"><label class="form-label fw-bold" for="ad-featured-description">{{__('Description')}}</label><textarea id="ad-featured-description" name="description" class="form-control" rows="2" maxlength="500"></textarea></div>
                        <div class="col-md-6 mb-3"><label class="form-label fw-bold" for="ad-featured-image-url">{{__('Image URL')}}</label><input id="ad-featured-image-url" type="text" name="image_url" class="form-control" placeholder="https://..."></div>
                        <div class="col-md-6 mb-3"><label class="form-label fw-bold" for="ad-featured-link-url">{{__('Link URL')}}</label><input id="ad-featured-link-url" type="text" name="link_url" class="form-control" placeholder="https://..."></div>
                        <div class="col-md-4 mb-3"><label class="form-label fw-bold" for="ad-featured-starts-at">{{__('Starts At')}}</label><input id="ad-featured-starts-at" type="datetime-local" name="starts_at" class="form-control"></div>
                        <div class="col-md-4 mb-3"><label class="form-label fw-bold" for="ad-featured-expires-at">{{__('Expires At')}}</label><input id="ad-featured-expires-at" type="datetime-local" name="expires_at" class="form-control"></div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                                <label class="form-check-label fw-bold">{{__('Active')}}</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{__('Cancel')}}</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Save')}}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
