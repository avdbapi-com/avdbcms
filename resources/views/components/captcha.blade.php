{{-- The robot check of a form (member design 6.12, auth-10, auth-30) and the honeypot.
     - The built-in check is a plain field whose LABEL is the question, one sentence: "Security check: what is 6 + 12?"
       (the sum in <bdi>, so it reads left to right in Arabic too), so it is the field's accessible name.
     - Turnstile / reCAPTCHA keep their widgets, under the same label.
     - The honeypot (honeypot_enabled): the hidden "website" field, off-screen, not focusable, aria-hidden, no autofill;
       a filled field is refused on the robot check's key with a sentence that says what to do (ValidatesCaptcha). It
       is printed even when the robot check is off for this form; the refusal is then shown in the form's alert
       (<x-auth-errors captcha="..."> and the contact page), because this component prints no field and no error
       while the check is off.
     <x-captcha action="login" /> (login, register, password_reset, contact) --}}
@props(['action'])

@php
    $captcha = app(\App\Services\CaptchaService::class);
    $provider = $captcha->provider();
    $enabled = $captcha->enabledFor((string) $action);
    $captchaError = $errors->first('captcha');
@endphp

@if($enabled)
    @if($provider === \App\Services\CaptchaService::PROVIDER_BUILT_IN)
        @php($challenge = $captcha->makeChallenge((string) $action))
        <div class="mk-field mk-captcha">
            <label class="mk-label" for="captcha_answer">{!! __('Security check: what is :sum?', ['sum' => '<bdi dir="ltr">'.e($challenge['question']).'</bdi>']) !!}</label>
            <input type="hidden" name="captcha_id" value="{{ $challenge['id'] }}">
            <input
                class="mk-input"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                name="captcha_answer"
                id="captcha_answer"
                @if($captchaError) aria-invalid="true" aria-describedby="captcha-error" @endif
                required
            >
            @if($captchaError)
                <span class="mk-error" id="captcha-error">{{ $captchaError }}</span>
            @endif
        </div>
    @else
        <div class="mk-field mk-captcha">
            <span class="mk-label">{{ __('Security check') }}</span>
            @if($provider === \App\Services\CaptchaService::PROVIDER_TURNSTILE)
                @once
                    @push('scripts')
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    @endpush
                @endonce
                <div class="cf-turnstile" data-sitekey="{{ $captcha->siteKey() }}" data-size="compact"></div>
            @elseif($provider === \App\Services\CaptchaService::PROVIDER_RECAPTCHA_V2)
                @once
                    @push('scripts')
                        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                    @endpush
                @endonce
                <div class="g-recaptcha" data-sitekey="{{ $captcha->siteKey() }}" data-size="compact"></div>
            @endif
            @if($captchaError)
                <span class="mk-error" id="captcha-error">{{ $captchaError }}</span>
            @endif
        </div>
    @endif
@endif

@if(config('site.honeypot_enabled', true))
    <div class="mk-hp" aria-hidden="true">
        <label>{{ __('Website') }} <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
    </div>
@endif
