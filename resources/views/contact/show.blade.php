@extends('layouts.default')

@section('title', __('Contact'))
@section('description', __('Contact :site support for account, video, billing, or website help.', ['site' => config('app.name')]))

{{-- The contact page. The page keeps its public look (the "Info pages"
     hero and panel, and the picture while "Picture on the contact page" is on: contact_illustration_enabled, loaded only
     where it shows); the form is in the member kit's markup and keeps every setting: the robot check while "Robot check
     on the contact form" is on (captcha_on_contact) and the honeypot, the name and message lengths of Settings > Limits
     (contact_name_min|max, contact_message_min|max: the same numbers the server checks), the visitor's text kept after
     an error or a wait (the form limiter answers with the seconds, never a bare 429). --}}
@php
    $contactIllustrationEnabled = (bool) config('site.contact_illustration_enabled', true);
    $contactNameMin = (int) config('validation.contact.name.min', 3);
    $contactNameMax = (int) config('validation.contact.name.max', 40);
    $contactMessageMin = (int) config('validation.contact.message.min', 10);
    $contactMessageMax = (int) config('validation.contact.message.max', 5000);
    // The robot check's error is shown under its field only while the check is on for this form; otherwise (a filled
    // honeypot) it is listed in the alert with the other messages, so the visitor always reads why nothing was sent.
    $contactFields = app(\App\Services\CaptchaService::class)->enabledFor('contact') ? ['name', 'email', 'message', 'captcha'] : ['name', 'email', 'message'];
    $contactError = session('error');
    $contactError = is_string($contactError) && trim($contactError) !== '' ? trim(strip_tags($contactError)) : null;
    $contactOther = collect($errors->keys())->reject(fn ($key) => in_array($key, $contactFields, true))->map(fn ($key) => $errors->first($key))->filter()->values();
@endphp

@section('content')
    <div class="avd-secondary-page avd-contact-page">
        <div class="avd-secondary-hero">
            <div class="avd-secondary-kicker"><i class="fa-solid fa-headset" aria-hidden="true"></i>{{ __('Support') }}</div>
            <h1 class="avd-secondary-title">{{ __('Contact Us') }}</h1>
            <p class="avd-secondary-subtitle">{{ __('Send account, video, billing, or website questions to the support team.') }}</p>
        </div>
        <div class="avd-secondary-panel avd-contact-panel">
            <div class="row g-0">
                @if($contactIllustrationEnabled)
                    <div class="col-12 col-xl-6 d-none d-xl-flex flex-column px-0 border-end border-gray-200 justify-content-center align-items-center avd-contact-visual">
                        <img class="img-fluid" src="{{ asset('images/pages/contact.png') }}" alt="" width="640" height="640" loading="lazy" decoding="async">
                    </div>
                @endif
                <div @class(['col-12 py-4 py-sm-5 px-3 px-sm-5', 'col-xl-6' => $contactIllustrationEnabled])>
                    <div class="avd-mk mk-contact" x-data x-init="$nextTick(() => { const target = $el.querySelector('[data-contact-focus]') || $el.querySelector('[aria-invalid=true]'); if (target) target.focus(); })">
                        @if(session('success') && is_string(session('success')))
                            <div class="mk-alert mk-alert--success" role="status" tabindex="-1" data-contact-focus>
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                <div class="mk-alert__body"><span>{{ session('success') }}</span></div>
                                <span></span>
                            </div>
                        @endif
                        @if($contactError)
                            <div @class(['mk-alert', 'mk-alert--warning' => session('auth_throttled'), 'mk-alert--danger' => ! session('auth_throttled')]) role="alert" tabindex="-1" data-contact-focus>
                                <i class="fa-solid {{ session('auth_throttled') ? 'fa-hourglass-half' : 'fa-circle-exclamation' }}" aria-hidden="true"></i>
                                <div class="mk-alert__body"><span>{{ $contactError }}</span></div>
                                <span></span>
                            </div>
                        @endif
                        @if($contactOther->isNotEmpty())
                            <div class="mk-alert mk-alert--danger" role="alert" @if(! $contactError) tabindex="-1" data-contact-focus @endif>
                                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                                <div class="mk-alert__body">@foreach($contactOther as $contactMessage)<span>{{ $contactMessage }}</span>@endforeach</div>
                                <span></span>
                            </div>
                        @endif

                        <form class="mk-form" method="POST" action="{{ locale_url(route('contact.contact')) }}" novalidate>
                            @csrf
                            <div class="mk-form__row mk-form__row--2">
                                <div class="mk-field">
                                    <label class="mk-label" for="name">{{ __('Your name') }}</label>
                                    <input class="mk-input" type="text" name="name" id="name" value="{{ old('name') }}"
                                           minlength="{{ $contactNameMin }}" maxlength="{{ $contactNameMax }}" autocomplete="name" required
                                           @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                                    @error('name')
                                        <span class="mk-error" id="name-error">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="mk-field">
                                    <label class="mk-label" for="email">{{ __('Your e-mail') }}</label>
                                    <input class="mk-input" type="email" name="email" id="email" value="{{ old('email') }}"
                                           maxlength="255" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required
                                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                                    @error('email')
                                        <span class="mk-error" id="email-error">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                            <div class="mk-field" x-data="{ count: {{ mb_strlen((string) old('message', '')) }} }">
                                <label class="mk-label" for="message">{{ __('Your message') }}</label>
                                <textarea class="mk-input mk-contact__message" name="message" id="message" rows="8"
                                          minlength="{{ $contactMessageMin }}" maxlength="{{ $contactMessageMax }}" required
                                          aria-describedby="message-help @error('message') message-error @enderror"
                                          @error('message') aria-invalid="true" @enderror
                                          @input="count = $el.value.length">{{ old('message') }}</textarea>
                                <span class="mk-help" id="message-help">{{ trans_choice('{1} At least :count character.|[2,*] At least :count characters.', $contactMessageMin, ['count' => $contactMessageMin]) }} <span class="mk-counter" aria-live="polite"><span x-text="count">{{ mb_strlen((string) old('message', '')) }}</span> / {{ $contactMessageMax }}</span></span>
                                @error('message')
                                    <span class="mk-error" id="message-error">{{ $message }}</span>
                                @enderror
                            </div>
                            <x-captcha action="contact" />
                            <button class="mk-btn mk-btn--primary mk-btn--block" type="submit"><i class="fa-regular fa-paper-plane" aria-hidden="true"></i><span>{{ __('Send message') }}</span></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
