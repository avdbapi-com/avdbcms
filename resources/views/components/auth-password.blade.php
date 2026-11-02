{{-- A password field of the sign-in pages (member design 5.9, 6.12): the label above, the show / hide eye inside the
     field (a 44 x 44px target on touch screens), the error under the field with its icon, aria-invalid and
     aria-describedby. $errorFor names the error to show here (the "confirmed" error of "password" is shown under the
     confirmation field: auth-24). The optional "aside" slot sits at the end of the label row ("Forgot password?"); the
     field is named by the label's text only (aria-labelledby), so a screen reader says "Password", not "Password Forgot
     password?" (section 8: a clean label on every field), and the link keeps its own name.
     <x-auth-password name="password" :label="__('Password')" autocomplete="current-password"><x-slot:aside>...</x-slot:aside></x-auth-password> --}}
@props(['name' => 'password', 'label' => null, 'autocomplete' => 'current-password', 'minlength' => null, 'errorFor' => null, 'help' => null])
@php
    $label = $label ?? __('Password');
    $errorFor = $errorFor ?? $name;
    $fieldId = 'auth-'.str_replace('_', '-', $name);
    $hasError = $errors->has($errorFor);
    $described = trim(($help ? $fieldId.'-help ' : '').($hasError ? $fieldId.'-error' : ''));
@endphp
<div class="mk-field" x-data="{ show: false }">
    <label class="mk-label" for="{{ $fieldId }}"><span id="{{ $fieldId }}-label">{{ $label }}</span>{{ $aside ?? '' }}</label>
    <div class="mk-group">
        <input
            class="mk-input"
            type="password"
            :type="show ? 'text' : 'password'"
            name="{{ $name }}"
            id="{{ $fieldId }}"
            aria-labelledby="{{ $fieldId }}-label"
            autocomplete="{{ $autocomplete }}"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            @if($minlength) minlength="{{ $minlength }}" @endif
            @if($hasError) aria-invalid="true" @endif
            @if($described !== '') aria-describedby="{{ $described }}" @endif
            required
        >
        <button type="button" class="mk-group__addon mk-group__addon--end"
                @click="show = !show" :aria-pressed="show.toString()" aria-pressed="false"
                aria-label="{{ __('Show password') }}" :aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))"
                title="{{ __('Show password') }}" :title="show ? @js(__('Hide password')) : @js(__('Show password'))"
                aria-controls="{{ $fieldId }}">
            <i class="fa-regular fa-eye" :class="{ 'fa-eye': !show, 'fa-eye-slash': show }" aria-hidden="true"></i>
        </button>
    </div>
    @if($help)
        <span class="mk-help" id="{{ $fieldId }}-help">{{ $help }}</span>
    @endif
    @if($hasError)
        <span class="mk-error" id="{{ $fieldId }}-error">{{ $errors->first($errorFor) }}</span>
    @endif
</div>
