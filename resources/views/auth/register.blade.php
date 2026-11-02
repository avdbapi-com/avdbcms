@extends('auth.layout')

@section('title', __('Create an account'))

@php
    $usernameMin = (int) config('validation.user.username.min', 3);
    $usernameMax = (int) config('validation.user.username.max', 40);
    $passwordMin = (int) config('validation.user.password.min', 6);
    $passwordHelp = config('site.require_strong_password', false)
        ? __('At least :count characters, with upper and lower case letters, a number and a symbol.', ['count' => $passwordMin])
        : __('At least :count characters.', ['count' => $passwordMin]);
@endphp

@section('auth-card')
    <div class="mk-auth__intro" x-data x-init="$nextTick(() => { const card = $el.closest('.mk-auth__card'); const target = card && (card.querySelector('[data-auth-focus]') || card.querySelector('[aria-invalid=true]')); if (target) target.focus(); })">
        <h1 class="mk-auth__title" id="authTitle">{{ __('Create an account') }}</h1>
        <p class="mk-auth__lead">{{ config('site.allow_uploads', true) ? __('Free. Save videos, follow creators and upload your own.') : __('Free. Save videos, follow creators and join the conversation.') }}</p>
    </div>

    <x-auth-errors :fields="['username', 'email', 'password', 'password_confirmation', 'cgu', 'captcha']" captcha="register" />

    <form class="mk-form" method="POST" action="{{ locale_url(route('registration.perform')) }}" novalidate>
        @csrf
        <div class="mk-field">
            <label class="mk-label" for="username">{{ __('Username') }}</label>
            <input
                class="mk-input"
                type="text"
                name="username"
                id="username"
                value="{{ old('username') }}"
                autocomplete="username"
                inputmode="text"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                minlength="{{ $usernameMin }}"
                maxlength="{{ $usernameMax }}"
                required
                aria-describedby="username-help @error('username') username-error @enderror"
                @error('username') aria-invalid="true" @enderror
            >
            <span class="mk-help" id="username-help">{{ __('Letters, numbers, dots, dashes and underscores. :min to :max characters.', ['min' => $usernameMin, 'max' => $usernameMax]) }}</span>
            @error('username')
                <span class="mk-error" id="username-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="mk-field">
            <label class="mk-label" for="email">{{ __('E-mail') }}</label>
            <input
                class="mk-input"
                type="email"
                name="email"
                id="email"
                value="{{ old('email') }}"
                autocomplete="email"
                inputmode="email"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                maxlength="255"
                required
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
            >
            @error('email')
                <span class="mk-error" id="email-error">{{ $message }}</span>
            @enderror
        </div>

        <div class="mk-form__row mk-form__row--2">
            {{-- A mismatch is an error of "Confirm password", where it is (RegisterRequest, auth-24). --}}
            <x-auth-password name="password" :label="__('Password')" autocomplete="new-password" :minlength="$passwordMin" :help="$passwordHelp" />
            <x-auth-password name="password_confirmation" :label="__('Confirm password')" autocomplete="new-password" :minlength="$passwordMin" />
        </div>

        <x-captcha action="register" />

        <div class="mk-field">
            <label class="mk-option">
                <input class="mk-check" type="checkbox" id="cgu" name="cgu" value="1" @checked(old('cgu')) required
                       @error('cgu') aria-invalid="true" aria-describedby="cgu-error" @enderror>
                <span class="mk-option__text"><span class="mk-auth__terms">{!! __('I agree to the :terms.', ['terms' => '<a href="'.e(locale_url(route('pages.terms'))).'" target="_blank" rel="noopener">'.e(__('Terms of service')).'</a>']) !!}</span></span>
            </label>
            @error('cgu')
                <span class="mk-error" id="cgu-error">{{ __('Agree to the Terms of service to create an account.') }}</span>
            @enderror
        </div>

        <button type="submit" class="mk-btn mk-btn--primary mk-btn--block"><span>{{ __('Create account') }}</span></button>
    </form>

    <x-auth-social mode="register" />

    <p class="mk-auth__switch">{{ __('Already have an account?') }} <a href="{{ locale_url(route('login')) }}">{{ __('Sign in') }}</a></p>
@endsection
