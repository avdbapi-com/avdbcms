@props(['name' => 'password', 'label' => null, 'autocomplete' => 'current-password', 'minlength' => null])
@php($label = $label ?? __('Password'))
<div class="form-group mb-3" x-data="{ show: false }">
    <div class="form-floating avd-auth-password">
        <input
            type="password"
            :type="show ? 'text' : 'password'"
            @class(['form-control', 'is-invalid' => $errors->has($name)])
            name="{{ $name }}"
            id="{{ $name }}"
            placeholder="{{ $label }}"
            autocomplete="{{ $autocomplete }}"
            @if($minlength) minlength="{{ $minlength }}" @endif
            @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
            required
        >
        <label for="{{ $name }}">{{ $label }}</label>
        <button type="button" class="avd-auth-password-toggle" x-cloak
                @click="show = !show" :aria-pressed="show.toString()"
                aria-label="{{ __('Show password') }}" aria-controls="{{ $name }}">
            <i class="fa-solid" :class="show ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i>
        </button>
    </div>
    @error($name)
        <div class="invalid-feedback d-block" id="{{ $name }}-error">{{ $message }}</div>
    @enderror
</div>
