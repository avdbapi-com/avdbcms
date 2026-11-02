{{-- Social sign-in (member design 6.12, auth-29): only the providers the admin set up (allow_social_login + keys), as
     secondary buttons with the provider's own mark, "Continue with Google". None set up: no "or" line, no buttons.
     <x-auth-social /> --}}
@props(['mode' => 'login'])
@php
    $providers = config('site.allow_social_login', true)
        ? array_values(array_filter(\App\Support\OAuthProviders::supported(), fn ($provider) => \App\Support\OAuthProviders::isAvailable($provider)))
        : [];
@endphp
@if(count($providers))
    <div class="mk-divider-text">{{ __('or') }}</div>
    <div class="mk-social">
        @foreach($providers as $provider)
            <a class="mk-btn mk-btn--secondary mk-btn--block" href="{{ locale_url(route('oauth.connect', ['service' => $provider])) }}">
                @if($provider === 'google')
                    <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                    <span>{{ __('Continue with :provider', ['provider' => 'Google']) }}</span>
                @elseif($provider === 'facebook')
                    <i class="fa-brands fa-facebook mk-social__mark mk-social__mark--facebook" aria-hidden="true"></i>
                    <span>{{ __('Continue with :provider', ['provider' => 'Facebook']) }}</span>
                @else
                    <i class="fa-brands fa-{{ $provider }}" aria-hidden="true"></i>
                    <span>{{ __('Continue with :provider', ['provider' => ucfirst($provider)]) }}</span>
                @endif
            </a>
        @endforeach
    </div>
@endif
