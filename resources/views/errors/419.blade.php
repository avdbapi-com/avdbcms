@extends('errors.minimal')

{{-- 419 (member design 6.15, auth-17): the form's page was open too long (or the session ended): go back to it and
     send it again, or sign in again. "Go back" without JavaScript: the page the form was on; a form that sends to its
     own address (sign in, sign up, contact) was on this address, so its page is this address, not the home page
     (judge R2A-050). --}}
@section('title', __('This page timed out'))
@section('code', '419')
@section('icon', 'hourglass-end')
@section('message', __('This page timed out'))
@section('text', __('The page was open for a long time, so its form expired to keep your account safe. Go back, then send it again.'))
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ url()->previous() !== url()->current() ? locale_url(url()->previous()) : locale_url(request()->isMethodSafe() ? '/' : url()->current()) }}" onclick="if (history.length > 1) { history.back(); return false; }"><i class="fa-solid fa-arrow-left mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Go back') }}</span></a>
    @guest
        <a class="mk-btn mk-btn--secondary" href="{{ locale_url(route('login')) }}"><i class="fa-solid fa-right-to-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Sign in') }}</span></a>
    @else
        <a class="mk-btn mk-btn--secondary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
    @endguest
@endsection
