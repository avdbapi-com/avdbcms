@extends('errors.minimal')

{{-- 403 (member design 6.15): "This page is private". The site's own explanation is shown when there is one (for
     example the view-only demo's "This page is not part of the view-only demo...", a policy's refusal), in the page's
     language, never a bare "Forbidden". A payment provider's webhook (Stripe, PayPal) answers servers, not people: its
     refusal ("... signing secret is not set") is for the log, so a person who posts there gets the general sentence.
     An e-mail link that expired or that a mail app cut (a signed link: the e-mail confirmation, a changed address)
     says so and offers a new link, instead of Laravel's "Invalid signature." with no way on. --}}
@php
    $forbiddenSignature = isset($exception) && $exception instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException;
    $forbiddenText = isset($exception) && is_object($exception) && method_exists($exception, 'getMessage') ? trim((string) $exception->getMessage()) : '';
    if (request()->routeIs('stripe_webhook', 'paypal_webhook')
        || in_array($forbiddenText, ['', 'Forbidden', 'This action is unauthorized.', 'Unauthorized'], true)) {
        $forbiddenText = auth()->check()
            ? __('This page belongs to another account, or it is not open to members.')
            : __('Sign in with the account that owns it, or go back to the home page.');
    }
    $forbiddenTranslated = __($forbiddenText);
    $forbiddenText = is_string($forbiddenTranslated) ? $forbiddenTranslated : $forbiddenText;
    // Where a new link comes from: the "Confirm your e-mail" page (its "Send the link again"), or Account settings for
    // a changed address (its "Send the link again" next to the new address).
    $forbiddenNewLink = null;
    if ($forbiddenSignature && auth()->check()) {
        if (request()->routeIs('verification.verify')) {
            $forbiddenNewLink = ['url' => locale_url(route('verification.notice')), 'label' => __('Get a new link'), 'icon' => 'envelope'];
        } elseif (request()->routeIs('verification.verify.update')) {
            $forbiddenNewLink = ['url' => locale_url(route('user.edit')), 'label' => __('Open Account settings'), 'icon' => 'user-gear'];
        }
    }
@endphp
@if($forbiddenSignature)
    @section('title', __('This link does not work any more'))
    @section('code', '403')
    @section('icon', 'link-slash')
    @section('message', __('This link does not work any more'))
    @section('text', __('It is too old, or your e-mail app cut it. Ask for a new link and open the newest one.'))
    @section('actions')
        @if($forbiddenNewLink)
            <a class="mk-btn mk-btn--primary" href="{{ $forbiddenNewLink['url'] }}"><i class="fa-solid fa-{{ $forbiddenNewLink['icon'] }}" aria-hidden="true"></i><span>{{ $forbiddenNewLink['label'] }}</span></a>
            <a class="mk-btn mk-btn--secondary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
        @else
            <a class="mk-btn mk-btn--primary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
            @guest
                <a class="mk-btn mk-btn--secondary" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}"><i class="fa-solid fa-right-to-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Sign in') }}</span></a>
            @endguest
        @endif
    @endsection
@else
    @section('title', __('This page is private'))
    @section('code', '403')
    @section('icon', 'lock')
    @section('message', __('This page is private'))
    @section('text', $forbiddenText)
    @section('actions')
        <a class="mk-btn mk-btn--primary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
        @guest
            <a class="mk-btn mk-btn--secondary" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}"><i class="fa-solid fa-right-to-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Sign in') }}</span></a>
        @endguest
    @endsection
@endif
