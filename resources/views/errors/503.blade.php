@extends('errors.minimal')

{{-- 503 / updating (member design 6.15): "We are updating the site", with the admin's maintenance message when there
     is one (maintenance.blade.php passes it). The page checks again by itself and says exactly how often: every 2
     minutes, or sooner when the answer's Retry-After / the admin's "Tell Google to come back after"
     (maintenance_retry_after) is shorter (at least 30 seconds). That setting is meant for search engines (3600 by
     default): the page used to wait the whole hour while it promised "a few minutes". --}}
@php
    $updatingHeaders = isset($exception) && is_object($exception) && method_exists($exception, 'getHeaders') ? (array) $exception->getHeaders() : [];
    $updatingRetry = (int) ($updatingHeaders['Retry-After'] ?? config('site.maintenance_retry_after', 0));
    // 30 s, 60 s or 120 s: the closest whole step at or below the answer's own wait
    $updatingCheck = $updatingRetry > 0 && $updatingRetry < 60 ? 30 : ($updatingRetry > 0 && $updatingRetry < 120 ? 60 : 120);
    $updatingMessage = trim((string) (isset($maintenanceMessage) && is_string($maintenanceMessage) ? $maintenanceMessage : ''));
    // The setting's own default sentence is replaced by the card's translated one.
    $updatingDefault = \App\Http\Middleware\CheckMaintenanceMode::DEFAULT_MESSAGE;
    $updatingPromise = $updatingCheck < 60
        ? __('This page checks every 30 seconds and shows the site again as soon as it is back.')
        : trans_choice('{1} This page checks every minute and shows the site again as soon as it is back.|[2,*] This page checks every :count minutes and shows the site again as soon as it is back.', intdiv($updatingCheck, 60), ['count' => intdiv($updatingCheck, 60)]);
    $updatingText = $updatingMessage !== '' && $updatingMessage !== $updatingDefault
        ? $updatingMessage.' '.$updatingPromise
        : $updatingPromise;
@endphp
@push('seo-head')
    <meta http-equiv="refresh" content="{{ $updatingCheck }}">
@endpush
@section('title', __('We are updating the site'))
@section('code', '503')
@section('code-line', __('Back soon'))
@section('icon', 'screwdriver-wrench')
@section('message', __('We are updating the site'))
@section('text', $updatingText)
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ locale_current_url() }}" onclick="location.reload(); return false;"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{{ __('Try again') }}</span></a>
@endsection
