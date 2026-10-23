@props(['action'])

@php
    $captcha = app(\App\Services\CaptchaService::class);
    $provider = $captcha->provider();
    $enabled = $captcha->enabledFor((string) $action);
@endphp

@if(config('site.honeypot_enabled', true))
    <input
        type="text"
        name="website"
        value=""
        tabindex="-1"
        autocomplete="off"
        class="d-none"
        aria-hidden="true"
    >
@endif

@if($enabled)
    <div class="mb-3">
        @if($provider === \App\Services\CaptchaService::PROVIDER_BUILT_IN)
            @php($challenge = $captcha->makeChallenge((string) $action))
            <label class="form-label fw-bold" for="captcha_answer">{{ __('Security check') }}</label>
            <div class="input-group">
                <span class="input-group-text">{{ $challenge['question'] }} =</span>
                <input
                    type="hidden"
                    name="captcha_id"
                    value="{{ $challenge['id'] }}"
                >
                <input
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    @class(['form-control', 'is-invalid' => $errors->has('captcha')])
                    name="captcha_answer"
                    id="captcha_answer"
                    required
                >
            </div>
        @elseif($provider === \App\Services\CaptchaService::PROVIDER_TURNSTILE)
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

        @error('captcha')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
@endif
