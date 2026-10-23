@extends('layouts.admin')

@section('title', __('Add Language'))

@section('content')
    <div class="d-flex justify-content-between align-items-center my-3">
        <div>
            <h2 class="mb-0">🌐 {{ __('Add Language') }}</h2>
            <small class="text-muted">{{__('Add a new language to the system')}}</small>
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

    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form action="{{ route('admin.languages.store') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="language-code" class="form-label fw-bold">{{__('Language Code')}}<span class="text-danger">*</span></label>
                            <input id="language-code" type="text" name="code" class="form-control @error('code') is-invalid @enderror"
                                   value="{{ old('code') }}" placeholder="{{ __('e.g. vi, fr, ja') }}"
                                   maxlength="10" pattern="[a-z]{2,5}" required>
                            <div class="form-text">{{ __('2–5 lowercase letters (ISO 639-1). Examples: en, vi, zh') }}</div>
                            @error('code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="language-name" class="form-label fw-bold">{{__('Language Name')}}<span class="text-danger">*</span></label>
                            <input id="language-name" type="text" name="label" class="form-control @error('label') is-invalid @enderror"
                                   value="{{ old('label') }}" placeholder="{{ __('e.g. Tiếng Việt, Français') }}" required>
                            @error('label')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="language-emoji" class="form-label fw-bold">{{__('Flag Emoji')}}<span class="text-danger">*</span></label>
                            <input id="language-emoji" type="text" name="emoji" class="form-control @error('emoji') is-invalid @enderror"
                                   value="{{ old('emoji', '🌐') }}" maxlength="20" required>
                            <div class="form-text">{{ __('Country flag emoji. Examples: 🇻🇳 🇫🇷 🇨🇳 🇯🇵') }}</div>
                            @error('emoji')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="language-direction" class="form-label fw-bold">{{__('Text Direction')}}<span class="text-danger">*</span></label>
                            <select id="language-direction" name="direction" class="form-select @error('direction') is-invalid @enderror" required>
                                <option value="ltr" {{ old('direction', 'ltr') === 'ltr' ? 'selected' : '' }}>
                                    {{ __('LTR — Left to Right (most languages)') }}
                                </option>
                                <option value="rtl" {{ old('direction') === 'rtl' ? 'selected' : '' }}>
                                    {{ __('RTL — Right to Left (Arabic, Hebrew, Persian…)') }}
                                </option>
                            </select>
                            @error('direction')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active"
                                       id="is_active" value="1"
                                       {{ old('is_active', '1') === '1' ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    {{ __('Active (visible on the site language switcher)') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fa-solid fa-plus me-1"></i> {{ __('Add Language') }}
                            </button>
                            <a href="{{ route('admin.languages.index') }}" class="btn btn-outline-secondary">{{__('Cancel')}}</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-info mt-3">
                <i class="fa-solid fa-circle-info me-2"></i>
                {!! __('After adding the language, go to <strong>Edit Translations</strong> to add or import the translation strings.') !!}
                {{ __('You can also import a .json file from the main Languages page.') }}
            </div>
        </div>
    </div>
@endsection

