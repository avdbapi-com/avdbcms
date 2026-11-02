@extends('auth.layout')

@section('title', __('Sign out'))

{{-- An old page's "Logout" link (a GET) lands here: signing out is a POST with the page's token since 1.2.3 (auth-22),
     so a link or an image on another site cannot sign a member out. --}}
@section('auth-card')
    <div class="mk-auth__intro">
        <h1 class="mk-auth__title" id="authTitle">{{ __('Sign out of :site?', ['site' => config('app.name')]) }}</h1>
        <p class="mk-auth__lead">{{ __('You are signed in as :name.', ['name' => (string) auth()->user()?->username]) }}</p>
    </div>
    <form class="mk-form" method="POST" action="{{ locale_url(route('logout')) }}">
        @csrf
        <button type="submit" class="mk-btn mk-btn--primary mk-btn--block"><i class="fa-solid fa-right-from-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Sign out') }}</span></button>
    </form>
    <p class="mk-auth__switch"><a href="{{ locale_url('/') }}">{{ __('Stay signed in and go home') }}</a></p>
@endsection
