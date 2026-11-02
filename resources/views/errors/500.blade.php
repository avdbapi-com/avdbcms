@extends('errors.minimal')

{{-- 500 (member design 6.15): "Something went wrong on our side": try again, or go home. --}}
@section('title', __('Something went wrong on our side'))
@section('code', '500')
@section('icon', 'triangle-exclamation')
@section('message', __('Something went wrong on our side'))
@section('text', __('We could not finish this. Try again in a moment; if it keeps happening, tell us.'))
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ locale_current_url() }}" onclick="location.reload(); return false;"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{{ __('Try again') }}</span></a>
    <a class="mk-btn mk-btn--secondary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
@endsection
