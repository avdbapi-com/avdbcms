@extends('errors.minimal')

{{-- 410 (member design 6.15, auth-33): a suspended member's channel, or another page that is gone for good. --}}
@php
    $goneChannel = request()->routeIs('user.show');
    $goneTitle = $goneChannel ? __('This channel is no longer available') : __('This page is no longer available');
@endphp
@section('title', $goneTitle)
@section('code', '410')
@section('icon', $goneChannel ? 'user-slash' : 'box-archive')
@section('message', $goneTitle)
@section('text', $goneChannel ? __('This channel was closed or suspended. Its videos are not shown any more.') : __('It was removed and will not come back.'))
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
    <a class="mk-btn mk-btn--secondary" href="{{ locale_url(route('subscription.discover')) }}"><i class="fa-solid fa-compass" aria-hidden="true"></i><span>{{ __('Discover channels') }}</span></a>
@endsection
