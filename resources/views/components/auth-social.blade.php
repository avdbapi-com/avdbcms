@props(['mode' => 'login'])
@php
    $providers = config('site.allow_social_login', true)
        ? array_filter(\App\Support\OAuthProviders::supported(), fn ($provider) => \App\Support\OAuthProviders::isAvailable($provider))
        : [];
@endphp
@if(count($providers))
    <hr>
    <div class="d-grid gap-2">
        @foreach($providers as $provider)
            <a class="btn btn-outline-secondary d-flex align-items-center justify-content-center gap-2"
               href="{{ locale_url(route('oauth.connect', ['service' => $provider])) }}">
                <i class="fa-brands fa-{{ $provider === 'facebook' ? 'facebook-f' : 'google' }}" aria-hidden="true"></i>
                <span>{{ $mode === 'register'
                    ? ($provider === 'facebook' ? __('Register with Facebook') : __('Register with Google'))
                    : ($provider === 'facebook' ? __('Sign In with Facebook') : __('Sign In with Google')) }}</span>
            </a>
        @endforeach
    </div>
@endif
