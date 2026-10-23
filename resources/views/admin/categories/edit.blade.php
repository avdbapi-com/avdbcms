@extends('layouts.admin')

@section('title', __('Edit Category') . ': ' . $category->title)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-folder-open me-2"></i>{{__('Edit Category')}}: {{ $category->title }}</h3>
        <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back')}}</a>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">
                    <form action="{{ route('admin.categories.update', $category) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-categories-title">{{__('Title')}}</label>
                                <input id="ad-categories-title" type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $category->title) }}" required>
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold" for="ad-categories-slug">{{__('Slug')}}</label>
                                <input id="ad-categories-slug" type="text" name="slug" class="form-control" value="{{ old('slug', $category->slug) }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">{{__('Icon')}} <small class="text-muted">{{__('(FontAwesome)')}}</small></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-{{ $category->icon_name }}"></i></span>
                                    <input type="text" name="icon" class="form-control" value="{{ old('icon', $category->icon) }}">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" name="in_menu" value="1" {{ $category->in_menu ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold">{{__('Show in Menu')}}</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Update Category')}}</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header"><h6 class="mb-0">{{__('Danger Zone')}}</h6></div>
                <div class="card-body">
                    <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" onsubmit='return confirm(@json(__('Delete this category? Videos in this category will lose their category.')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Category')}}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
