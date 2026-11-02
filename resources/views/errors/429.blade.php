@extends('errors.minimal')

{{-- 429 (member design 6.15, auth-17): "Wait a minute", with the seconds of the Retry-After header. A longer wait (the
     limit of "Download my data" is ten minutes) is said in minutes under "Too many tries" (it read "Wait 594 seconds"
     under "Wait a minute"). "Try again" keeps the page's language without JavaScript too (its address lost the /fr). --}}
@php
    $retryHeaders = isset($exception) && is_object($exception) && method_exists($exception, 'getHeaders') ? (array) $exception->getHeaders() : [];
    $retrySeconds = max(0, (int) ($retryHeaders['Retry-After'] ?? $retryHeaders['retry-after'] ?? 0));
    $retryMinutes = $retrySeconds > 60 ? (int) ceil($retrySeconds / 60) : 0;
    $retryTitle = $retryMinutes > 0 ? __('Too many tries') : __('Wait a minute');
    $retryText = match (true) {
        $retryMinutes > 0 => trans_choice('{1} You did this many times in a short time. Wait :count minute, then try again.|[2,*] You did this many times in a short time. Wait :count minutes, then try again.', $retryMinutes, ['count' => $retryMinutes]),
        $retrySeconds > 0 => trans_choice('{1} Too many tries in a short time. Wait :seconds second, then try again.|[2,*] Too many tries in a short time. Wait :seconds seconds, then try again.', $retrySeconds, ['seconds' => $retrySeconds]),
        default => __('Too many tries in a short time. Wait a minute, then try again.'),
    };
@endphp
@section('title', $retryTitle)
@section('code', '429')
@section('icon', 'hourglass-half')
@section('message', $retryTitle)
@section('text', $retryText)
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ locale_current_url() }}" onclick="location.reload(); return false;"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{{ __('Try again') }}</span></a>
    <a class="mk-btn mk-btn--secondary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
@endsection
