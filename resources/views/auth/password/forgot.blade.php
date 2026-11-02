@extends('auth.layout')

@section('title', __('Reset your password'))

@section('auth-card')
    <div class="mk-auth__intro" x-data x-init="$nextTick(() => { const card = $el.closest('.mk-auth__card'); const target = card && (card.querySelector('[data-auth-focus]') || card.querySelector('[aria-invalid=true]')); if (target) target.focus(); })">
        <h1 class="mk-auth__title" id="authTitle">{{ __('Reset your password') }}</h1>
        <p class="mk-auth__lead">{{ __('Enter the e-mail of your account. We send you a link to choose a new password.') }}</p>
    </div>

    {{-- The same answer for every address (auth-21): it never tells whether an account uses it. --}}
    @if(session('reset_link_sent') && is_string(session('status')))
        <div class="mk-alert mk-alert--success" role="status" tabindex="-1" data-auth-focus>
            <i class="fa-solid fa-envelope" aria-hidden="true"></i>
            <div class="mk-alert__body">
                <span class="mk-alert__title">{{ __('Check your e-mail') }}</span>
                <span>{{ session('status') }} {{ __('Check your spam folder too.') }}</span>
            </div>
            <span></span>
        </div>
    @endif
    <x-auth-errors :fields="['email', 'captcha']" captcha="password_reset" />

    <form class="mk-form" method="POST" action="{{ locale_url(route('password.email')) }}" novalidate>
        @csrf
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

        <x-captcha action="password_reset" />

        <button type="submit" class="mk-btn mk-btn--primary mk-btn--block"><span>{{ __('Send reset link') }}</span></button>
    </form>

    <p class="mk-auth__switch"><a href="{{ locale_url(route('login')) }}"><i class="fa-solid fa-arrow-left mk-flip-rtl" aria-hidden="true"></i> {{ __('Back to sign in') }}</a></p>
@endsection
