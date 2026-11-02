@extends('auth.layout')

@section('title', __('Confirm your e-mail'))

@php
    $verifyUser = auth()->user();
    $verifyNext = session()->get('verification.send|'.$verifyUser->id.':next');
    $verifyWait = $verifyNext instanceof \DateTimeInterface && \Illuminate\Support\Carbon::instance($verifyNext)->isFuture()
        ? (int) ceil(now()->diffInSeconds(\Illuminate\Support\Carbon::instance($verifyNext), true))
        : 0;
    $verifyMinutes = max(1, (int) config('auth.verification.expire', 60));
@endphp

@section('auth-card')
    <div class="mk-auth__intro" x-data x-init="$nextTick(() => { const card = $el.closest('.mk-auth__card'); const target = card && card.querySelector('[data-auth-focus]'); if (target) target.focus(); })">
        <h1 class="mk-auth__title" id="authTitle">{{ __('Confirm your e-mail') }}</h1>
        <p class="mk-auth__lead">{{ __('One last step.') }}</p>
    </div>

    <div class="mk-empty mk-empty--compact mk-auth__verify">
        <span class="mk-empty__icon" aria-hidden="true"><i class="fa-regular fa-envelope"></i></span>
        {{-- What waits for the confirmation is what the "verified" check guards (routes: subscribe, like / dislike,
             report, comments, uploads, new playlists, the Premium checkout); it said "upload or comment" only. --}}
        <p class="mk-empty__text">{!! __('We sent a link to :email. Open it to confirm your e-mail.', ['email' => '<strong>'.e((string) $verifyUser->email).'</strong>']) !!} {{ __('Until then you can watch videos. Likes, comments, subscriptions, reports, uploads, new playlists and Premium wait until your e-mail is confirmed.') }}</p>
    </div>

    {{-- What the last "Send the link again" did (auth-20): sent, or why it was not. --}}
    @if(session('status') && is_string(session('status')))
        <div class="mk-alert mk-alert--success" role="status" tabindex="-1" data-auth-focus>
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <div class="mk-alert__body"><span>{{ session('status') }}</span></div>
            <span></span>
        </div>
    @endif
    @if(session('error') && is_string(session('error')))
        <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" data-auth-focus>
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div class="mk-alert__body"><span>{{ strip_tags(session('error')) }}</span></div>
            <span></span>
        </div>
    @endif

    <form method="POST" action="{{ locale_url(route('verification.send')) }}"
          x-data="{ wait: {{ $verifyWait }}, label(s) { return @js(__('Send the link again in :time')).replace(':time', Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')); } }"
          x-init="if (wait > 0) { const t = setInterval(() => { wait = Math.max(0, wait - 1); if (wait === 0) clearInterval(t); }, 1000); }">
        @csrf
        <button type="submit" class="mk-btn mk-btn--primary mk-btn--block" @if($verifyWait > 0) disabled @endif :disabled="wait > 0" aria-live="polite">
            <span x-text="wait > 0 ? label(wait) : @js(__('Send the link again'))">{{ $verifyWait > 0 ? __('Send the link again in :time', ['time' => intdiv($verifyWait, 60).':'.str_pad((string) ($verifyWait % 60), 2, '0', STR_PAD_LEFT)]) : __('Send the link again') }}</span>
        </button>
    </form>

    {{-- Two lines, two targets: "Sign out" on its own line, so its 44px target on a touch screen never reaches the text of
         the link above (on one wrapped line it covered "Account": a tap there signed the member out). --}}
    <p class="mk-auth__switch">
        {{ __('Wrong address?') }} <a href="{{ locale_url(route('user.edit')) }}">{{ __('Change it in Account settings') }}</a>
    </p>
    <p class="mk-auth__switch">
        <button type="submit" form="verify-sign-out" class="mk-auth__link-btn">{{ __('Sign out') }}</button>
    </p>
    <form id="verify-sign-out" method="POST" action="{{ locale_url(route('logout')) }}" hidden>@csrf</form>
@endsection
