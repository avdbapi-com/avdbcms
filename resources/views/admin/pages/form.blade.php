@extends('layouts.admin')
@section('title', isset($page) ? 'Edit Page' : 'Create Page')
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-file-pen me-2"></i>{{ isset($page) ? 'Edit Page: ' . $page->title : 'Create New Page' }}</{{ $titleTag }}>
    <a href="{{ route('admin.pages.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back to Pages')}}</a>
</div>

<div class="card shadow">
    <form method="POST" action="{{ isset($page) ? route('admin.pages.update', $page->id) : route('admin.pages.store') }}">
        @csrf
        @if(isset($page)) @method('PUT') @endif
        <div class="card-body">
            <div class="row">
                <div class="col-md-8 mb-3">
                    <label class="form-label fw-bold" for="ad-pages-title">{{__('Title')}}</label>
                    <input id="ad-pages-title" type="text" name="title" class="form-control" value="{{ $page->title ?? old('title') }}" required maxlength="200">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">{{__('Slug')}}</label>
                    <div class="input-group">
                        <span class="input-group-text">/page/</span>
                        <input type="text" name="slug" class="form-control" value="{{ $page->slug ?? old('slug') }}" required maxlength="200">
                    </div>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label fw-bold" for="ad-pages-content">{{__('Content (HTML allowed)')}}</label>
                    <textarea id="ad-pages-content" name="content" class="form-control" rows="15" style="font-family:monospace; font-size:13px;">{{ $page->content ?? old('content') }}</textarea>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold" for="ad-pages-meta-title">{{__('Meta Title (SEO)')}}</label>
                    <input id="ad-pages-meta-title" type="text" name="meta_title" class="form-control" value="{{ $page->meta_title ?? old('meta_title') }}" maxlength="200">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold" for="ad-pages-meta-description">{{__('Meta Description (SEO)')}}</label>
                    <input id="ad-pages-meta-description" type="text" name="meta_description" class="form-control" value="{{ $page->meta_description ?? old('meta_description') }}" maxlength="500">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold" for="ad-pages-sort-order">{{__('Sort Order')}}</label>
                    <input id="ad-pages-sort-order" type="number" name="sort_order" class="form-control" value="{{ $page->sort_order ?? 0 }}">
                </div>
                <div class="col-md-3 mb-3">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" name="is_published" value="1" {{ (isset($page) ? $page->is_published : false) ? 'checked' : '' }}>
                        <label class="form-check-label fw-bold">{{__('Published')}}</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" name="show_in_header" value="1" {{ (isset($page) ? $page->show_in_header : false) ? 'checked' : '' }}>
                        <label class="form-check-label fw-bold">{{__('Show in Header')}}</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" name="show_in_footer" value="1" {{ (isset($page) ? $page->show_in_footer : false) ? 'checked' : '' }}>
                        <label class="form-check-label fw-bold">{{__('Show in Footer')}}</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{ isset($page) ? 'Update Page' : 'Create Page' }}</button>
        </div>
    </form>
</div>
@endsection
