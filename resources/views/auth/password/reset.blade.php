@extends('auth.layout')

@section('title', __('Choose a new password'))

@php
    $passwordMin = (int) config('validation.user.password.min', 6);
    $linkEmail = $linkEmail ?? null;
    $resetEmail = old('email', $linkEmail);
    // Read-only only when the address came with the link and nothing was typed over it (auth-15).
    $emailLocked = $linkEmail !== null && ! $errors->has('email') && $resetEmail === $linkEmail;
@endphp

@section('auth-card')
    <div class="mk-auth__intro" x-data x-init="$nextTick(() => { const card = $el.closest('.mk-auth__card'); const target = card && (card.querySelector('[data-auth-focus]') || card.querySelector('[aria-invalid=true]')); if (target) target.focus(); })">
        <h1 class="mk-auth__title" id="authTitle">{{ __('Choose a new password') }}</h1>
        <p class="mk-auth__lead">{{ trans_choice('{1} Use at least :count character. After saving you sign in with the new password.|[2,*] Use at least :count characters. After saving you sign in with the new password.', $passwordMin, ['count' => $passwordMin]) }}</p>
    </div>

    <x-auth-errors :fields="['email', 'password', 'password_confirmation']" />

    <form class="mk-form" method="POST" action="{{ locale_url(route('password.update')) }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="mk-field">
            <label class="mk-label" for="email">{{ __('E-mail') }}</label>
            <input
                class="mk-input"
                type="email"
                name="email"
                id="email"
                value="{{ $resetEmail }}"
                autocomplete="username"
                inputmode="email"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                maxlength="255"
                required
                @if($emailLocked) readonly aria-describedby="email-help" @endif
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
            >
            @if($emailLocked)
                <span class="mk-help mk-help--link" id="email-help">{{ __('From your reset link.') }} <a href="{{ locale_url(route('password.forgot')) }}">{{ __('Not you?') }}</a></span>
            @endif
            @error('email')
                <span class="mk-error" id="email-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="mk-form__row mk-form__row--2">
            <x-auth-password name="password" :label="__('New password')" autocomplete="new-password" :minlength="$passwordMin" />
            <x-auth-password name="password_confirmation" :label="__('Confirm new password')" autocomplete="new-password" :minlength="$passwordMin" />
        </div>

        <button type="submit" class="mk-btn mk-btn--primary mk-btn--block"><span>{{ __('Save new password') }}</span></button>
    </form>

    <p class="mk-auth__switch"><a href="{{ locale_url(route('login')) }}"><i class="fa-solid fa-arrow-left mk-flip-rtl" aria-hidden="true"></i> {{ __('Back to sign in') }}</a></p>
@endsection
