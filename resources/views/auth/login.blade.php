@extends('auth.layout')

@section('title', __('Sign in'))

@section('auth-card')
    <div class="mk-auth__intro" x-data x-init="$nextTick(() => { const card = $el.closest('.mk-auth__card'); const target = card && (card.querySelector('[data-auth-focus]') || card.querySelector('[aria-invalid=true]')); if (target) target.focus(); })">
        <h1 class="mk-auth__title" id="authTitle">{{ __('Sign in') }}</h1>
        <p class="mk-auth__lead">{{ __('Welcome back. Sign in with your username or e-mail.') }}</p>
    </div>

    @if(session('success') && is_string(session('success')))
        <x-mk.alert type="success">{{ session('success') }}</x-mk.alert>
    @endif
    <x-auth-errors :fields="['username', 'password', 'captcha']" captcha="login" />

    <form class="mk-form" method="POST" action="{{ locale_url(route('login.perform')) }}" novalidate>
        @csrf
        <div class="mk-field">
            <label class="mk-label" for="username">{{ __('Username or e-mail') }}</label>
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
                maxlength="255"
                required
                @error('username') aria-invalid="true" aria-describedby="username-error" @enderror
            >
            @error('username')
                <span class="mk-error" id="username-error">{{ $message }}</span>
            @enderror
        </div>

        <x-auth-password name="password" :label="__('Password')" autocomplete="current-password">
            <x-slot:aside><a class="mk-label__aside mk-auth__forgot" href="{{ locale_url(route('password.forgot')) }}">{{ __('Forgot password?') }}</a></x-slot:aside>
        </x-auth-password>

        <x-captcha action="login" />

        <label class="mk-option">
            <input class="mk-check" type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span class="mk-option__text"><span>{{ __('Keep me signed in') }}</span></span>
        </label>

        <button type="submit" class="mk-btn mk-btn--primary mk-btn--block"><span>{{ __('Sign in') }}</span></button>
    </form>

    <x-auth-social mode="login" />

    @if(config('site.registration_enabled', true))
        <p class="mk-auth__switch">{{ __('New here?') }} <a href="{{ locale_url(route('registration')) }}">{{ __('Create an account') }}</a></p>
    @endif
@endsection
