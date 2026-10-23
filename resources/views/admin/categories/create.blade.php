@extends('layouts.admin')

@section('title', __('Create Category'))

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-folder-plus me-2"></i>{{__('Create Category')}}</h3>
        <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back')}}</a>
    </div>

    <div class="card shadow">
        <div class="card-body">
            <form action="{{ route('admin.categories.store') }}" method="POST">
                @csrf
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold" for="ad-categories-title">{{__('Title')}}</label>
                        <input id="ad-categories-title" type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" required>
                        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">{{__('Slug')}} <small class="text-muted">{{__('(auto-generated if blank)')}}</small></label>
                        <input type="text" name="slug" class="form-control" value="{{ old('slug') }}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">{{__('Icon')}} <small class="text-muted">{{__('(FontAwesome name)')}}</small></label>
                        <input type="text" name="icon" class="form-control" value="{{ old('icon', 'folder') }}" placeholder="folder">
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" name="in_menu" value="1" checked>
                            <label class="form-check-label fw-bold">{{__('Show in Menu')}}</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Create Category')}}</button>
            </form>
        </div>
    </div>
</div>
@endsection
