@extends('layouts.admin')

@section('title', __('Edit Language — :label', ['label' => $language->label]))

@section('content')
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h2 class="mb-0">{{ $language->emoji }} {{ __('Edit') }} — {{ $language->label }}</h2>
            <small class="text-muted">
                <code>{{ $language->code }}</code>
                @if($language->is_default)
                    <span class="badge bg-primary ms-1">{{__('Default')}}</span>
                @endif
            </small>
        </div>
        <a href="{{ route('admin.languages.index') }}" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> {{ __('Back to Languages') }}
        </a>
    </div>
    <hr>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header fw-bold">{{__('Language Settings')}}</div>
                <div class="card-body">
                    <form action="{{ route('admin.languages.update-meta', $language) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="language-code" class="form-label fw-bold">{{__('Language Code')}}</label>
                            <input id="language-code" type="text" class="form-control bg-light" value="{{ $language->code }}" readonly>
                            <div class="form-text text-muted">{{__('Code cannot be changed after creation.')}}</div>
                        </div>

                        <div class="mb-3">
                            <label for="language-name" class="form-label fw-bold">{{__('Language Name')}}<span class="text-danger">*</span></label>
                            <input id="language-name" type="text" name="label" class="form-control @error('label') is-invalid @enderror"
                                   value="{{ old('label', $language->label) }}" required>
                            @error('label')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="language-emoji" class="form-label fw-bold">{{__('Flag Emoji')}}<span class="text-danger">*</span></label>
                            <input id="language-emoji" type="text" name="emoji" class="form-control @error('emoji') is-invalid @enderror"
                                   value="{{ old('emoji', $language->emoji) }}" maxlength="20" required>
                            @error('emoji')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="language-direction" class="form-label fw-bold">{{__('Text Direction')}}<span class="text-danger">*</span></label>
                            <select id="language-direction" name="direction" class="form-select @error('direction') is-invalid @enderror" required>
                                <option value="ltr" {{ old('direction', $language->direction) === 'ltr' ? 'selected' : '' }}>
                                    {{ __('LTR — Left to Right') }}
                                </option>
                                <option value="rtl" {{ old('direction', $language->direction) === 'rtl' ? 'selected' : '' }}>
                                    {{ __('RTL — Right to Left (Arabic, Hebrew…)') }}
                                </option>
                            </select>
                            @error('direction')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="language-sort-order" class="form-label fw-bold">{{__('Sort Order')}}</label>
                            <input id="language-sort-order" type="number" name="sort_order" class="form-control"
                                   value="{{ old('sort_order', $language->sort_order) }}" min="0">
                            <div class="form-text">{{__('Lower numbers appear first in the language switcher.')}}</div>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active"
                                       id="is_active" value="1"
                                       {{ old('is_active', $language->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    {{ __('Active (visible on site)') }}
                                </label>
                            </div>
                            @if($language->is_default)
                                <div class="form-text text-warning">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                    {{ __('Default language cannot be deactivated.') }}
                                </div>
                            @endif
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-save me-1"></i> {{ __('Save Changes') }}
                            </button>
                            <a href="{{ route('admin.languages.edit', $language->code) }}" class="btn btn-outline-primary">
                                <i class="fa-solid fa-language me-1"></i> {{ __('Edit Translations') }}
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Danger Zone --}}
            @if(!$language->is_default)
            <div class="card border-danger mt-3">
                <div class="card-header text-danger fw-bold">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>{{__('Danger Zone')}}</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong>{{__('Set as Default Language')}}</strong>
                            <div class="text-muted small">{{__('This language will be served at the bare URL (no prefix).')}}</div>
                        </div>
                        <form method="POST" action="{{ route('admin.languages.set-default', $language) }}"
                              onsubmit='return confirm({!! json_encode(__('Set :label as the default language? The current default will lose its Default status.', ['label' => $language->label])) !!})'>
                            @csrf
                            <button type="submit" class="btn btn-warning btn-sm">
                                <i class="fa-solid fa-star me-1"></i> {{ __('Set as Default') }}
                            </button>
                        </form>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong class="text-danger">{{__('Delete Language')}}</strong>
                            <div class="text-muted small">{{__('Removes from DB. Translation JSON file is kept.')}}</div>
                        </div>
                        <form method="POST" action="{{ route('admin.languages.destroy', $language) }}"
                              onsubmit='return confirm({!! json_encode(__('Delete :label? This cannot be undone.', ['label' => $language->label])) !!})'>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="fa-solid fa-trash me-1"></i>{{__('Delete')}}</button>
                        </form>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
@endsection
